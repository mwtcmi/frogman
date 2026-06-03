<?php
namespace FreePBX\modules\Frogman\Tools;

abstract class AbstractTool {

	protected $freepbx;
	protected $frogman;

	// Permission levels
	const PERM_READ = 'read';       // List/show/get operations
	const PERM_WRITE = 'write';     // Create/update/delete PBX objects
	const PERM_ADMIN = 'admin';     // Module management, firewall, advanced settings, fwconsole

	public function __construct($freepbx, $frogman) {
		$this->freepbx = $freepbx;
		$this->frogman = $frogman;
	}

	abstract public function name();
	abstract public function description();
	abstract public function validate($params);
	abstract public function execute($params, $context);

	/**
	 * Permission level required: 'read', 'write', or 'admin'.
	 * Override in subclass. Default is 'read'.
	 */
	public function permissionLevel() {
		return self::PERM_READ;
	}

	/**
	 * Legacy method — still used by some tools.
	 * Returns null (no specific FreePBX permission needed beyond the level).
	 */
	public function requiredPermission() {
		return null;
	}

	/**
	 * Run a fwconsole command. Centralized wrapper so any FreePBX-side change to the
	 * binary path or argv shape is patched in one place. Tools should call this rather
	 * than reaching for exec() directly, so we stay within the FreePBX walls.
	 *
	 * @param string|array $args String form is split on whitespace ("ma install tts");
	 *                           array form passes each token as-is.
	 * @param array $opts        'sudo'       prepend sudo (full)
	 *                           'sudo_check' prepend `sudo -n` (non-interactive probe)
	 *                           'tty'        wrap in `script -qc` for subcommands that
	 *                                        require a TTY (e.g. sa info, validate)
	 *                           'no_ansi'    append --no-ansi to args
	 *                           'background' fire-and-forget via nohup; returns
	 *                                        ['output' => '', 'exit_code' => 0] immediately
	 *                           'log_file'   when 'background' is set, redirect to this
	 *                                        file instead of /dev/null (caller can tail it)
	 * @return array             ['output' => string (ANSI stripped, trimmed),
	 *                            'exit_code' => int]
	 */
	protected function runFwconsole($args, array $opts = []) {
		$parts = is_array($args) ? $args : preg_split('/\s+/', trim((string)$args));
		$parts = array_values(array_filter($parts, function($p) { return $p !== ''; }));
		if (!empty($opts['no_ansi'])) {
			$parts[] = '--no-ansi';
		}
		$escaped = implode(' ', array_map('escapeshellarg', $parts));
		$cmd = '/usr/sbin/fwconsole ' . $escaped;
		if (!empty($opts['tty'])) {
			// `script` simulates a TTY for fwconsole subcommands that detect non-TTY
			// and either refuse to run or emit different output.
			$cmd = 'script -qc ' . escapeshellarg($cmd . ' 2>&1') . ' /dev/null';
		} else {
			$cmd .= ' 2>&1';
		}
		if (!empty($opts['sudo_check'])) {
			$cmd = 'sudo -n ' . $cmd;
		} elseif (!empty($opts['sudo'])) {
			$cmd = 'sudo ' . $cmd;
		}
		if (!empty($opts['background'])) {
			$dest = !empty($opts['log_file']) ? escapeshellarg($opts['log_file']) : '/dev/null';
			exec('nohup ' . $cmd . ' > ' . $dest . ' 2>&1 < /dev/null &');
			return ['output' => '', 'exit_code' => 0];
		}
		$output = []; $exitCode = 0;
		exec($cmd, $output, $exitCode);
		$raw = implode("\n", $output);
		$raw = preg_replace('/\x1B\[[0-9;]*[a-zA-Z]/', '', $raw);
		return ['output' => trim($raw), 'exit_code' => $exitCode];
	}

	/**
	 * Check if sudo is available for fwconsole. Returns true when sudoers is wired up
	 * (NOPASSWD for asterisk user) so commands like reload/restart can run elevated.
	 */
	protected function canSudo() {
		$r = $this->runFwconsole('--version', ['sudo_check' => true]);
		return $r['exit_code'] === 0;
	}

	/**
	 * Filter for Asterisk internal channels that aren't real phone calls.
	 * Message/* = SIP MESSAGE / SMS queue. AsyncGoto/* = dialplan jumps.
	 * Both show up in `core show channels` but have blank caller IDs and no human on them.
	 * Used by every tool that counts/lists active channels.
	 */
	protected function isAsteriskInternalChannel($lineOrName) {
		return (bool) preg_match('#^(Message|AsyncGoto)/#i', $lineOrName);
	}

	// Cache for isInternalExtension(). Per-request hashset of every configured
	// extension number. Lazily populated on first lookup; subsequent calls O(1).
	private static $_extensionSet = null;

	/**
	 * True if $number is a configured FreePBX extension.
	 *
	 * Resolves against the canonical users table (same source as
	 * describeDestination's name lookup). Caller IDs from the PSTN happen to be
	 * digit strings too, so a regex test alone is not enough — this is what
	 * fm_get_busiest_extensions needs to stop labeling inbound PSTN numbers as
	 * extensions.
	 */
	protected function isInternalExtension($number) {
		if ($number === null || $number === '') return false;
		$num = (string)$number;
		if (!preg_match('/^\d+$/', $num)) return false;
		$this->ensureExtensionSet();
		return isset(self::$_extensionSet[$num]);
	}

	/**
	 * Return all configured extension numbers as a list of strings.
	 * Shares the same per-request cache as isInternalExtension(). Useful for
	 * building IN-clauses on CDR/CEL/queue_log queries so we filter at the SQL
	 * boundary rather than scanning the full table.
	 */
	protected function getInternalExtensions() {
		$this->ensureExtensionSet();
		return array_keys(self::$_extensionSet);
	}

	/**
	 * Lookup table mapping extension number → display name. Same cache shape as
	 * the extension set, populated alongside it. Returns '' when the extension
	 * is unknown (e.g. agent on a since-deleted extension shows up in queue_log).
	 *
	 * Named lookupExtensionName (not getExtensionName) so it doesn't collide
	 * with TraceCallFlow's private getExtensionName($ext, $db) — PHP refuses
	 * to let a child make an inherited method stricter than the parent.
	 */
	protected function lookupExtensionName($number) {
		$this->ensureExtensionSet();
		return self::$_extensionNames[(string)$number] ?? '';
	}

	private static $_extensionNames = [];

	private function ensureExtensionSet() {
		if (self::$_extensionSet !== null) return;
		self::$_extensionSet = [];
		try {
			$db = $this->freepbx->Database;
			$sth = $db->query("SELECT extension, name FROM users");
			foreach ($sth->fetchAll(\PDO::FETCH_ASSOC) as $row) {
				$ext = (string)$row['extension'];
				self::$_extensionSet[$ext] = true;
				self::$_extensionNames[$ext] = (string)($row['name'] ?? '');
			}
		} catch (\Throwable $e) {
			// On lookup failure, treat as empty rather than spinning per-row retries.
		}
	}

	/**
	 * Collapse multi-leg CDR/CEL rows into one row per logical call.
	 *
	 * Asterisk emits 2–3 rows per real call when Local/...;1 + Local/...;2
	 * fan-out is involved. linkedid ties every leg of one conversation together;
	 * when linkedid is absent (very old rows), fall back to the uniqueid root
	 * (everything before the first dot). Keeps the FIRST row seen for each key,
	 * which for time-ordered queries is the master leg.
	 *
	 * Returns a re-indexed array (numeric keys) so downstream iteration is safe.
	 */
	protected function dedupeByCall(array $rows) {
		$seen = [];
		$out = [];
		foreach ($rows as $r) {
			$key = '';
			if (!empty($r['linkedid'])) {
				$key = (string)$r['linkedid'];
			} elseif (!empty($r['uniqueid'])) {
				$key = (string)$r['uniqueid'];
				$dot = strpos($key, '.');
				if ($dot !== false) $key = substr($key, 0, $dot);
			} else {
				// No identity to dedupe on — preserve the row, can't merge it.
				$out[] = $r;
				continue;
			}
			if (isset($seen[$key])) continue;
			$seen[$key] = true;
			$out[] = $r;
		}
		return $out;
	}

	/**
	 * True if a CDR/CEL row represents a real human call (the kind reporting cares
	 * about). False for paging multicast, lockdown beacons, echo tests, and the
	 * other system-traffic contexts that pollute call-volume metrics.
	 *
	 * Checks across the column names CDR and CEL both use, so the same helper
	 * works for either source without the caller adapting. Pass extra patterns
	 * via $denyContexts to layer in deployment-specific noise.
	 *
	 * Patterns are regex fragments (no anchors needed — added here).
	 */
	/**
	 * Apply the locked default reporting window when neither date_from nor
	 * date_to is supplied: today 00:00 → now. Used by every CEL/queuelog
	 * reporting tool so unbounded queries can't accidentally scan a
	 * year-of-rows table. Callers who pass even one bound opt out — they know
	 * what they want.
	 */
	protected function applyDefaultReportWindow(array $params) {
		if (empty($params['date_from']) && empty($params['date_to'])) {
			$params['date_from'] = date('Y-m-d 00:00:00');
			$params['date_to']   = date('Y-m-d H:i:s');
		}
		return $params;
	}

	/**
	 * True if a value looks like a real Asterisk linkedid: "<digits>.<digits>".
	 * Used by chat formatters before embedding linkedid inside a {{cmd:...}}
	 * chip — sanitizeForChat() strips backticks/{{/[ but does not neutralize
	 * the chip's own }} and | delimiters, so an unvalidated value could break
	 * out of the chip template. Defense in depth; current Asterisk source
	 * always emits this format.
	 */
	protected function isSafeLinkedid($v) {
		return is_string($v) && preg_match('/^\d+\.\d+$/', $v) === 1;
	}

	protected function isRealCall(array $row, array $denyContexts = []) {
		static $defaults = [
			'context_re' => [
				'^fm-lockdown-',
				'^app-echo-test$',
				'^app-blackhole$',
			],
			'dst_re' => [
				'^\*43$',  // echo test feature code
			],
			'channel_re' => [
				'^MulticastRTP/',
				'^Message/',
			],
		];
		$ctx = (string)($row['dcontext'] ?? $row['context'] ?? '');
		$dst = (string)($row['dst'] ?? $row['exten'] ?? '');
		$ch  = (string)($row['channel']  ?? $row['channame'] ?? '');
		$dch = (string)($row['dstchannel'] ?? $row['peer'] ?? '');
		foreach ($defaults['context_re'] as $re) {
			if (preg_match('#' . $re . '#i', $ctx)) return false;
		}
		foreach ($defaults['dst_re'] as $re) {
			if (preg_match('#' . $re . '#i', $dst)) return false;
		}
		foreach ($defaults['channel_re'] as $re) {
			if ($ch  !== '' && preg_match('#' . $re . '#i', $ch))  return false;
			if ($dch !== '' && preg_match('#' . $re . '#i', $dch)) return false;
		}
		foreach ($denyContexts as $re) {
			if (preg_match('#' . $re . '#i', $ctx)) return false;
		}
		return true;
	}

	/**
	 * Cryptographically-secure shuffle (Fisher-Yates with random_int).
	 *
	 * PHP's str_shuffle() uses mt_rand internally and is NOT cryptographically
	 * secure — using it on a password assembled from random_int picks partially
	 * undoes the entropy guarantee. Used by password generators.
	 */
	protected static function secureShuffle($s) {
		$chars = str_split((string)$s);
		$n = count($chars);
		for ($i = $n - 1; $i > 0; $i--) {
			$j = random_int(0, $i);
			if ($i !== $j) {
				$tmp = $chars[$i];
				$chars[$i] = $chars[$j];
				$chars[$j] = $tmp;
			}
		}
		return implode('', $chars);
	}

	/**
	 * Describe a FreePBX dialplan destination string ("ivr-8,s,1") with a structured
	 * label for human display. Returns ['type', 'label', 'key'] — type is the destination
	 * category (extension/ringgroup/queue/ivr/etc.), label is the display string,
	 * key is a stable dedup key (type-prefixed).
	 *
	 * Note: this is the OPPOSITE direction from AddInboundRoute::resolveDestination(),
	 * which parses friendly shorthand ("rg 600") INTO a dialplan string. Keep the
	 * verb difference (describe vs resolve) to avoid the collision.
	 */
	protected function describeDestination($dest, $db = null) {
		if ($db === null) $db = $this->freepbx->Database;
		if (empty($dest)) return ['type' => 'unknown', 'label' => '⚠️ Not Configured', 'key' => 'noconfig'];
		$parts = explode(',', $dest);
		$context = $parts[0] ?? '';
		$exten = $parts[1] ?? '';

		if (strpos($context, 'from-did-direct') !== false || strpos($context, 'ext-local') !== false) {
			$sth = $db->prepare("SELECT name FROM users WHERE extension = ?");
			$sth->execute([$exten]);
			$name = $sth->fetchColumn() ?: '';
			return ['type' => 'extension', 'label' => $name ? "{$exten} ({$name})" : "Ext {$exten}", 'key' => "ext:{$exten}"];
		}
		if (strpos($context, 'ext-group') !== false) {
			$sth = $db->prepare("SELECT description FROM ringgroups WHERE grpnum = ?");
			$sth->execute([$exten]);
			$name = $sth->fetchColumn() ?: '';
			return ['type' => 'ringgroup', 'label' => $name ? "Ring Group {$exten}: {$name}" : "Ring Group {$exten}", 'key' => "rg:{$exten}"];
		}
		if (strpos($context, 'ext-queues') !== false) {
			// queues_config has a direct `descr` column per row (matches the pattern used
			// by SearchPbx). Previous query used `queues_config` with a `keyword='descr'`
			// filter — that's the schema of queues_details, not queues_config — so the
			// filter matched nothing and every queue rendered as the unnamed "Queue N" label.
			$sth = $db->prepare("SELECT descr FROM queues_config WHERE extension = ? LIMIT 1");
			$sth->execute([$exten]);
			$name = $sth->fetchColumn() ?: '';
			return ['type' => 'queue', 'label' => $name ? "Queue {$exten}: {$name}" : "Queue {$exten}", 'key' => "q:{$exten}"];
		}
		if (strpos($context, 'ivr-') !== false) {
			$id = preg_replace('/[^0-9]/', '', $context);
			$sth = $db->prepare("SELECT name FROM ivr_details WHERE id = ?");
			$sth->execute([$id]);
			$name = $sth->fetchColumn() ?: '';
			return ['type' => 'ivr', 'label' => $name ? "IVR: {$name}" : "IVR {$id}", 'key' => "ivr:{$id}"];
		}
		if (strpos($context, 'timeconditions') !== false) {
			$id = preg_replace('/[^0-9]/', '', $exten);
			$sth = $db->prepare("SELECT displayname FROM timeconditions WHERE timeconditions_id = ?");
			$sth->execute([$id]);
			$name = $sth->fetchColumn() ?: '';
			return ['type' => 'timecondition', 'label' => $name ? "Time: {$name}" : "Time Cond {$id}", 'key' => "tc:{$id}"];
		}
		if (strpos($dest, 'vmu') !== false || strpos($context, 'app-vmmain') !== false) {
			$ext = preg_replace('/[^0-9]/', '', $exten);
			return ['type' => 'voicemail', 'label' => "Voicemail {$ext}", 'key' => "vm:{$ext}"];
		}
		if (strpos($context, 'app-announcement') !== false) {
			$sth = $db->prepare("SELECT description FROM announcement WHERE announcement_id = ?");
			$sth->execute([$exten]);
			$name = $sth->fetchColumn() ?: '';
			return ['type' => 'announcement', 'label' => $name ? "Announcement: {$name}" : "Announcement {$exten}", 'key' => "ann:{$exten}"];
		}
		if (strpos($context, 'app-blackhole') !== false) {
			$labels = ['hangup'=>'Hangup', 'congestion'=>'Congestion', 'busy'=>'Busy', 'zapateller'=>'Zapateller', 'musiconhold'=>'Music on Hold', 'ring'=>'Ring Forever'];
			return ['type' => 'terminate', 'label' => $labels[$exten] ?? "Terminate: {$exten}", 'key' => "term:{$exten}"];
		}
		return ['type' => 'unknown', 'label' => trim($dest), 'key' => 'raw:' . md5($dest)];
	}

	/**
	 * Run a fwconsole command as root. Thin wrapper over runFwconsole() that adds the
	 * dry-run gate and the sudoers preflight. Existing callers (Reload, Restart, etc.)
	 * use the dry-run-returns-null contract; preserve it.
	 */
	protected function runAsRoot($cmd, $confirm = true) {
		if (!$confirm) {
			return null;
		}
		if (!$this->canSudo()) {
			return [
				'needs_root' => true,
				'message' => "This command requires root access.",
			];
		}
		return $this->runFwconsole($cmd, ['sudo' => true]);
	}
}
