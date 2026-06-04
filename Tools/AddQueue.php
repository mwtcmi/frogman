<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

// Routing surface for queue writes:
//   1. $freepbx->Queues->X(): only read methods (listQueues, getQueuesDetails,
//      getDynMembersOfQueue, parseQueueVal). No facade for write.
//   2. REST API /queues: GET endpoints only; PUT exists only for /members/{id}.
//      No POST for queue create, no DELETE for queue removal.
//   3. queues_add() / queues_del() in queues/functions.inc/geters_seters.php:
//      what FreePBX itself uses — Queues.class.php doConfigPageInit calls them
//      directly on form submit. Edit path is "del + re-add" (same as Core's
//      editUser/editDevice pattern).
//   4. Direct DB writes: forbidden by Frogman Hard Rule 3.
// We're on rung 3 because rungs 1 and 2 don't exist. If queues_add changes
// shape, the GUI form handler breaks identically, so this is de facto in-walls.
// Reads stay on the BMO facade.
//
// queues_add() reads many config fields directly from $_REQUEST, so applyRequest()
// below pre-populates them with sensible defaults; caller restores prior $_REQUEST.
class AddQueue extends AbstractTool {
	public function name() { return 'fm_add_queue'; }
	public function description() { return 'Create a call queue. Params: account (required, queue number, digits), name (required, queue description), strategy (optional, one of ringall|leastrecent|fewestcalls|random|rrmemory|linear|wrandom, default ringall), timeout (optional seconds per member ring, default 15), retry (optional seconds between attempts, default 5), maxwait (optional max queue wait seconds, 0 unlimited, default 0), fail_destination (optional dialplan goto when timed out / no agents, e.g. "from-did-direct,1001,1"), mohclass (optional, default "default"), members (optional, array of extension numbers OR objects {ext, penalty} OR "ext:penalty" strings; resolved to Local/<ext>@from-queue/n,<penalty>), password (optional PIN), prefix (optional CID prefix), alertinfo (optional SIP Alert-Info), wrapuptime (optional, default 0), weight (optional, default 0), joinempty (optional yes|no, default yes), leavewhenempty (optional yes|no, default no), announce_position (optional yes|no, default no), announce_holdtime (optional yes|no, default no), recording (optional one of dontcare|yes|no|force|never|always|onlyincoming, default dontcare). Requires confirm:true.'; }

	const VALID_STRATEGIES = ['ringall','leastrecent','fewestcalls','random','rrmemory','linear','wrandom'];
	const VALID_RECORDING = ['dontcare','yes','no','force','never','always','onlyincoming'];

	public function validate($params) {
		$err = self::validateCore($params, false);
		return $err === '' ? true : $err;
	}

	// Field validation shared with UpdateQueue (which makes every field optional).
	// $partial = true skips account/name required checks.
	public static function validateCore(array $params, $partial) {
		if (!$partial) {
			if (empty($params['account'])) return 'Parameter "account" is required (queue number)';
			if (empty($params['name'])) return 'Parameter "name" is required';
		}
		if (isset($params['account']) && !preg_match('/^\d{1,11}$/', (string)$params['account'])) return 'Parameter "account" must be 1-11 digits';
		if (isset($params['name']) && !preg_match('/^[a-zA-Z0-9_\-\s.]{1,80}$/', (string)$params['name'])) return 'Parameter "name" must be 1-80 chars, [a-zA-Z0-9_\-\s.] only';
		if (isset($params['strategy']) && !in_array($params['strategy'], self::VALID_STRATEGIES, true)) return 'Parameter "strategy" must be one of: ' . implode('|', self::VALID_STRATEGIES);
		foreach (['timeout','retry','maxwait','wrapuptime','weight'] as $f) {
			if (isset($params[$f]) && !preg_match('/^\d+$/', (string)$params[$f])) return "Parameter \"{$f}\" must be a non-negative integer";
		}
		if (!empty($params['password']) && !preg_match('/^\d{1,15}$/', (string)$params['password'])) return 'Parameter "password" must be 1-15 digits';
		foreach (['joinempty','leavewhenempty','announce_position','announce_holdtime'] as $f) {
			if (isset($params[$f]) && !in_array($params[$f], ['yes','no'], true)) return "Parameter \"{$f}\" must be 'yes' or 'no'";
		}
		if (isset($params['recording']) && !in_array($params['recording'], self::VALID_RECORDING, true)) return 'Parameter "recording" must be one of: ' . implode('|', self::VALID_RECORDING);
		// Control-char reject for fields that flow into the generated
		// extensions_additional.conf or SIP headers. Defense in depth — FreePBX
		// has its own conf sanitization layer, but the upstream guard is cheap.
		foreach (['prefix','alertinfo','fail_destination','mohclass'] as $f) {
			if (isset($params[$f]) && preg_match('/[\r\n\0]/', (string)$params[$f])) return "Parameter \"{$f}\" contains disallowed control characters";
		}
		if (isset($params['members'])) {
			if (!is_array($params['members'])) return 'Parameter "members" must be an array';
			foreach ($params['members'] as $i => $m) {
				$err = '';
				if (self::normalizeMember($m, $err) === null) return "members[{$i}]: {$err}";
			}
		}
		return '';
	}

	public function requiredPermission() { return null; }
	public function permissionLevel() { return self::PERM_WRITE; }

	// Resolve a member spec into the "Local/EXT@from-queue/n,PENALTY" string
	// queues_details expects. Accepts:
	//   - "101"              → Local/101@from-queue/n,0
	//   - "101:1"            → Local/101@from-queue/n,1
	//   - ["ext"=>101]       → Local/101@from-queue/n,0
	//   - ["ext"=>101,"penalty"=>2] → Local/101@from-queue/n,2
	//   - "PJSIP/101,0"      → passed through (raw form trusted after control-char check)
	// Returns null on validation failure with $err populated. Static so the
	// member CRUD tools can reuse.
	public static function normalizeMember($spec, &$err) {
		$err = '';
		$ext = ''; $pen = 0;
		if (is_array($spec)) {
			$ext = (string)($spec['ext'] ?? '');
			$pen = (int)($spec['penalty'] ?? 0);
		} else {
			$s = (string)$spec;
			if (preg_match('#^(Local|PJSIP|SIP|IAX2|Agent|ZAP|DAHDI)/#', $s)) {
				if (preg_match('/[\r\n\0;]/', $s)) { $err = 'control chars not allowed in member string'; return null; }
				return $s;
			}
			if (strpos($s, ':') !== false) {
				[$ext, $pen] = explode(':', $s, 2);
			} elseif (strpos($s, ',') !== false) {
				[$ext, $pen] = explode(',', $s, 2);
			} else {
				$ext = $s;
			}
			$pen = (int)$pen;
		}
		$ext = trim((string)$ext);
		if (!preg_match('/^\d{1,11}$/', $ext)) { $err = "extension \"{$ext}\" must be 1-11 digits"; return null; }
		if ($pen < 0 || $pen > 99) { $err = "penalty \"{$pen}\" must be 0-99"; return null; }
		return "Local/{$ext}@from-queue/n,{$pen}";
	}

	// Extract the extension number from a stored member string for chat-summary
	// rendering. Returns "" if the shape is unrecognized.
	public static function extractMemberExt($memberStr) {
		if (preg_match('#^[A-Za-z]+/(\d+)#', (string)$memberStr, $m)) return $m[1];
		return '';
	}

	// Pre-populate $_REQUEST with the fields queues_add() reads directly.
	// Returns the prior $_REQUEST so the caller can restore it after the call.
	// Static so UpdateQueue + member tools can reuse without subclassing.
	public static function applyRequest(array $params) {
		$prior = $_REQUEST;
		$_REQUEST['action'] = $params['_action'] ?? 'add';
		$_REQUEST['strategy'] = $params['strategy'] ?? 'ringall';
		$_REQUEST['timeout'] = isset($params['timeout']) ? (string)(int)$params['timeout'] : '15';
		$_REQUEST['retry'] = isset($params['retry']) ? (string)(int)$params['retry'] : '5';
		$_REQUEST['wrapuptime'] = isset($params['wrapuptime']) ? (string)(int)$params['wrapuptime'] : '0';
		$_REQUEST['weight'] = isset($params['weight']) ? (string)(int)$params['weight'] : '0';
		$_REQUEST['maxlen'] = (string)(int)($params['maxlen'] ?? 0);
		$_REQUEST['joinempty'] = $params['joinempty'] ?? 'yes';
		$_REQUEST['leavewhenempty'] = $params['leavewhenempty'] ?? 'no';
		$_REQUEST['announceposition'] = $params['announce_position'] ?? 'no';
		$_REQUEST['announceholdtime'] = $params['announce_holdtime'] ?? 'no';
		$_REQUEST['announcefreq'] = '0';
		$_REQUEST['min-announce'] = '15';
		$_REQUEST['pannouncefreq'] = '0';
		$_REQUEST['recording'] = $params['recording'] ?? 'dontcare';
		$_REQUEST['autofill'] = $params['autofill'] ?? 'yes';
		$_REQUEST['reportholdtime'] = 'no';
		$_REQUEST['autopause'] = 'no';
		$_REQUEST['autopausedelay'] = '0';
		$_REQUEST['servicelevel'] = '60';
		$_REQUEST['memberdelay'] = '0';
		$_REQUEST['timeoutrestart'] = 'no';
		$_REQUEST['skip_joinannounce'] = '';
		$_REQUEST['answered_elsewhere'] = '0';
		$_REQUEST['timeoutpriority'] = 'app';
		$_REQUEST['penaltymemberslimit'] = '0';
		$_REQUEST['rvolume'] = '';
		$_REQUEST['rvol_mode'] = '';
		$_REQUEST['autopausebusy'] = 'no';
		$_REQUEST['autopauseunavail'] = 'no';
		$_REQUEST['music'] = $params['mohclass'] ?? 'default';
		$_REQUEST['rtone'] = 0;
		return $prior;
	}

	// Core writer used by Add + Update + member tools. All inputs already
	// validated by the caller. Pre-populates $_REQUEST around queues_add().
	public static function writeQueue($freepbx, array $args) {
		$freepbx->Modules->loadFunctionsInc('queues');
		if (!function_exists('queues_add')) throw new \Exception('queues_add() not available — Queues module not loaded');
		$prior = self::applyRequest($args);
		try {
			queues_add(
				$args['account'],
				$args['name'],
				(string)($args['password'] ?? ''),
				(string)($args['prefix'] ?? ''),
				(string)($args['fail_destination'] ?? ''),
				null,                                        // agentannounce_id
				$args['members'] ?? [],
				null,                                        // joinannounce_id
				isset($args['maxwait']) ? (string)(int)$args['maxwait'] : '0',
				(string)($args['alertinfo'] ?? ''),
				'0',                                         // cwignore
				'',                                          // qregex
				'0',                                         // queuewait
				'0',                                         // use_queue_context
				[],                                          // dynmembers — queues_add() default '' crashes array_unique
				'no',                                        // dynmemberonly
				'0',                                         // togglehint
				'0',                                         // qnoanswer
				'0',                                         // callconfirm
				'',                                          // callconfirm_id
				'',                                          // monitor_type
				'0',                                         // monitor_heard
				'0',                                         // monitor_spoken
				'0'                                          // answered_elsewhere
			);
		} finally {
			$_REQUEST = $prior;
		}
	}

	public function execute($params, $context) {
		$confirm = !empty($params['confirm']) && $params['confirm'] === true;

		$account = (string)$params['account'];
		$name = trim((string)$params['name']);

		$members = [];
		if (!empty($params['members']) && is_array($params['members'])) {
			foreach ($params['members'] as $i => $m) {
				$err = '';
				$norm = self::normalizeMember($m, $err);
				if ($norm === null) return ['error' => "members[{$i}]: {$err}"];
				$members[] = $norm;
			}
		}

		// Conflict check against the full FreePBX object space.
		if (function_exists('framework_check_extension_usage')) {
			$usage = framework_check_extension_usage($account);
			if (!empty($usage)) {
				$accountSan = $this->frogman->sanitizeForChat($account);
				return ['error' => "Queue number `{$accountSan}` conflicts with an existing extension/route/queue/conference. Choose a different number."];
			}
		}

		// DB-based pre-existence check. getQueuesDetails() reads AMI "queue show"
		// which is empty until reload — would miss a queue that exists in DB but
		// hasn't been pushed to Asterisk yet.
		$this->freepbx->Modules->loadFunctionsInc('queues');
		if (function_exists('queues_get') && !empty(queues_get($account))) {
			$accountSan = $this->frogman->sanitizeForChat($account);
			return ['error' => "Queue `{$accountSan}` already exists. Use fm_update_queue to change it, or fm_remove_queue first."];
		}

		$nameSan = $this->frogman->sanitizeForChat($name);
		$accountSan = $this->frogman->sanitizeForChat($account);
		$strategy = $params['strategy'] ?? 'ringall';
		$strategySan = $this->frogman->sanitizeForChat($strategy);
		$timeoutDisp = (string)(int)($params['timeout'] ?? 15);

		if (!$confirm) {
			$frogman = $this->frogman;
			$memberSummary = empty($members) ? '_no initial members_' : count($members) . ' member(s): ' . implode(', ', array_map(function($m) use ($frogman) {
				$ext = self::extractMemberExt($m);
				return $ext !== '' ? '`' . $frogman->sanitizeForChat($ext) . '`' : '`?`';
			}, $members));
			return ['dry_run' => true, 'message' => "Would add queue `{$accountSan}` `{$nameSan}` with strategy `{$strategySan}`, per-member timeout {$timeoutDisp}s.\n• {$memberSummary}\n\nReply yes to confirm.", 'queue' => ['account' => $account, 'name' => $name, 'strategy' => $strategy, 'members' => $members]];
		}

		self::writeQueue($this->freepbx, [
			'account' => $account,
			'name' => $name,
			'password' => (string)($params['password'] ?? ''),
			'prefix' => (string)($params['prefix'] ?? ''),
			'fail_destination' => (string)($params['fail_destination'] ?? ''),
			'members' => $members,
			'maxwait' => $params['maxwait'] ?? 0,
			'alertinfo' => (string)($params['alertinfo'] ?? ''),
			'strategy' => $strategy,
			'timeout' => $params['timeout'] ?? 15,
			'retry' => $params['retry'] ?? 5,
			'wrapuptime' => $params['wrapuptime'] ?? 0,
			'weight' => $params['weight'] ?? 0,
			'joinempty' => $params['joinempty'] ?? 'yes',
			'leavewhenempty' => $params['leavewhenempty'] ?? 'no',
			'announce_position' => $params['announce_position'] ?? 'no',
			'announce_holdtime' => $params['announce_holdtime'] ?? 'no',
			'recording' => $params['recording'] ?? 'dontcare',
			'mohclass' => $params['mohclass'] ?? 'default',
		]);

		return ['dry_run' => false, 'message' => "✅ Queue `{$accountSan}` `{$nameSan}` added (" . count($members) . " member(s), strategy `{$strategySan}`).", 'account' => $account, 'name' => $name, 'needs_reload' => true];
	}
}
