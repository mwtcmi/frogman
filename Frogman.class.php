<?php
namespace FreePBX\modules;

class Frogman extends \FreePBX_Helpers implements \BMO {

	private $freepbx;
	private $db;
	private $tools = [];
	private $toolsLoaded = false;
	// Populated by authenticateRequest. ['user' => string, 'level' => string|null]
	// 'level' is set when the auth source carries an explicit permission level
	// (token's level column, or localhost-trust = admin). null means "resolve via
	// oc_permissions / FreePBX sections from the username".
	private $authContext = null;

	public function __construct($freepbx = null) {
		parent::__construct($freepbx);
		$this->freepbx = $freepbx;
		$this->db = $freepbx->Database;
	}

	public function install() {
	}

	public function uninstall() {
	}

	public function backup() {
	}

	public function restore($backup) {
	}

	public function doConfigPageInit($page) {
	}

	public function getActionBar($request) {
		return [];
	}

	public function ajaxRequest($req, &$setting) {
		switch ($req) {
			case 'tool':
			case 'catalog':
			case 'chat':
				// Allow remote access — we handle auth ourselves (session OR API token)
				$setting['authenticate'] = false;
				$setting['allowremote'] = true;
				return true;
			case 'audit-feed':
				$setting['authenticate'] = true;
				$setting['allowremote'] = true;
				return true;
			case 'download':
				$setting['authenticate'] = true;
				$setting['allowremote'] = false;
				return true;
		}
		return false;
	}

	/**
	 * Authenticate the current request. Stashes the result in $this->authContext
	 * (which runTool reads to enforce permissions) and returns the username for
	 * back-compat with callers that ignored the original return value.
	 *
	 * Order matters: explicit auth (token, session) wins over the localhost
	 * fallback so a localhost caller that bothered to send a token gets the
	 * token's level rather than blanket admin.
	 */
	private function authenticateRequest() {
		// 1. API token via header — explicit identity, carries its own level.
		// If the caller bothered to send a token we treat it as their chosen auth
		// method: a bad/inactive one fails outright rather than silently falling
		// through to localhost trust.
		$token = $_SERVER['HTTP_X_FROGMAN_TOKEN'] ?? '';
		if (!empty($token)) {
			$sth = $this->db->prepare("SELECT username, level FROM oc_api_tokens WHERE token = ? AND active = 1");
			$sth->execute([$token]);
			$row = $sth->fetch(\PDO::FETCH_ASSOC);
			if ($row) {
				$this->authContext = ['user' => $row['username'], 'level' => $row['level']];
				return $this->authContext['user'];
			}
			throw new \Exception('Invalid or inactive API token.');
		}

		// 2. Valid FreePBX session — username is known, level resolves via oc_permissions / sections.
		if (!empty($_SESSION['AMP_user'])) {
			$user = $_SESSION['AMP_user']->username ?? 'session';
			$this->authContext = ['user' => $user, 'level' => null];
			return $user;
		}

		// 3. Localhost fallback — anyone who can reach 127.0.0.1 already has filesystem
		// access to this module, so trust them as admin. Matches FreePBX's own local-Apache model.
		$ip = $_SERVER['REMOTE_ADDR'] ?? '';
		if (in_array($ip, ['127.0.0.1', '::1'])) {
			$this->authContext = ['user' => 'localhost', 'level' => 'admin'];
			return 'localhost';
		}

		throw new \Exception('Not authenticated. Provide X-Frogman-Token header or connect from localhost.');
	}

	public function ajaxHandler() {
		$command = isset($_REQUEST['command']) ? $_REQUEST['command'] : '';
		switch ($command) {
			case 'catalog':
				$this->setCorsHeaders();
				try {
					$this->authenticateRequest();
				} catch (\Exception $e) {
					return ['status' => 'error', 'message' => $e->getMessage()];
				}
				return $this->handleCatalog();
			case 'audit-feed':
				return $this->handleAuditFeed();
			default:
				return ['status' => 'error', 'message' => 'Unknown command'];
		}
	}

	public function ajaxCustomHandler() {
		$command = isset($_REQUEST['command']) ? $_REQUEST['command'] : '';
		switch ($command) {
			case 'tool':
				$this->handleToolRequest();
				return true;
			case 'chat':
				$this->handleChatRequest();
				return true;
			case 'download':
				$this->handleDownload();
				return true;
		}
		return false;
	}

	// ── HTTP Endpoints ─────────────────────────────────────────

	private function setCorsHeaders() {
		header('Access-Control-Allow-Origin: *');
		header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
		header('Access-Control-Allow-Headers: Content-Type, X-Frogman-Token');
		// Handle preflight
		if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
			http_response_code(204);
			exit;
		}
	}

	private function handleToolRequest() {
		$this->setCorsHeaders();
		header('Content-Type: application/json');

		try {
			$this->authenticateRequest();
		} catch (\Exception $e) {
			http_response_code(401);
			echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
			return;
		}

		$rawBody = file_get_contents('php://input');
		$body = json_decode($rawBody, true);

		if (empty($body) || empty($body['tool'])) {
			http_response_code(400);
			echo json_encode([
				'status' => 'error',
				'message' => 'Request body must be JSON with "tool" field.',
			]);
			return;
		}

		$toolName = $body['tool'];
		$params = isset($body['params']) ? $body['params'] : [];
		$userId = null;
		if (isset($_SESSION['AMP_user']) && is_object($_SESSION['AMP_user'])) {
			$userId = null; // ampusers has no numeric ID
		}
		$sessionId = session_id() ?: 'http';

		$result = $this->runTool($toolName, $params, $userId, $sessionId);

		$httpCode = ($result['status'] === 'success') ? 200 : 400;
		if (isset($result['message']) && strpos($result['message'], 'Unknown tool') !== false) {
			$httpCode = 404;
		}

		http_response_code($httpCode);
		echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
	}

	/**
	 * Handle POST /admin/ajax.php?module=frogman&command=chat
	 * Accepts JSON body: {"message": "...", "session_id": "..."}
	 * Parses natural language, executes the matched tool, returns formatted text.
	 */
	private function handleChatRequest() {
		$this->setCorsHeaders();
		header('Content-Type: application/json');

		try {
			$this->authenticateRequest();
		} catch (\Exception $e) {
			http_response_code(401);
			echo json_encode(['reply' => $e->getMessage()]);
			return;
		}

		// Cap chat parsing memory so a parser bug fails fast instead of OOM-killing the worker
		ini_set('memory_limit', '256M');

		$rawBody = file_get_contents('php://input');
		$body = json_decode($rawBody, true);

		if (empty($body) || !isset($body['message'])) {
			http_response_code(400);
			echo json_encode(['reply' => 'Send a JSON body with a "message" field.']);
			return;
		}

		$message = trim($body['message']);
		$sessionId = $body['session_id'] ?? 'chat-default';

		require_once __DIR__ . '/Tools/ChatParser.php';
		$parsed = \FreePBX\modules\Frogman\ChatParser::parse($message, $sessionId);

		// If the parser returned a direct text response (help, error, cancel)
		if (isset($parsed['response'])) {
			echo json_encode(['reply' => $parsed['response']]);
			return;
		}

		// Otherwise it matched a tool
		$toolName = $parsed['tool'];
		$params = $parsed['params'];

		$result = $this->runTool($toolName, $params, null, $sessionId);

		// Format the result as human-readable text
		$reply = $this->formatToolResult($toolName, $result, $sessionId);

		// Offer a follow-up action if applicable
		$followUp = $this->getFollowUpOffer($toolName, $result, $params);
		if ($followUp) {
			require_once __DIR__ . '/Tools/ChatParser.php';
			\FreePBX\modules\Frogman\ChatParser::setFollowUp(
				$sessionId,
				$followUp['tool'],
				$followUp['params'],
				$followUp['needs_input'] ?? null,
				$followUp['input_prompt'] ?? null
			);
			$reply .= "\n\n" . $followUp['question'] . "\n{{cmd:yes|✅ Yes}} {{cmd:no|❌ No}}";
		}

		echo json_encode(['reply' => $reply]);
	}

	/**
	 * Format a tool result into readable chat text.
	 */
	public function formatToolResult($toolName, $result, $sessionId) {
		if ($result['status'] === 'error') {
			$oops = ['Oopsy daisy!', 'Well, that didn\'t work.', 'Hmm, hit a snag.', 'Whoops!', 'That didn\'t go as planned.', 'Uh oh.'];
			$prefix = $oops[array_rand($oops)];
			return "**{$prefix}** " . ($result['message'] ?? 'Unknown error');
		}

		$data = $result['data'] ?? [];

		// If the tool needs root access
		if (!empty($data['needs_root'])) {
			return "🔒 **Root access required**\n\n"
				. "This command needs elevated privileges. To enable, run once as root:\n"
				. "`echo 'asterisk ALL=(root) NOPASSWD: /usr/sbin/fwconsole' > /etc/sudoers.d/frogman`\n\n"
				. "This allows Frogman to manage FreePBX services from the chat console.";
		}

		// If it's a dry-run, store pending and prompt for confirm
		if (!empty($data['dry_run'])) {
			require_once __DIR__ . '/Tools/ChatParser.php';
			// The parser already stored the pending confirm when it parsed
			// Show dialplan preview for dialplan tools
			if (!empty($data['dialplan'])) {
				$ctx = $data['context'] ?? 'custom';
				return "**Preview — " . $ctx . ":**\n```\n" . $data['dialplan'] . "\n```\n{{cmd:yes|✅ Yes}} {{cmd:no|❌ No}}";
			}
			// Recording: show mode chips alongside Yes/No so user can swap mode in one click.
			if ($toolName === 'fm_set_recording') {
				$ext = $data['ext'] ?? null;
				if (!$ext) {
					// Best-effort recovery from message text "extension {ext}"
					if (preg_match('/extension\s+(\d+)/', $data['message'] ?? '', $em)) $ext = $em[1];
				}
				$msg = preg_replace('/\s*(Pass|Send)\s+confirm[:\s]true\s+to\s+execute\.?\s*/i', '', $data['message'] ?? '');
				$chips = $this->recordingModeChips($ext);
				return rtrim($msg, '. ') . ".\n\nPick a mode: " . $chips . "\n\n{{cmd:yes|✅ Confirm}} {{cmd:no|❌ Cancel}}";
			}
			$msg = $data['message'] ?? 'Action requires confirmation.';
			// Strip API-oriented confirm instructions — the chat UI handles this
			$msg = preg_replace('/\s*(Pass|Send)\s+confirm[:\s]true\s+to\s+execute\.?\s*/i', '', $msg);
			$msg = preg_replace('/\s*Reply\s+yes\s+to\s+confirm\.?\s*/i', '', $msg);
			$msg = rtrim($msg, '. ') . '.';

			// Add warning emoji for destructive actions
			$destructiveTools = [
				'fm_disable_extension', 'fm_delete_ringgroup', 'fm_remove_inbound_route',
				'fm_remove_blacklist', 'fm_remove_misc_dest', 'fm_delete_ivr',
				'fm_delete_time_condition', 'fm_dialplan_remove', 'fm_delete_saved_query',
				'fm_delete_notification', 'fm_module_uninstall', 'fm_stop',
				'fm_clear_followme', 'fm_clear_call_forward', 'fm_disable_voicemail',
				'fm_disable_trunk', 'fm_delete_api_token', 'fm_revoke_api_token',
			];
			$isDestructive = in_array($toolName, $destructiveTools);
			if ($isDestructive) {
				$msg = preg_replace('/^Would\s+/i', 'This will ', $msg);
			}
			$prefix = $isDestructive ? '⚠️ ' : '';

			return $prefix . $msg . "\n\n{{cmd:yes|✅ Yes}} {{cmd:no|❌ No}}";
		}

		// Format based on tool
		switch ($toolName) {
			case 'fm_set_recording':
				$ext = $data['ext'] ?? null;
				if (!$ext && preg_match('/extension\s+(\d+)/', $data['message'] ?? '', $em)) $ext = $em[1];
				$msg = $data['message'] ?? 'Call recording updated.';
				if (!empty($data['success']) || !isset($data['success'])) {
					$onDemand = $data['on_demand'] ?? 'disabled';
					$note = "Applies to all four call directions (inbound/outbound × external/internal). On-demand recording (`*1` feature code) is currently `{$onDemand}` — separate setting, unchanged.";
					$link = !empty($data['extension_url'])
						? "[Open extension {$ext} → Advanced for per-direction control]({$data['extension_url']})"
						: '';
					$out = rtrim($msg, '. ') . ".\n\n" . $note . "\n\nChange mode: " . $this->recordingModeChips($ext);
					if ($link) $out .= "\n\n" . $link;
					return $out;
				}
				return "⚠️ " . rtrim($msg, '. ') . ".\n\nTry again: " . $this->recordingModeChips($ext);

			case 'fm_list_extensions':
				if (empty($data['extensions'])) {
					return "No extensions found.";
				}
				$lines = ["**Extensions** ({$data['count']}):"];
				foreach ($data['extensions'] as $ext) {
					$lines[] = "  {{cmd:show extension {$ext['extension']}|{$ext['extension']}}} — {$ext['name']} ({$ext['tech']})";
				}
				return implode("\n", $lines);

			case 'fm_get_extension':
				$u = $data['user'] ?? [];
				$d = $data['device'] ?? [];
				return "**Extension {$data['extension']}**\n"
					. "  Name: {$u['name']}\n"
					. "  Tech: " . ($d['tech'] ?? 'n/a') . "\n"
					. "  CID Masquerade: " . ($u['cid_masquerade'] ?? 'n/a') . "\n"
					. "  Call Waiting: " . ($u['callwaiting'] ?? 'n/a') . "\n"
					. "  Voicemail: " . ($u['voicemail'] ?? 'n/a');

			case 'fm_get_extension_health':
				$reg = $data['registered'] ? 'Registered' : 'Not registered';
				return "**Health: Extension {$data['extension']}** ({$data['name']})\n"
					. "  Configured: Yes\n"
					. "  Tech: {$data['tech']}\n"
					. "  Registration: {$reg}\n"
					. "  Recent calls: {$data['recent_call_count']}";

			case 'fm_list_active_calls':
				if (empty($data['calls'])) {
					return "No active calls.";
				}
				$lines = ["**Active Calls** ({$data['active_call_count']}):"];
				foreach ($data['calls'] as $c) {
					$lines[] = "  {$c['channel']} — {$c['callerid']} → {$c['extension']} ({$c['state']})";
				}
				return implode("\n", $lines);

			case 'fm_get_cdr':
				if (empty($data['records'])) {
					return "No CDR records found.";
				}
				$lines = ["**CDR** ({$data['count']} records):"];
				foreach ($data['records'] as $r) {
					$lines[] = "  {$r['calldate']} | {$r['src']} → {$r['dst']} | {$r['disposition']} | {$r['duration']}s";
				}
				return implode("\n", $lines);

			case 'fm_list_trunks':
				if (empty($data['trunks'])) {
					return "No trunks configured.";
				}
				$lines = ["**Trunks** ({$data['count']}):"];
				foreach ($data['trunks'] as $t) {
					$dis = $t['disabled'] === 'off' ? '' : ' [DISABLED]';
					$lines[] = "  {{cmd:show trunk {$t['trunkid']}|{$t['trunkid']}}} — {$t['name']} ({$t['tech']}){$dis}";
				}
				return implode("\n", $lines);

			case 'fm_list_ringgroups':
				if (empty($data['ringgroups'])) {
					return "No ring groups configured.";
				}
				$lines = ["**Ring Groups** ({$data['count']}):"];
				foreach ($data['ringgroups'] as $g) {
					$lines[] = "  {{cmd:show ringgroup {$g['grpnum']}|{$g['grpnum']}}} — {$g['description']}";
				}
				return implode("\n", $lines);

			case 'fm_get_ringgroup':
				$memberLinks = [];
				foreach ($data['members'] ?? [] as $m) {
					$ext = preg_replace('/[^0-9]/', '', $m);
					$memberLinks[] = $ext ? "{{cmd:show extension {$ext}|{$m}}}" : $m;
				}
				$members = implode(', ', $memberLinks);
				return "**Ring Group {$data['grpnum']}** — {$data['description']}\n"
					. "  Strategy: {$data['strategy']}\n"
					. "  Ring time: {$data['grptime']}s\n"
					. "  Members ({$data['member_count']}): {$members}";

			case 'fm_reload':
				return $data['message'] ?? 'Reload complete.';

			case 'fm_module_list':
				if (empty($data['modules'])) return "No modules found.";
				$upgCount = $data['upgrades_available'] ?? 0;
				$grouped = ['Commercial' => [], 'GPLv2' => [], 'GPLv3+' => [], 'AGPLv3' => [], 'Other' => []];
				foreach ($data['modules'] as $m) {
					$lic = $m['license'] ?? 'Other';
					$bucket = $grouped[$lic] ?? null;
					if ($bucket === null) $lic = 'Other';
					$grouped[$lic][] = $m;
				}
				$header = "**Modules** ({$data['count']} installed)";
				if ($upgCount > 0) {
					$header .= " — {$upgCount} {{cmd:upgrade all modules|upgrades available}}";
				}
				$lines = ["{$header}:"];
				foreach ($grouped as $license => $mods) {
					if (empty($mods)) continue;
					$lines[] = "\n**{$license}** (" . count($mods) . "):";
					foreach ($mods as $m) {
						$upg = '';
						if (!empty($m['upgrade_available'])) {
							$upg = " ⬆️ {{cmd:upgrade module {$m['name']}|v{$m['upgrade_available']}}}";
						}
						$lines[] = "  {{cmd:module status {$m['name']}|{$m['name']}}} — v{$m['version']}{$upg}";
					}
				}
				return implode("\n", $lines);

			case 'fm_audit_search':
				if (empty($data['entries'])) {
					return "No audit entries found.";
				}
				$lines = ["**Audit Log** ({$data['count']}):"];
				foreach ($data['entries'] as $e) {
					$ts = $e['created_at_human'] ?? date('H:i:s', $e['created_at']);
					$lines[] = "  {$ts} | {$e['tool']} | {$e['status']}";
				}
				return implode("\n", $lines);


			case 'fm_dialplan_show':
				if (empty($data['contexts'])) {
					return "No custom dialplan contexts found in extensions_custom.conf.";
				}
				$lines = ["**Custom Dialplan Contexts** ({$data['count']}):"];
				foreach ($data['contexts'] as $ctx) {
					$comment = $ctx['comment'] ? " — {$ctx['comment']}" : '';
					$lines[] = "  `[{$ctx['context']}]` ({$ctx['lines']} lines){$comment}";
				}
				return implode("\n", $lines);

			case 'fm_dialplan_get_context':
				$lines = ["**Context [{$data['name']}]:**", '```'];
				foreach ($data['lines'] as $line) {
					$lines[] = $line;
				}
				$lines[] = '```';
				return implode("\n", $lines);

			case 'fm_dialplan_templates':
				$lines = ["**Dialplan Templates:**"];
				foreach ($data['templates'] as $t) {
					$lines[] = "  **{$t['name']}** (`{$t['id']}`) — {$t['description']}";
				}
				return implode("\n", $lines);

			case 'fm_dialplan_apply':
				if (!empty($data['dialplan'])) {
					return "**Preview — {$data['context']}:**\n```\n{$data['dialplan']}\n```\n{{cmd:yes|✅ Yes}} {{cmd:no|❌ No}}";
				}
				$msg = $data['message'] ?? 'Dialplan applied.';
				if (!empty($data['context'])) {
					$msg .= " Context: `[{$data['context']}]`";
				}
				return $msg;

			case 'fm_dialplan_remove':
				return $data['message'] ?? 'Context removed.';

			case "oc_list_misc_dests":
				if (empty($data["destinations"])) {
					return "No misc destinations found.";
				}
				$lines = ["**Misc Destinations** ({$data["count"]}):"];
				foreach ($data["destinations"] as $d) {
					$lines[] = "  `{$d["id"]}` — {$d["description"]} → {$d["destdial"]}";
				}
				return implode("
", $lines);


			case 'fm_list_inbound_routes':
				if (empty($data['routes'])) return "No inbound routes configured.";
				$lines = ["**Inbound Routes** ({$data['count']}):"];
				foreach ($data['routes'] as $r) {
					$desc = !empty($r['description']) ? " ({$r['description']})" : '';
					$lines[] = "  `{$r['extension']}` → {$r['destination']}{$desc}";
				}
				return implode("\n", $lines);

			case 'fm_list_outbound_routes':
				if (empty($data['routes'])) return "No outbound routes configured.";
				$lines = ["**Outbound Routes** ({$data['count']}):"];
				foreach ($data['routes'] as $r) {
					$lines[] = "  `{$r['route_id']}` — {$r['name']}";
				}
				return implode("\n", $lines);

			case 'fm_get_outbound_route':
				$name = $data['name'] ?? 'unknown';
				return "**Outbound Route {$data['route_id']}** — {$name}\nTrunks: " . count($data['trunks'] ?? []) . "\nPatterns: " . count($data['patterns'] ?? []);

			case 'fm_list_queues':
				if (empty($data['queues'])) return "No queues configured.";
				$lines = ["**Queues** ({$data['count']}):"];
				foreach ($data['queues'] as $q) {
					$lines[] = "  {{cmd:show queue {$q['extension']}|{$q['extension']}}} — {$q['name']}";
				}
				return implode("\n", $lines);

			case 'fm_get_queue':
				$ext = $data['extension'] ?? $data['account'] ?? '?';
				$name = $data['descr'] ?? $data['name'] ?? '';
				$members = $data['dynamic_members'] ?? [];
				return "**Queue {$ext}** — {$name}\n  Strategy: " . ($data['strategy'] ?? 'n/a') . "\n  Timeout: " . ($data['timeout'] ?? 'n/a') . "s\n  Dynamic members: " . count($members);

			case 'fm_list_time_conditions':
				if (empty($data['time_conditions'])) return "No time conditions configured.";
				$lines = ["**Time Conditions** ({$data['count']}):"];
				foreach ($data['time_conditions'] as $tc) {
					$state = is_array($tc['state']) ? 'normal' : ($tc['state'] ?: 'normal');
					$icon = $state === 'override' ? '🔴' : '🟢';
					$lines[] = "  {$icon} `{$tc['id']}` — **{$tc['name']}** ({$state})";
				}
				return implode("\n", $lines);

			case 'fm_get_call_forward':
				$ext = $data['extension'];
				$cf = $data['call_forward'] ?? 'none';
				$cfb = $data['call_forward_busy'] ?? 'none';
				$cfu = $data['call_forward_unavailable'] ?? 'none';
				return "**Call Forward — Ext {$ext}**\n  All calls: {$cf}\n  When busy: {$cfb}\n  Unavailable: {$cfu}";

			case 'fm_get_dnd':
				return "**DND on {$data['extension']}:** {$data['dnd']}";

			case 'fm_list_blacklist':
				if (empty($data['blacklist'])) return "Blacklist is empty.";
				$lines = ["**Blacklist** ({$data['count']}):"];
				foreach ($data['blacklist'] as $b) {
					$desc = !empty($b['description']) ? " — {$b['description']}" : '';
					$lines[] = "  `{$b['number']}`{$desc}";
				}
				return implode("\n", $lines);

			case 'fm_list_daynight':
				if (empty($data['call_flows'])) return "No day/night call flows configured.";
				$lines = ["**Day/Night Call Flows** ({$data['count']}):"];
				foreach ($data['call_flows'] as $f) {
					$lines[] = "  `{$f['id']}` — state: {$f['state']}";
				}
				return implode("\n", $lines);

			case 'fm_list_voicemail':
				if (!empty($data['settings'])) {
					$lines = ["**Voicemail Settings** ({$data['count']}):"];
					foreach ($data['settings'] as $k => $v) {
						$val = is_string($v) && strlen($v) > 80 ? substr($v, 0, 80) . '...' : $v;
						$lines[] = "  `{$k}` = {$val}";
					}
					return implode("\n", $lines);
				}
				if (empty($data['voicemails'])) return "No voicemail boxes configured.";
				$lines = ["**Voicemail Boxes** ({$data['count']}):"];
				foreach ($data['voicemails'] as $v) {
					$lines[] = "  {{cmd:show voicemail for {$v['extension']}|{$v['extension']}}} — {$v['name']}";
				}
				return implode("\n", $lines);

			case 'fm_get_voicemail':
				$ext = $data['mailbox'] ?? $data['extension'] ?? '?';
				$newm = $data['new_messages'] ?? 0;
				$oldm = $data['old_messages'] ?? 0;
				return "**Voicemail {$ext}**\n  New messages: {$newm}\n  Old messages: {$oldm}\n  Email: " . ($data['email'] ?? 'none');

			case 'fm_list_conferences':
				if (empty($data['conferences'])) return "No conference rooms configured.";
				$lines = ["**Conferences** ({$data['count']}):"];
				foreach ($data['conferences'] as $c) {
					$lines[] = "  {{cmd:show conference {$c['extension']}|{$c['extension']}}} — {$c['name']}";
				}
				return implode("\n", $lines);

			case 'fm_get_conference':
				$ext = $data['exten'] ?? '?';
				$name = $data['name'] ?? '';
				return "**Conference {$ext}** — {$name}\n  Pin: " . ($data['pin'] ?? 'none') . "\n  Admin Pin: " . ($data['adminpin'] ?? 'none');

			case 'fm_list_paging':
				if (empty($data['paging_groups'])) return "No paging groups configured.";
				$lines = ["**Paging Groups** ({$data['count']}):"];
				foreach ($data['paging_groups'] as $p) {
					$lines[] = "  `{$p['extension']}` — {$p['description']}";
				}
				return implode("\n", $lines);

			case 'fm_list_parking':
				$lots = $data['lots'] ?? [];
				$parked = $data['parked_calls'] ?? [];
				if (empty($lots)) return "No parking lots configured.";
				$lines = ["**Parking** (" . count($lots) . " lots, " . count($parked) . " parked calls):"];
				foreach ($lots as $id => $lot) {
					$lines[] = "  Lot `{$id}`";
				}
				return implode("\n", $lines);

			case 'fm_list_ivrs':
				if (empty($data['ivrs'])) return "No IVRs configured.";
				$lines = ["**IVRs** ({$data['count']}):"];
				foreach ($data['ivrs'] as $i) {
					$desc = !empty($i['description']) ? "\n    {$i['description']}" : '';
					$lines[] = "  {{cmd:show ivr {$i['id']}|{$i['id']} — {$i['name']}}}{$desc}";
				}
				return implode("\n", $lines);

			case 'fm_get_ivr':
				$name = $data['name'] ?? '';
				$id = $data['id'] ?? '?';
				return "**IVR {$id}** — {$name}\n  Timeout: " . ($data['timeout'] ?? 'n/a') . "s";

			case 'fm_list_announcements':
				if (empty($data['announcements'])) return "No announcements configured.";
				$lines = ["**Announcements** ({$data['count']}):"];
				foreach ($data['announcements'] as $a) {
					$lines[] = "  `{$a['id']}` — {$a['description']}";
				}
				return implode("\n", $lines);

			case 'fm_list_feature_codes':
				if (empty($data['feature_codes'])) return "No feature codes found.";
				$enabled = array_filter($data['feature_codes'], function($fc) { return $fc['enabled']; });
				$disabled = array_filter($data['feature_codes'], function($fc) { return !$fc['enabled']; });
				$lines = ["**Feature Codes** — {$data['count']} total (" . count($enabled) . " active, " . count($disabled) . " disabled):"];
				// Group enabled codes by module
				$grouped = [];
				foreach ($enabled as $fc) {
					$mod = $fc['module'] ?: 'Other';
					$grouped[$mod][] = $fc;
				}
				ksort($grouped);
				foreach ($grouped as $mod => $codes) {
					$lines[] = "  **{$mod}:**";
					foreach ($codes as $fc) {
						$code = $fc['code'] ?: '-';
						$lines[] = "    `{$code}` — {$fc['description']}";
					}
				}
				if (!empty($disabled)) {
					$lines[] = "\n  " . count($disabled) . " disabled feature codes not shown.";
				}
				return implode("\n", $lines);

			case 'fm_list_recordings':
				$builtin = $data['builtin_count'] ?? 0;
				if (empty($data['recordings'])) {
					return "No custom recordings found.\n📦 {$builtin} built-in Asterisk sounds available. {{cmd:list all recordings|Show all (verbose)}}";
				}
				$lines = ["**Custom Recordings** ({$data['count']}):"];
				foreach ($data['recordings'] as $r) {
					$lines[] = "  🎙️ `{$r['name']}`";
				}
				$lines[] = "\n📦 {$builtin} built-in Asterisk sounds also available. {{cmd:list all recordings|Show all (verbose)}}";
				return implode("\n", $lines);

			case 'fm_list_moh':
				if (empty($data['categories'])) return "No music on hold categories.";
				$lines = ["**Music on Hold** ({$data['count']}):"];
				foreach ($data['categories'] as $c) {
					$lines[] = "  `{$c['name']}` ({$c['type']})";
				}
				return implode("\n", $lines);

			case 'fm_get_firewall_status':
				$ids = $data['intrusion_detection'] ?? 'unknown';
				$lines = ["**Firewall Status:**"];
				$lines[] = "  🛡️ Intrusion Detection: `{$ids}`";
				$zoneCount = $data['zone_count'] ?? 0;
				if (!empty($data['network_zones'])) {
					$lines[] = "\n**Network Zones** ({$zoneCount}):";
					foreach ($data['network_zones'] as $z) {
						$lines[] = "  `{$z['network']}` → {$z['zone']}";
					}
				} else {
					$lines[] = "  Network Zones: {$zoneCount}";
				}
				return implode("\n", $lines);

			case 'fm_get_asterisk_info':
				$lines = ["**Asterisk Info:**"];
				if (!empty($data['version'])) $lines[] = "  📦 Version: `{$data['version']}`";
				if (!empty($data['uptime'])) {
					$up = preg_replace('/Privilege:\s+\w+,?\s*/i', '', $data['uptime']);
					$up = str_replace("\n", ", ", trim($up));
					$lines[] = "  ⏱️ {$up}";
				}
				if (isset($data['registered_endpoints'])) $lines[] = "  📞 Registered endpoints: {$data['registered_endpoints']}";
				return implode("\n", $lines);

			case 'fm_get_sip_settings':
				$lines = ["**SIP Settings:**"];
				$lines[] = "  📡 Driver: `{$data['sip_driver']}`" ?? 'unknown';
				if (!empty($data['external_ip'])) $lines[] = "  🌐 External IP: `{$data['external_ip']}`";
				if (!empty($data['local_networks'])) $lines[] = "  🏠 Local Networks: `{$data['local_networks']}`";
				$lines[] = "  🔊 RTP Range: `" . ($data['rtp_start'] ?? '?') . "-" . ($data['rtp_end'] ?? '?') . "`";
				if (!empty($data['transports'])) {
					$tlines = explode("\n", $data['transports']);
					foreach ($tlines as $tl) {
						$tl = trim($tl);
						if (!empty($tl) && stripos($tl, 'TransportId') === false && stripos($tl, '===') === false && stripos($tl, 'Privilege') === false) {
							if (preg_match('/Transport:\s+(\S+)\s+(\w+)/', $tl, $tm)) {
								$lines[] = "  🔗 Transport: `{$tm[1]}` ({$tm[2]})";
							}
						}
					}
				}
				return implode("\n", $lines);

			case 'fm_get_trunk_status':
				$t = $data['trunk'] ?? [];
				$name = $t['name'] ?? 'unknown';
				$tech = $t['tech'] ?? '?';
				$reg = $data['registration_status'] ?? 'n/a';
				return "**Trunk {$t['trunkid']}** — {$name} ({$tech})\n  Registration: {$reg}";


			case 'fm_diagnose_extension':
				$checks = $data['checks'] ?? [];
				$cfg = $checks['extension_config'] ?? [];
				$name = $cfg['name'] ?? 'Unknown';
				$ext = $data['extension'];
				$lines = ["🔍 **Diagnosing Extension {$ext} — {$name}**"];

				// Registration
				if (isset($checks['endpoint'])) {
					$ep = $checks['endpoint'];
					if ($ep['registered']) {
						$contact = !empty($ep['contacts']) ? " (`{$ep['contacts'][0]}`)" : '';
						$lines[] = "🟢 Registration: **Online**{$contact}";
					} else {
						$lines[] = "⚪ Registration: **Offline** (no contacts)";
					}
				}

				// Active calls
				if (isset($checks['active_calls'])) {
					$count = $checks['active_calls']['count'];
					$callIcon = $count > 0 ? '🔴' : '📞';
					$lines[] = "{$callIcon} Active Calls: {$count}";
					if (!empty($checks['active_calls']['calls'])) {
						foreach ($checks['active_calls']['calls'] as $c) {
							$lines[] = "    {$c['channel']} — {$c['state']} ({$c['duration']}s)";
						}
					}
				}

				// CDR
				if (isset($checks['recent_cdr'])) {
					$cdr = $checks['recent_cdr'];
					$cdrText = "{$cdr['count']} records";
					if (!empty($cdr['records'])) {
						$last = $cdr['records'][0];
						$cdrText .= " (last: {$last['calldate']})";
					}
					$lines[] = "📋 Recent CDR: {$cdrText}";
					if (!empty($cdr['records'])) {
						foreach (array_slice($cdr['records'], 0, 3) as $r) {
							$lines[] = "    {$r['src']} → {$r['dst']} | {$r['disposition']} | {$r['duration']}s";
						}
					}
				}

				// Issues summary at the bottom
				$issues = [];
				if (isset($checks['endpoint']) && !$checks['endpoint']['registered']) {
					$issues[] = "Phone is not registered";
				}
				if (!empty($checks['recent_cdr']['records'])) {
					$records = $checks['recent_cdr']['records'];
					$failed = count(array_filter($records, function($r) { return $r['disposition'] !== 'ANSWERED'; }));
					if ($failed > count($records) / 2) {
						$issues[] = round($failed / count($records) * 100) . "% of recent calls unanswered";
					}
				}
				if (!empty($issues)) {
					$lines[] = "\n⚠️ **" . implode('; ', $issues) . "**";
				} elseif (isset($checks['endpoint']) && $checks['endpoint']['registered']) {
					$lines[] = "\n✅ **Extension looks healthy**";
				}

				return implode("\n", $lines);

			case 'fm_diagnose_trunk':
				$trunk = $data['trunk'] ?? [];
				$name = $trunk['name'] ?? 'Unknown';
				$tid = $data['trunk_id'];
				$lines = ["🔍 **Diagnosing Trunk {$tid} — {$name}**"];
				$checks = $data['checks'] ?? [];

				// Disabled?
				if (!empty($checks['disabled'])) {
					$lines[] = "🚫 Status: **Disabled**";
					return implode("\n", $lines);
				}

				// Registration
				if (isset($checks['registration'])) {
					$state = strtolower($checks['registration']['state']);
					$isReg = strpos($state, 'registered') === 0;
					$icon = $isReg ? '🟢' : '⚪';
					$label = $isReg ? '**Registered**' : "**{$checks['registration']['state']}**";
					$lines[] = "{$icon} Registration: {$label}";
				}

				// Routes
				$routeCount = !empty($checks['outbound_routes']) ? count($checks['outbound_routes']) : 0;
				$routeIcon = $routeCount > 0 ? '🔀' : '⚠️';
				$lines[] = "{$routeIcon} Outbound Routes: {$routeCount}";

				// CDR
				if (isset($checks['recent_cdr'])) {
					$cdr = $checks['recent_cdr'];
					$cdrText = "{$cdr['count']} records";
					if (!empty($cdr['records'])) {
						$last = $cdr['records'][0];
						$cdrText .= " (last: {$last['calldate']})";
					}
					$lines[] = "📋 Recent CDR: {$cdrText}";
					if (!empty($cdr['records'])) {
						foreach (array_slice($cdr['records'], 0, 3) as $r) {
							$lines[] = "    {$r['src']} → {$r['dst']} | {$r['disposition']} | {$r['duration']}s";
						}
					}
				}

				// Issues
				$issues = [];
				if (isset($checks['registration'])) {
					$state = strtolower($checks['registration']['state']);
					if (strpos($state, 'registered') !== 0) {
						$issues[] = "Not registered";
					}
				}
				if ($routeCount === 0) $issues[] = "No outbound routes";
				if (!empty($cdr['records'])) {
					$failed = count(array_filter($cdr['records'], function($r) { return $r['disposition'] === 'FAILED'; }));
					if ($failed > count($cdr['records']) / 2) {
						$issues[] = round($failed / count($cdr['records']) * 100) . "% of recent calls failed";
					}
				}
				if (!empty($issues)) {
					$lines[] = "\n⚠️ **" . implode('; ', $issues) . "**";
				} elseif (empty($issues) && isset($checks['registration'])) {
					$lines[] = "\n✅ **Trunk looks healthy**";
				}

				return implode("\n", $lines);

			case 'fm_pjsip_endpoint_details':
				$lines = ["**PJSIP Endpoint: {$data['endpoint']}**"];
				$p = $data['parsed'] ?? [];
				if (!empty($p['callerid'])) $lines[] = "  Caller ID: {$p['callerid']}";
				if (!empty($p['allow'])) $lines[] = "  Codecs: {$p['allow']}";
				if (!empty($p['context'])) $lines[] = "  Context: {$p['context']}";
				if (!empty($p['transport'])) $lines[] = "  Transport: {$p['transport']}";
				$lines[] = "  NAT: rtp_symmetric=" . ($p['rtp_symmetric'] ?? '?') . " force_rport=" . ($p['force_rport'] ?? '?') . " rewrite_contact=" . ($p['rewrite_contact'] ?? '?');
				if (!empty($p['contacts'])) {
					foreach ($p['contacts'] as $c) $lines[] = "  Contact: {$c}";
				} else {
					$lines[] = "  Contacts: **none (not registered)**";
				}
				$q = $data['qualify_result'] ?? [];
				if (!empty($q['Response'])) $lines[] = "  Qualify: {$q['Response']} — " . ($q['Message'] ?? '');
				return implode("\n", $lines);

			case 'fm_list_sangoma_phones':
				if (empty($data['phones'])) return "📞 No Sangoma phones registered with DPMA.";
				$lines = ["📞 **Sangoma phones** ({$data['count']}):", ""];
				foreach ($data['phones'] as $p) {
					$id = $p['identifier'] ?? '?';
					$ext = $p['ext'] ?? null;
					$header = $ext
						? "📞 {{cmd:sangoma phone {$ext}|Sangoma phone {$ext}}} ({$id})"
						: "📞 {$id}";
					$lines[] = $header;
					if (!empty($p['name'])) $lines[] = "  Name: {$p['name']}";
					if (!empty($p['model'])) $lines[] = "  Model: {$p['model']}";
					if (!empty($p['mac']) && $p['mac'] !== '<none>') $lines[] = "  MAC: {$p['mac']}";
					if (isset($p['registered'])) {
						if ($p['registered']) {
							$cnt = $p['contact_count'] ?? 1;
							$lines[] = "  Registered: ✓ yes ({$cnt} contact" . ($cnt === 1 ? '' : 's') . ")";
						} else {
							$lines[] = "  Registered: ✗ no";
						}
					}
					$lines[] = "";
				}
				return rtrim(implode("\n", $lines));

			case 'fm_get_sangoma_phone':
				if (empty($data['known_to_dpma'])) {
					return "📞 DPMA does not know about phone {$data['extension']} ({$data['identifier']}).";
				}
				$p = $data['parsed'] ?? [];
				$reg = $data['sip_registration'] ?? [];
				$lines = ["📞 **Sangoma phone {$data['extension']}** ({$data['identifier']})"];
				if (!empty($p['full_name'])) $lines[] = "  Name: {$p['full_name']}";
				if (!empty($p['mac']) && $p['mac'] !== '<none>') $lines[] = "  MAC: {$p['mac']}";
				if (!empty($p['active_ringtone'])) $lines[] = "  Ringtone: {$p['active_ringtone']}";
				if (!empty($p['configfile'])) $lines[] = "  Config: {$p['configfile']}";
				$lines[] = "  Registered: " . (!empty($reg['registered']) ? "✓ yes (" . count($reg['contacts']) . " contact" . (count($reg['contacts']) === 1 ? '' : 's') . ")" : "✗ no");
				if (!empty($data['epm_mapping'])) {
					$m = $data['epm_mapping'];
					$brand = $m['brand'] ?? $m['vendor'] ?? '';
					$model = $m['model'] ?? '';
					if ($brand || $model) $lines[] = "  EPM: {$brand} {$model}";
				}
				return implode("\n", $lines);

			case 'fm_diagnose_sangoma_phone':
				$lines = ["📞 **Diagnose Sangoma {$data['extension']}**"];
				if (!empty($data['summary'])) $lines[] = "  " . $data['summary'];
				$c = $data['checks'] ?? [];
				if (!empty($c['epm_mapping']) && empty($c['epm_mapping']['status'])) {
					$lines[] = "  Mapping: ✓";
				} else {
					$lines[] = "  Mapping: ✗ not provisioned in EPM";
				}
				if (isset($c['license'])) {
					$lic = !empty($c['license']['covered']);
					$brand = $c['license']['brand'] ?? '';
					$count = $c['license']['brand_count'] ?? 0;
					$badge = $lic ? "✓ ({$brand}: {$count})" : "✗ no {$brand} licenses in use";
					$lines[] = "  License: {$badge}";
				}
				if (isset($c['sip_registration'])) {
					$lines[] = "  Registered: " . (!empty($c['sip_registration']['registered']) ? '✓' : '✗');
				}
				if (isset($c['dpma_state'])) {
					$lines[] = "  DPMA aware: " . (!empty($c['dpma_state']['known_to_dpma']) ? '✓' : '✗');
				}
				if (isset($c['firmware_audit']) && $c['firmware_audit']['current']) {
					$utd = $c['firmware_audit']['up_to_date'];
					$badge = $utd === true ? '✓' : ($utd === false ? '⚠️ out of date' : '?');
					$lines[] = "  Firmware: {$c['firmware_audit']['current']} {$badge}";
				}
				if (isset($c['dpma_alerts']) && $c['dpma_alerts']['count'] > 0) {
					$lines[] = "  ⚠️ DPMA alerts: {$c['dpma_alerts']['count']}";
				}
				return implode("\n", $lines);

			case 'fm_dpma_alerts':
				if (empty($data['alerts']) || $data['count'] === 0) {
					$f = !empty($data['filter']) ? " for ext {$data['filter']}" : '';
					return "📞 No DPMA alerts{$f}.";
				}
				$lines = ["⚠️ **DPMA alerts** ({$data['count']}):"];
				foreach ($data['alerts'] as $a) $lines[] = "  {$a}";
				return implode("\n", $lines);

			case 'fm_dpma_license_status':
				$valid = !empty($data['valid']);
				$statusBadge = $valid ? '✓' : '✗';
				$statusText = $data['status_line'] ?? ($valid ? 'valid' : 'unknown');
				// Strip the "OK, " prefix DPMA prepends to its own status line — redundant with the badge.
				$statusText = preg_replace('/^OK,\s*/i', '', $statusText);
				$lines = ["📞 **DPMA License**"];
				$lines[] = "  Status: {$statusBadge} {$statusText}";
				$bc = $data['brand_counts'] ?? null;
				if (is_array($bc)) {
					$sang = (int)($bc['sangoma'] ?? 0);
					$digi = (int)($bc['digium'] ?? 0);
					$lines[] = "  Sangoma phones licensed: {$sang}";
					$lines[] = "  Digium phones licensed: {$digi}";
				}
				$ep = $data['epm_licensed'] ?? null;
				if (is_array($ep)) {
					if (isset($ep['epm'])) $lines[] = "  EPM module: " . (!empty($ep['epm']) ? '✓' : '✗');
					if (isset($ep['ucp'])) $lines[] = "  UCP module: " . (!empty($ep['ucp']) ? '✓' : '✗');
				}
				return implode("\n", $lines);

			case 'fm_reboot_sangoma_phone':
				if (!empty($data['error'])) return "✗ {$data['error']}";
				if (!empty($data['dry_run'])) {
					$ph = $data['phone'] ?? [];
					return "📞 **Reboot preview** — ext {$ph['ext']} ({$ph['model']}). Reply **yes** to confirm. Phone will be unavailable ~30s.";
				}
				return "📞 " . ($data['message'] ?? 'Reboot NOTIFY sent.');

			case 'fm_pjsip_show_channels':
				if ($data['channel_count'] === 0) {
					return "No active PJSIP channels.";
				}
				$lines = ["**Active PJSIP Channels** ({$data['channel_count']}):"];
				foreach ($data['channels'] as $ch) {
					$lines[] = "  {$ch}";
				}
				return implode("\n", $lines);

			case 'fm_sip_trace':
				$d = $data;
				$traceStatus = $d['status'] ?? null;
				if ($traceStatus === 'started') {
					return "**SIP Trace started** — capturing for {$d['duration']}s (auto-stops at {$d['auto_stop_at']}).\nType `stop trace` to end and see results.";
				}
				if ($traceStatus === 'stopped') {
					$lines = ["**SIP Trace stopped** — {$d['message_count']} SIP messages captured ({$d['raw_size_bytes']} bytes)"];
					if (!empty($d['messages'])) {
						foreach (array_slice($d['messages'], 0, 30) as $m) {
							if ($m['type'] === 'request') {
								$lines[] = "  → {$m['method']}";
							} elseif ($m['type'] === 'response') {
								$lines[] = "  ← {$m['code']} {$m['reason']}";
							} else {
								$lines[] = "  {$m['line']}";
							}
						}
						if ($d['message_count'] > 30) {
							$lines[] = "  ... and " . ($d['message_count'] - 30) . " more";
						}
					}
					return implode("\n", $lines);
				}
				// status check
				$running = $d['running'] ? 'Yes' : 'No';
				return "**SIP Trace Status:** Running: {$running} | Captured: {$d['capture_size_bytes']} bytes";

			case 'fm_list_filestores':
				$locs = $data['locations']['locations'] ?? [];
				$types = $data['locations']['filestoreTypes'] ?? [];
				$total = 0;
				foreach ($locs as $stores) { $total += count($stores); }
				if (empty($locs)) return "No filestores configured.\n\nAvailable types: " . implode(', ', $types);
				$lines = ["**Filestores** ({$total} configured):"];
				$typeIcons = ['Local' => '📁', 'SSH' => '🔑', 'S3' => '☁️', 'Dropbox' => '📦', 'FTP' => '📡', 'Email' => '✉️'];
				foreach ($locs as $type => $stores) {
					$icon = $typeIcons[$type] ?? '📂';
					$lines[] = "\n{$icon} **{$type}**";
					foreach ($stores as $s) {
						$desc = !empty($s['description']) ? " — {$s['description']}" : '';
						$lines[] = "  `{$s['name']}`{$desc}";
					}
				}
				$unused = array_diff($types, array_keys($locs));
				if (!empty($unused)) {
					$lines[] = "\n**Other available:**";
					foreach ($unused as $t) {
						$icon = $typeIcons[$t] ?? '📂';
						$lines[] = "  {$icon} {$t}";
					}
				}
				return implode("\n", $lines);

			case 'fm_get_license_info':
				$activated = !empty($data['activated']);
				$actIcon = $activated ? '🟢' : '🔴';
				$actLabel = $activated ? 'Active' : 'Not Activated';
				$lines = ["**License & Activation:**"];
				$lines[] = "  {$actIcon} System: **{$actLabel}**";
				$activation = $data['activation'] ?? [];
				foreach ($activation as $k => $v) {
					if (strtolower($k) === 'activation status') continue;
					$lines[] = "  {$k}: `{$v}`";
				}

				// Support contract
				$support = $data['support_contract'] ?? [];
				if (!empty($support)) {
					$expDate = $support['expiration_date'] ?? 'unknown';
					// Don't trust FreePBX's boolean alone — check the date ourselves
					$isExpired = !empty($support['expired']);
					if (!$isExpired && $expDate !== 'unknown') {
						$isExpired = strtotime($expDate) < time();
					}
					$isExpiringSoon = !$isExpired && !empty($support['expiring_soon']);
					if (!$isExpiringSoon && !$isExpired && $expDate !== 'unknown') {
						$daysLeft = (int)((strtotime($expDate) - time()) / 86400);
						$isExpiringSoon = $daysLeft <= 30 && $daysLeft >= 0;
					}

					if ($isExpired) {
						$lines[] = "\n🔴 **Support Contract: Expired** ({$expDate})";
					} elseif ($isExpiringSoon) {
						$daysLeft = (int)((strtotime($expDate) - time()) / 86400);
						$lines[] = "\n🟡 **Support Contract: Expiring Soon** — {$daysLeft} days left ({$expDate})";
					} else {
						$lines[] = "\n🟢 Support Contract: **Active** (expires {$expDate})";
					}
				}

				// Registered licenses (add-ons and modules)
				$addons = $data['addon_licenses'] ?? [];
				$modLics = $data['module_licenses'] ?? [];
				if (!empty($addons) || !empty($modLics)) {
					$lines[] = "\n**Registered Licenses:**";
					foreach ($addons as $a) {
						$expTime = strtotime($a['expiry']);
						$isExp = $expTime && $expTime < time();
						$icon = $isExp ? '🔴' : '🟢';
						$lines[] = "  {$icon} **{$a['name']}** — {$a['used']}/{$a['total']} used, expires {$a['expiry']}";
					}
					foreach ($modLics as $ml) {
						$expTime = strtotime($ml['expiry']);
						$isExp = $expTime && $expTime < time();
						$icon = $isExp ? '🔴' : '🟢';
						$lines[] = "  {$icon} **{$ml['name']}** — expires {$ml['expiry']}";
					}
				}

				// Commercial modules
				$commercial = $data['commercial_modules'] ?? [];
				$count = $data['commercial_count'] ?? 0;
				if ($count > 0) {
					$lines[] = "\n**Commercial Modules** ({$count}):";
					foreach ($commercial as $m) {
						$lines[] = "  {{cmd:module status {$m['name']}|{$m['name']}}} — v{$m['version']}";
					}
				} else {
					$lines[] = "\nNo commercial modules installed.";
				}
				return implode("\n", $lines);

			case 'fm_update_activation':
				$lic = $data['license'] ?? [];
				// Background mode: tool kicked off `fwconsole sa activate` async because
				// it restarts apache. Skip the System/Activation block (would be misleading
				// mid-restart) and surface a clickable sc status follow-up.
				if (!empty($data['background'])) {
					$msg = $data['message'] ?? 'Activation refresh started.';
					$msg = str_replace('`sc status`', '{{cmd:sc status|sc status}}', $msg);
					$lines = ["✅ **{$msg}**"];
					if (!empty($data['deployment_id'])) {
						$lines[] = "  🔑 Deployment: `{$data['deployment_id']}`";
					}
					return implode("\n", $lines);
				}
				$lines = ["✅ **{$data['message']}**"];

				// System info (always available from BMO)
				$actIcon = !empty($data['activated']) ? '🟢' : '🔴';
				$actLabel = !empty($data['activated']) ? 'Active' : 'Not Activated';
				$lines[] = "\n**System:**";
				$lines[] = "  {$actIcon} Activation: **{$actLabel}**";
				if (!empty($data['deployment_id'])) $lines[] = "  🔑 Deployment: `{$data['deployment_id']}`";
				if (!empty($data['deployment_type'])) $lines[] = "  📋 Type: {$data['deployment_type']}";

				// If we got the full license table, show the rich data
				if (!empty($lic)) {
					$product = $lic['Product-Name'] ?? '';
					$hwLocked = $lic['Hardware-Locked'] ?? '';
					$expires = $lic['Expires'] ?? '';
					$globalExp = $lic['global_lic_exp'] ?? '';
					$zuluExp = $lic['zulu_exp'] ?? '';
					$zuluUsers = $lic['zulu_users'] ?? '';

					if ($product) $lines[] = "  📦 Product: **{$product}**";
					if ($hwLocked) $lines[] = "  🔒 Hardware Locked: {$hwLocked}";

					$lines[] = "\n**Expiration:**";
					if ($expires) {
						$expTime = strtotime($expires);
						$isExp = $expTime && $expTime < time();
						$icon = $isExp ? '🔴' : '🟢';
						$lines[] = "  {$icon} License: **{$expires}**";
					}
					if ($globalExp) $lines[] = "  📅 Global: {$globalExp}";

					// Support contract — decode base64 payload
					if (!empty($lic['support_contract'])) {
						$decoded = @json_decode(base64_decode($lic['support_contract']), true);
						if ($decoded) {
							$scExp = $decoded['expiration'] ?? 'unknown';
							$scOrg = $decoded['organization_name'] ?? '';
							$scExpired = strtotime($scExp) < time();
							$scIcon = $scExpired ? '🔴' : '🟢';
							$scLabel = $scExpired ? '**Expired**' : '**Active**';
							$lines[] = "  {$scIcon} Support: {$scLabel} (expires {$scExp})";
							if ($scOrg) $lines[] = "  🏢 Organization: {$scOrg}";
						}
					}

					if ($zuluExp) {
						$zExpired = strtotime($zuluExp) < time();
						$zIcon = $zExpired ? '🔴' : '🟢';
						$lines[] = "\n**Zulu UC:**";
						$lines[] = "  {$zIcon} Expires: {$zuluExp}";
						if ($zuluUsers) $lines[] = "  👥 Users: {$zuluUsers}";
					}
				}

				// Support contract from BMO (always available)
				if (empty($lic) && !empty($data['support_contract'])) {
					$sc = $data['support_contract'];
					$scExp = $sc['expiredDate'] ?? 'unknown';
					$scExpired = !empty($sc['isExpired']) || ($scExp !== 'unknown' && strtotime($scExp) < time());
					$scIcon = $scExpired ? '🔴' : '🟢';
					$scLabel = $scExpired ? '**Expired**' : '**Active**';
					$lines[] = "\n  {$scIcon} Support Contract: {$scLabel} (expires {$scExp})";
				}

				return implode("\n", $lines);

			case 'fm_module_status':
				$name = $data['display_name'] ?? $data['name'] ?? '?';
				$rawName = $data['name'] ?? '?';
				$version = $data['version'] ?? '?';
				$status = $data['status'] ?? 'unknown';
				$license = $data['license'] ?? 'Unknown';
				$category = $data['category'] ?? '';
				$publisher = $data['publisher'] ?? '';
				$desc = $data['description'] ?? '';
				$upgrade = $data['upgrade_available'] ?? null;
				// Clean up description — strip "COMMERCIAL MODULE REQUIRES..." boilerplate
				$desc = preg_replace('/COMMERCIAL MODULE REQUIRES.*?(?:FUNCTION|FEATURES)\.\s*/i', '', $desc);
				$desc = preg_replace('/^System Administration\s*-\s*/i', '', $desc);
				// Clean up tabs/extra whitespace
				$desc = preg_replace('/\s+/', ' ', trim($desc));
				// No truncation — show full description

				$statusIcon = strtolower($status) === 'enabled' ? '🟢' : '⚪';
				$licIcon = stripos($license, 'Commercial') !== false ? '💼' : '📦';

				$lines = ["**{$name}** (`{$rawName}`)"];
				$versionLine = "  {$statusIcon} Status: **{$status}** | v{$version}";
				if ($upgrade) {
					$versionLine .= " ⬆️ {{cmd:upgrade module {$rawName}|upgrade to v{$upgrade}}}";
				}
				$lines[] = $versionLine;
				$lines[] = "  {$licIcon} License: {$license}";
				if ($category) $lines[] = "  📁 Category: {$category}";
				if ($publisher) $lines[] = "  🏢 Publisher: {$publisher}";
				if ($desc) $lines[] = "\n  {$desc}";
				return implode("\n", $lines);

			case 'fm_get_mcp_config':
				$ip = $data['ip'] ?? 'YOUR_PBX_IP';
				$path = $data['mcp_server'] ?? '';
				$lines = ["🔌 **Frogman Connection Guide**"];
				$lines[] = "\n**MCP (Claude Desktop):**";
				$lines[] = "Add to `~/.claude/claude_desktop_config.json`:";
				$lines[] = "`{\"mcpServers\":{\"frogman\":{\"command\":\"ssh\",\"args\":[\"root@{$ip}\",\"php\",\"{$path}\"]}}}`";
				$lines[] = "\n**Claude Code:**";
				$lines[] = "`ssh root@{$ip} php {$path}`";
				$lines[] = "\n**HTTP API (bots/integrations):**";
				$lines[] = "  Catalog: `GET https://{$ip}/admin/ajax.php?module=frogman&command=catalog`";
				$lines[] = "  Execute: `POST https://{$ip}/admin/ajax.php?module=frogman&command=tool`";
				$lines[] = "  Header: `X-Frogman-Token: <token>`";
				$lines[] = "  Body: `{\"tool\":\"oc_list_extensions\",\"params\":{}}`";
				$lines[] = "\nGenerate a token: `create token for mybot with read`";
				return implode("\n", $lines);

			case 'fm_create_api_token':
				$lines = ["🔑 **{$data['message']}**"];
				if (!empty($data['token'])) {
					$lines[] = "\n**Token:** `{$data['token']}`";
					$lines[] = "**Level:** {$data['level']}";
					$lines[] = "\n⚠️ Save this token now — it cannot be retrieved again.";
					$lines[] = "Use header: `X-Frogman-Token`";
				}
				return implode("\n", $lines);

			case 'fm_list_api_tokens':
				if (empty($data['tokens'])) return "No API tokens created.";
				$lines = ["🔑 **API Tokens** ({$data['count']}):"];
				foreach ($data['tokens'] as $t) {
					$icon = $t['status'] === 'active' ? '🟢' : '🔴';
					if ($t['status'] === 'active') {
						$actions = " — {{cmd:revoke token {$t['id']}|revoke}}";
					} else {
						$actions = " — {{cmd:delete token {$t['id']}|delete}}";
					}
					$lines[] = "  {$icon} #{$t['id']} `{$t['token_preview']}` — **{$t['username']}** ({$t['level']}) {$t['created_at_human']}{$actions}";
				}
				return implode("\n", $lines);

			case 'fm_export':
				if (empty($data['file']) && empty($data['url'])) {
					return "📥 No data to export for **{$data['type']}**.";
				}
				return "📥 **Export ready:** {{download:{$data['url']}|{$data['filename']}}} ({$data['count']} rows)";

			case 'fm_list_callbacks':
				if (empty($data['callbacks'])) return "No callbacks configured.";
				$lines = ["**Callbacks** ({$data['count']}):"];
				foreach ($data['callbacks'] as $cb) {
					$num = $cb['number'] ? " → `{$cb['number']}`" : '';
					$lines[] = "  `{$cb['id']}` — {$cb['description']}{$num}";
				}
				return implode("\n", $lines);

			case 'fm_list_cid_lookup':
				if (empty($data['sources'])) return "No CID lookup sources configured.";
				$lines = ["**CID Lookup Sources** ({$data['count']}):"];
				foreach ($data['sources'] as $s) {
					$lines[] = "  `{$s['id']}` — {$s['description']} ({$s['sourcetype']})";
				}
				return implode("\n", $lines);

			case 'fm_get_call_waiting':
				$status = $data['call_waiting'] ?? 'unknown';
				$icon = $status === 'enabled' ? '🟢' : '⚪';
				return "{$icon} **Call Waiting on {$data['extension']}:** {$status}";

			case 'fm_list_recording_rules':
				if (empty($data['rules'])) return "No call recording rules configured.";
				$lines = ["**Call Recording Rules** ({$data['count']}):"];
				foreach ($data['rules'] as $r) {
					$lines[] = "  `{$r['id']}` — {$r['description']} ({$r['mode']})";
				}
				return implode("\n", $lines);

			case 'fm_list_calendars':
				if (empty($data['calendars'])) return "No calendars configured.";
				$lines = ["**Calendars** ({$data['count']}):"];
				foreach ($data['calendars'] as $c) {
					$desc = !empty($c['description']) ? " — {$c['description']}" : '';
					$lines[] = "  📅 `{$c['id']}` — **{$c['name']}** ({$c['type']}){$desc}";
				}
				return implode("\n", $lines);

			case 'fm_get_announcement':
				$desc = $data['description'] ?? '';
				$id = $data['announcement_id'] ?? $data['id'] ?? '?';
				$recording = $data['recording_id'] ?? 'none';
				$dest = $data['post_dest'] ?? $data['dest'] ?? 'none';
				$lines = ["**Announcement {$id}** — {$desc}"];
				$lines[] = "  🔊 Recording: `{$recording}`";
				$lines[] = "  ➡️ After: {$dest}";
				return implode("\n", $lines);

			case 'fm_list_allowlist':
				if (empty($data['allowlist'])) return "Allowlist is empty.";
				$lines = ["**Allowlist** ({$data['count']}):"];
				foreach ($data['allowlist'] as $a) {
					$desc = !empty($a['description']) ? " — {$a['description']}" : '';
					$lines[] = "  ✅ `{$a['number']}`{$desc}";
				}
				return implode("\n", $lines);

			case 'fm_list_contacts':
				if (!empty($data['contacts'])) {
					$lines = ["**Contacts in Group {$data['group_id']}** ({$data['count']}):"];
					foreach ($data['contacts'] as $c) {
						$nums = !empty($c['numbers']) ? ' — ' . implode(', ', $c['numbers']) : '';
						$company = !empty($c['company']) ? " ({$c['company']})" : '';
						$lines[] = "  👤 **{$c['name']}**{$company}{$nums}";
					}
					return implode("\n", $lines);
				}
				if (empty($data['groups'])) return "No contact groups found.";
				$lines = ["**Contact Groups** ({$data['count']}):"];
				foreach ($data['groups'] as $g) {
					$lines[] = "  📇 {{cmd:show contacts in group {$g['id']}|{$g['name']}}} ({$g['type']}, {$g['entries']} entries)";
				}
				return implode("\n", $lines);

			case 'fm_list_speed_dials':
				if (empty($data['speed_dials'])) return "No speed dials configured.";
				$lines = ["**Speed Dials** ({$data['count']}):"];
				foreach ($data['speed_dials'] as $d) {
					$lines[] = "  ⚡ `{$d['code']}` — {$d['name']} → {$d['number']}";
				}
				return implode("\n", $lines);

			case 'fm_get_route_patterns':
				$name = $data['name'] ?? '?';
				$patterns = $data['patterns'] ?? [];
				$trunks = $data['trunks'] ?? [];
				$lines = ["**Outbound Route {$data['route_id']}** — {$name}"];
				if (!empty($trunks)) {
					$lines[] = "\n**Trunks** (" . count($trunks) . "):";
					foreach ($trunks as $t) {
						$lines[] = "  📡 " . ($t['trunkid'] ?? $t ?? '');
					}
				}
				if (!empty($patterns)) {
					$lines[] = "\n**Dial Patterns** (" . count($patterns) . "):";
					foreach ($patterns as $p) {
						$prefix = $p['prefix'] ?? '';
						$match = $p['match_pattern_prefix'] ?? '';
						$pattern = $p['match_pattern_pass'] ?? '';
						$prepend = $p['prepend_digits'] ?? '';
						$display = '';
						if ($prefix) $display .= "[{$prefix}]";
						if ($match) $display .= $match;
						$display .= $pattern;
						if ($prepend) $display = "{$prepend}+{$display}";
						$lines[] = "  `{$display}`";
					}
				} else {
					$lines[] = "\n  No dial patterns configured.";
				}
				return implode("\n", $lines);

			case 'fm_list_all_dids':
				if (empty($data['dids'])) return "No DIDs found.";
				$lines = ["**All DIDs** ({$data['count']}):"];
				foreach ($data['dids'] as $d) {
					$ext = $d['extension'] ?: '(unassigned)';
					$desc = !empty($d['description']) ? " — {$d['description']}" : '';
					$dest = !empty($d['destination']) ? " → {$d['destination']}" : '';
					$lines[] = "  {{cmd:show inbound route {$ext}|{$ext}}}{$desc}{$dest}";
				}
				return implode("\n", $lines);

			case 'fm_get_pm2_status':
				if (empty($data['processes'])) return "No PM2 processes running.";
				$lines = ["**Services** ({$data['count']}):"];
				foreach ($data['processes'] as $p) {
					$status = strtolower($p['status']);
					$icon = $status === 'online' ? '🟢' : ($status === 'stopped' ? '⚪' : '🔴');
					$mem = $p['memory_mb'] ? "{$p['memory_mb']}MB" : '';
					$cpu = $p['cpu'] ? "CPU {$p['cpu']}%" : '';
					$restarts = $p['restarts'] > 0 ? "↻{$p['restarts']}" : '';
					$details = implode(' | ', array_filter([$mem, $cpu, $restarts]));
					$details = $details ? " — {$details}" : '';
					$lines[] = "  {$icon} **{$p['name']}** (PID {$p['pid']}){$details}";
				}
				return implode("\n", $lines);

			case 'fm_validate':
				$result = trim($data['result'] ?? '');
				if (stripos($result, 'root user') !== false) {
					return "🛡️ **Security Scan**\n\n  This scan requires root access. Run from CLI:\n  `fwconsole validate`";
				}
				if (stripos($result, 'Error opening') !== false || stripos($result, 'mirror') !== false) {
					return "🛡️ **Security Scan**\n\n  Cannot reach Sangoma mirror to download scanner. Check server network connectivity.";
				}
				if (!empty($data['error'])) {
					return "🔴 **Security Scan Failed**\n\n  {$result}";
				}
				if (!empty($data['passed'])) {
					return "✅ **Security Scan Passed** — no issues found.";
				}
				return "🛡️ **Security Scan Complete**\n\n  {$result}";

			case 'fm_list_notifications':
				// Single notification detail view
				if (!empty($data['single'])) {
					$levelIcons = ['error' => '🔴', 'warning' => '🟡', 'update' => '🔵', 'notice' => '💬', 'critical' => '🚨', 'security' => '🔒'];
					$icon = $levelIcons[$data['level']] ?? '📋';
					$lines = ["{$icon} **{$data['text']}**"];
					if (!empty($data['details'])) {
						$isUpdates = $data['id'] === 'NEWUPDATES';
						foreach (explode("\n", $data['details']) as $detail) {
							$detail = trim($detail);
							if (empty($detail)) continue;
							// Make upgrade lines clickable
							if ($isUpdates && preg_match('/^(\S+)\s+(\S+)\s+\(current:\s+(\S+)\)/', $detail, $um)) {
								$lines[] = "  {{cmd:module status {$um[1]}|{$um[1]}}} v{$um[3]} ⬆️ {{cmd:upgrade module {$um[1]}|v{$um[2]}}}";
							} else {
								$lines[] = "  {$detail}";
							}
						}
						if ($isUpdates) {
							$lines[] = "\n{{cmd:upgrade all modules|⬆️ Upgrade All}}";
						}
					} else {
						$lines[] = "  No additional details.";
					}
					return implode("\n", $lines);
				}

				if (empty($data['notifications'])) return "No notifications. All clear! ✨";
				$levelIcons = [
					'error' => '🔴',
					'warning' => '🟡',
					'update' => '🔵',
					'notice' => '💬',
					'critical' => '🚨',
					'security' => '🔒',
				];
				$lines = ["**Notifications** ({$data['count']}):"];
				foreach ($data['notifications'] as $n) {
					$icon = $levelIcons[$n['level']] ?? '📋';
					$text = $n['text'];
					$action = '';
					if ($n['id'] === 'NEWUPDATES') {
						$action = " — {{cmd:list modules|view}} | {{cmd:upgrade all modules|upgrade all}}";
					}
					// Make clickable to expand if details exist
					if (!empty($n['details']) && !empty($n['id'])) {
						$lines[] = "  {$icon} {{cmd:show notification {$n['id']}|{$text}}}{$action}";
					} else {
						$lines[] = "  {$icon} {$text}{$action}";
					}
				}
				return implode("\n", $lines);

			case 'fm_get_external_ip':
				$ip = trim($data['output'] ?? $data['ip'] ?? 'unknown');
				return "**External IP:** `{$ip}`";

			case 'fm_extension_states':
				if (empty($data['extensions'])) return "No extensions found.";
				$lines = ["**Extension States** ({$data['count']}):"];
				foreach ($data['extensions'] as $e) {
					$icon = match($e['state']) {
						'Available' => '🟢',
						'On a call' => '🔴',
						'Ringing' => '🟡',
						'Busy' => '🔴',
						'On Hold' => '🟠',
						default => '⚪',
					};
					$lines[] = "  {$icon} {{cmd:diagnose ext {$e['ext']}|{$e['ext']}}} — {$e['state']}";
				}
				return implode("\n", $lines);

			case 'fm_list_certificates':
				if (empty($data['certificates'])) return "No certificates found.";
				$lines = ["**Certificates** ({$data['count']}):"];
				foreach ($data['certificates'] as $c) {
					$def = $c['default'] ?? '';
					$ca = !empty($c['ca']) ? " (CA: {$c['ca']})" : '';
					$lines[] = "  `{$c['name']}` — {$c['description']} | {$c['type']}{$def}{$ca}";
				}
				if (!empty($data['ca_count'])) {
					$lines[] = "  Certificate Authorities: {$data['ca_count']}";
				}
				return implode("\n", $lines);

			case 'fm_sc_status':
				if (empty($data['installed'])) {
					return "🔴 **Sangoma Connect:** not installed.";
				}
				$lic = $data['license'];
				$dom = $data['domain'];
				$crt = $data['cert'];
				$usr = $data['users'];
				$lines = ["**Sangoma Connect:**"];

				$licOk = $lic['licensed'] && !$lic['expired'];
				if (!$licOk) {
					$exp = $lic['expires'] ? " (expired {$lic['expires']})" : '';
					$lines[] = "  🔴 License: invalid{$exp}";
				} else {
					$lines[] = "  🟢 License: valid until {$lic['expires']}";
				}

				$domVal = $dom['domain'] !== '' ? $dom['domain'] : '(none)';
				if ($dom['status']) {
					$lines[] = "  🟢 Domain: `{$domVal}` — running | proxy: {$dom['proxy_status']}";
				} elseif ($dom['domain'] !== '') {
					$lines[] = "  🟡 Domain: `{$domVal}` — not running | proxy: {$dom['proxy_status']}";
				} else {
					$lines[] = "  🔴 Domain: not configured | proxy: {$dom['proxy_status']}";
				}

				if ($crt['exists']) {
					$certTypeLabels = ['ss' => 'Self-Signed', 'le' => "Let's Encrypt", 'up' => 'Uploaded', 'csr' => 'CSR'];
					$certType = $certTypeLabels[$crt['type'] ?? ''] ?? ($crt['type'] ?: 'Unknown');
					$lines[] = "  🟢 Cert: `{$crt['basename']}` ({$certType})";
				} else {
					$lines[] = "  🔴 Cert: none — required for SCD/Talk";
				}

				$cap = (int)$lic['seat_cap'];
				$used = (int)$usr['distinct_users'];
				$free = (int)$usr['free_seats'];
				if ($cap <= 0) {
					$seatIcon = '🔴'; $seatText = 'no seats licensed';
				} elseif ($free <= 0) {
					$seatIcon = '🔴'; $seatText = "{$used}/{$cap} seats — exhausted";
				} elseif ($free <= 1) {
					$seatIcon = '🟡'; $seatText = "{$used}/{$cap} seats ({$free} free)";
				} else {
					$seatIcon = '🟢'; $seatText = "{$used}/{$cap} seats ({$free} free)";
				}
				$lines[] = "  {$seatIcon} Users: {$usr['scd_count']} SCD, {$usr['talk_count']} Talk — {$seatText}";

				$nextMap = [
					'license_invalid'    => ['🔴', "License is invalid or expired. [Purchase Sangoma Connect seats](https://portal.sangoma.com/store/searchStore?search=softphones) to enable users."],
					'cert_required'      => ['🔴', "Need a TLS cert. {{cmd:list certificates|See existing}} or issue one in Certificate Manager."],
					'domain_not_running' => ['🟡', "Cert OK but SC domain isn't running. Bring it up before enabling users."],
					'license_exhausted'  => ['🟡', "All seats used. [Add more seats](https://portal.sangoma.com/store/searchStore?search=softphones) or remove a user before enabling more."],
					'ready'              => ['🟢', "**Ready to enable users.**"],
					'module_missing'     => ['🔴', "Module not installed."],
				];
				if (isset($nextMap[$data['next_step']])) {
					[$icon, $text] = $nextMap[$data['next_step']];
					$lines[] = "  {$icon} → {$text}";
				}
				return implode("\n", $lines);

			case 'fm_set_extension_email':
				if (!empty($data['error'])) {
					return "🔴 **Set email:** {$data['error']}";
				}
				$icon = !empty($data['success']) ? '🟢' : '🟡';
				$lines = ["{$icon} **{$data['message']}**"];
				if (!empty($data['previous_email']) && $data['previous_email'] !== ($data['email'] ?? '')) {
					$lines[] = "  Previous: `{$data['previous_email']}`";
				}
				return implode("\n", $lines);

			case 'fm_whos_on_the_phone':
				if (empty($data['calls'])) return "📞 Nobody is on the phone right now.";
				$lines = ["📞 **On the Phone** ({$data['count']}):"];
				foreach ($data['calls'] as $c) {
					$who = $c['name'] ?? $c['ext'] ?? $c['callerid'] ?? 'Unknown';
					$dur = $c['duration'] > 0 ? gmdate('i:s', $c['duration']) : '0:00';
					if (!empty($c['talking_to'])) {
						$partner = $c['talking_to']['name'] ?? $c['talking_to']['ext'] ?? $c['talking_to']['callerid'] ?? 'Unknown';
						$lines[] = "  🔴 **{$who}** ↔ **{$partner}** ({$dur})";
					} else {
						$lines[] = "  🟡 **{$who}** — {$c['state']} ({$dur})";
					}
				}
				return implode("\n", $lines);

			case 'fm_get_disk_space':
				$lines = ["💾 **Disk Space:**"];
				foreach ($data as $dev => $info) {
					if (!is_array($info)) continue;
					$pct = (int)($info['usepct'] ?? 0);
					$icon = $pct > 90 ? '🔴' : ($pct > 75 ? '🟡' : '🟢');
					$lines[] = "  {$icon} `{$info['mountpoint']}` — {$info['used']}/{$info['size']} ({$info['usepct']}) — {$info['avail']} free";
				}
				return implode("\n", $lines);

			case 'fm_system_dashboard':
				$lines = ["📊 **System Status**"];
				if (!empty($data['version'])) $lines[] = "  📦 Asterisk `{$data['version']}`";
				if (!empty($data['uptime'])) {
					$up = preg_replace('/System uptime:\s*/', '', $data['uptime']);
					$up = preg_replace('/\s*Last reload:.*/', '', $up);
					$lines[] = "  ⏱️ Uptime: {$up}";
				}
				$callIcon = $data['active_calls'] > 0 ? '🔴' : '📞';
				if ($data['active_calls'] > 0) {
					$lines[] = "  {$callIcon} Active Calls: {{cmd:who's on the phone|**{$data['active_calls']}** — see who's on}}";
				} else {
					$lines[] = "  {$callIcon} Active Calls: **0**";
				}
				$lines[] = "  📱 Extensions: {{cmd:registrations|{$data['registered']}/{$data['extensions']} registered}}";
				if ($data['trunks'] > 0) $lines[] = "  📡 Trunks: {{cmd:list trunks|{$data['trunks']}}}";
				// Notifications summary
				$notifParts = [];
				if ($data['errors'] > 0) $notifParts[] = "🔴 {$data['errors']} errors";
				if ($data['warnings'] > 0) $notifParts[] = "🟡 {$data['warnings']} warnings";
				if ($data['updates'] > 0) $notifParts[] = "🔵 {$data['updates']} updates";
				if (!empty($notifParts)) {
					$lines[] = "  📋 Notifications: " . implode(', ', $notifParts) . " — {{cmd:list notifications|view}}";
				} else {
					$lines[] = "  ✅ No alerts";
				}
				if ($data['need_reload']) {
					$lines[] = "  ⚠️ {{cmd:reload|Config changes pending — apply now}}";
				}
				return implode("\n", $lines);

			case 'fm_search':
				if (empty($data['results'])) return "No results for **{$data['query']}**.";
				$lines = ["🔍 **Search: {$data['query']}** ({$data['count']} results):"];
				$cmdMap = [
					'Extension' => 'show extension',
					'Ring Group' => 'show ringgroup',
					'Queue' => 'show queue',
					'IVR' => 'show ivr',
					'Trunk' => 'show trunk',
				];
				foreach ($data['results'] as $r) {
					$cmd = $cmdMap[$r['type']] ?? null;
					$idDisplay = $cmd ? "{{cmd:{$cmd} {$r['id']}|{$r['id']}}}" : "`{$r['id']}`";
					$lines[] = "  {$r['type']}: {$idDisplay} — {$r['name']}";
				}
				return implode("\n", $lines);

			case 'fm_trace_call_flow':
				if (!empty($data['error'])) {
					return "📞 **Call Flow Trace**\n\n  {$data['error']}";
				}
				$nodes = $data['nodes'] ?? [];
				$edges = $data['edges'] ?? [];
				if (empty($nodes)) return "📞 No call flow data found.";

				// Build Mermaid diagram
				$typeStyles = [
					'did' => ':::did',
					'route' => ':::route',
					'extension' => ':::ext',
					'ringgroup' => ':::rg',
					'ivr' => ':::ivr',
					'timecondition' => ':::tc',
					'queue' => ':::queue',
					'voicemail' => ':::vm',
					'followme' => ':::fm',
					'forward' => ':::fwd',
					'announcement' => ':::ann',
					'terminate' => ':::term',
				];
				$typeShapes = [
					'did' => ['([', '])'],
					'extension' => ['[', ']'],
					'ringgroup' => ['[[', ']]'],
					'ivr' => ['{', '}'],
					'timecondition' => ['{{', '}}'],
					'queue' => ['[/', '/]'],
					'voicemail' => ['[(', ')]'],
					'terminate' => ['(', ')'],
				];

				$mermaid = "graph TD\n";
				foreach ($nodes as $n) {
					$shape = $typeShapes[$n['type']] ?? ['[', ']'];
					$label = str_replace('"', "'", $n['label']);
					$mermaid .= "    {$n['id']}{$shape[0]}\"{$label}\"{$shape[1]}\n";
				}
				foreach ($edges as $e) {
					if (!empty($e['label'])) {
						$mermaid .= "    {$e['from']} -->|{$e['label']}| {$e['to']}\n";
					} else {
						$mermaid .= "    {$e['from']} --> {$e['to']}\n";
					}
				}
				// Style classes
				$mermaid .= "    classDef did fill:#009d9d,stroke:#0f5a59,color:#fff\n";
				$mermaid .= "    classDef route fill:#d0ebeb,stroke:#0f5a59,color:#0f5a59\n";
				$mermaid .= "    classDef ext fill:#fff,stroke:#009d9d,color:#0f5a59\n";
				$mermaid .= "    classDef rg fill:#e8f4f4,stroke:#009d9d,color:#0f5a59\n";
				$mermaid .= "    classDef ivr fill:#f3a25e,stroke:#c77a30,color:#fff\n";
				$mermaid .= "    classDef tc fill:#ffd700,stroke:#b8960f,color:#333\n";
				$mermaid .= "    classDef queue fill:#b8dede,stroke:#0f5a59,color:#0f5a59\n";
				$mermaid .= "    classDef vm fill:#88b0b0,stroke:#0f5a59,color:#fff\n";
				$mermaid .= "    classDef term fill:#cb3429,stroke:#8b1a12,color:#fff\n";
				// Apply styles and click handlers
				$clickMap = [
					'extension' => 'show extension',
					'ringgroup' => 'show ringgroup',
					'ivr' => 'show ivr',
					'queue' => 'show queue',
					'voicemail' => 'show voicemail for',
					'did' => 'show inbound route',
				];
				// Store click data for JS to read, and use Mermaid href for clickability
				$clickData = [];
				foreach ($nodes as $n) {
					$cls = $typeStyles[$n['type']] ?? '';
					if ($cls) {
						$mermaid .= "    class {$n['id']} " . str_replace(':::', '', $cls) . "\n";
					}
					if (isset($clickMap[$n['type']])) {
						$clickId = preg_replace('/[^0-9]/', '', $n['label']);
						if (!empty($clickId)) {
							$cmd = $clickMap[$n['type']] . ' ' . $clickId;
							$mermaid .= "    click {$n['id']} \"#oc-cmd:{$cmd}\"\n";
							$clickData[$n['id']] = $cmd;
						}
					}
				}

				return "📞 **Call Flow Trace**\n\n```mermaid\n{$mermaid}```";

			case 'fm_search_docs':
				if (empty($data['results'])) return "📚 No articles found for **{$data['query']}**. Try different keywords.";
				$lines = ["📚 **Knowledge Base: {$data['query']}** ({$data['count']} articles):"];
				foreach ($data['results'] as $r) {
					$lines[] = "\n📄 **{$r['title']}**";
					if (!empty($r['sections'])) {
						foreach ($r['sections'] as $s) {
							// Trim content to first few meaningful lines
							$preview = $s['content'];
							$previewLines = explode("\n", $preview);
							$shown = [];
							foreach ($previewLines as $pl) {
								$pl = trim($pl);
								if (!empty($pl) && strpos($pl, '```') !== 0) {
									$shown[] = "  {$pl}";
									if (count($shown) >= 3) break;
								}
							}
							if (!empty($shown)) {
								$lines[] = "  **{$s['title']}**";
								$lines = array_merge($lines, $shown);
							}
						}
					} elseif (!empty($r['matched_lines'])) {
						foreach ($r['matched_lines'] as $ml) {
							$lines[] = "  {$ml}";
						}
					}
				}
				return implode("\n", $lines);

			case 'fm_pjsip_registrations':
				$inbound = preg_replace('/Privilege:\s+\w+\s*/i', '', $data['inbound'] ?? '');
				$outbound = preg_replace('/Privilege:\s+\w+\s*/i', '', $data['outbound'] ?? '');
				$inbound = trim($inbound);
				$outbound = trim($outbound);
				$noIn = empty($inbound) || stripos($inbound, 'No objects') !== false;
				$noOut = empty($outbound) || stripos($outbound, 'No objects') !== false;
				$lines = ["**SIP Registrations:**"];
				$lines[] = "  📱 Inbound (phones): " . ($noIn ? 'None registered' : $inbound);
				$lines[] = "  📡 Outbound (trunks): " . ($noOut ? 'None registered' : $outbound);
				return implode("\n", $lines);

			case 'fm_pjsip_qualify':
				$ep = $data['endpoint'] ?? '?';
				$msg = $data['result']['Message'] ?? 'No response';
				return "📡 **Qualify {$ep}:** {$msg}";

			case 'fm_queue_status':
				$r = $data['result'] ?? [];
				if (!empty($r['Message'])) {
					return "**Queue Status:** {$r['Message']}";
				}
				return "**Queue Status:** No data available.";

			case 'fm_list_permissions':
				if (empty($data['permissions'])) return "No permissions configured. All users have default read access.";
				$lines = ["**Permissions** ({$data['count']}):"];
				foreach ($data['permissions'] as $p) {
					$icon = $p['level'] === 'admin' ? '👑' : ($p['level'] === 'write' ? '✏️' : '👁️');
					$lines[] = "  {$icon} `{$p['username']}` — **{$p['level']}**";
				}
				return implode("\n", $lines);

			case 'fm_list_sounds':
				$raw = $data['output'] ?? '';
				$raw = preg_replace('/Privilege:\s+\w+\s*/i', '', $raw);
				// Parse table rows
				$packs = [];
				foreach (explode("\n", $raw) as $line) {
					if (preg_match('/\|\s*(\S+)\s*\|\s*(.+?)\s*\|\s*(.+?)\s*\|\s*(\S*)\s*\|/', $line, $m)) {
						if ($m[1] === 'ID' || strpos($m[1], '-') === 0) continue;
						$installed = !empty(trim($m[4]));
						$packs[] = ['id' => $m[1], 'language' => trim($m[2]), 'installed' => $installed];
					}
				}
				if (empty($packs)) return "No sound packs found.";
				$installed = array_filter($packs, function($p) { return $p['installed']; });
				$available = array_filter($packs, function($p) { return !$p['installed']; });
				$lines = ["**Sound Packs** (" . count($installed) . " installed, " . count($available) . " available):"];
				if (!empty($installed)) {
					$lines[] = "\n**Installed:**";
					foreach ($installed as $p) {
						$lines[] = "  🔊 `{$p['id']}` — {$p['language']}";
					}
				}
				if (!empty($available)) {
					$lines[] = "\n**Available:**";
					foreach ($available as $p) {
						$lines[] = "  ⚪ `{$p['id']}` — {$p['language']}";
					}
				}
				return implode("\n", $lines);

			default:
				return $this->formatGenericResult($data, $result['auditId']);
		}
	}

	/**
	 * Smart generic formatter — handles common tool return shapes so every tool
	 * gets readable output without needing a dedicated case.
	 */
	/**
	 * Suggest related follow-up actions after a tool completes successfully.
	 */
	/**
	 * Return a follow-up offer after a tool completes.
	 * Returns ['tool' => ..., 'params' => ..., 'question' => ...] or null.
	 */
	private function recordingModeChips($ext) {
		$ext = $ext ? (string)$ext : '';
		$prefix = "set recording on {$ext} to ";
		$modes = [
			'force'    => 'Force',
			'yes'      => 'Yes',
			'dontcare' => "Don't Care",
			'no'       => 'No',
			'never'    => 'Never',
		];
		$chips = [];
		foreach ($modes as $val => $label) {
			$chips[] = '{{cmd:' . $prefix . $val . '|' . $label . '}}';
		}
		return implode(' &nbsp;|&nbsp; ', $chips);
	}

	public function getFollowUpOffer($toolName, $result, $params) {
		if ($result['status'] !== 'success') return null;
		$data = $result['data'] ?? [];
		if (!empty($data['dry_run'])) return null;

		switch ($toolName) {
			case 'fm_add_extension':
				$ext = $data['extension'] ?? $params['ext'] ?? '';
				// Chain: if combo params were passed, offer those first
				if (!empty($params['_chain_forward'])) {
					return [
						'tool' => 'fm_set_call_forward',
						'params' => ['ext' => $ext, 'number' => $params['_chain_forward']],
						'question' => "Would you also like to forward {$ext} to {$params['_chain_forward']}?",
					];
				}
				if (!empty($params['_chain_ringgroup'])) {
					return [
						'tool' => 'fm_ringgroup_add_member',
						'params' => ['id' => $params['_chain_ringgroup'], 'member' => $ext],
						'question' => "Would you also like to add {$ext} to ring group {$params['_chain_ringgroup']}?",
					];
				}
				// Default: offer voicemail (unless already enabled via vm:yes)
				if (!empty($params['vm']) && $params['vm'] === 'yes') {
					return [
						'tool' => 'fm_reload',
						'params' => [],
						'question' => "Would you like to apply the changes now?",
					];
				}
				return [
					'tool' => 'fm_enable_voicemail',
					'params' => ['ext' => $ext],
					'question' => "Would you also like to enable voicemail for {$ext}?",
				];

			case 'fm_enable_voicemail':
				$vmExt = $params['ext'] ?? $data['ext'] ?? null;
				if ($vmExt) {
					$umUser = null;
					try { $umUser = $this->freepbx->Userman->getUserByDefaultExtension($vmExt); } catch (\Throwable $e) {}
					$hasEmail = is_array($umUser) && !empty($umUser['email']);
					if (!$hasEmail) {
						return [
							'tool' => 'fm_set_extension_email',
							'params' => ['ext' => $vmExt, '_chain_reload' => true],
							'question' => "Would you also like to add an email for {$vmExt}?",
							'needs_input' => 'email',
							'input_prompt' => "What email address? {{cmd:skip|⏭ Skip}}",
						];
					}
				}
				return [
					'tool' => 'fm_reload',
					'params' => [],
					'question' => "Would you like to apply the changes now?",
				];

			case 'fm_set_extension_email':
				if (!empty($params['_chain_reload'])) {
					return [
						'tool' => 'fm_reload',
						'params' => [],
						'question' => "Would you like to apply the changes now?",
					];
				}
				return null;

			case 'fm_add_ringgroup':
			case 'fm_add_inbound_route':
			case 'fm_dialplan_apply':
			case 'fm_disable_extension':
				return [
					'tool' => 'fm_reload',
					'params' => [],
					'question' => "Would you like to apply the changes now?",
				];

			case 'fm_reload':
				return null;

			default:
				if (!empty($data['needs_reload'])) {
					return [
						'tool' => 'fm_reload',
						'params' => [],
						'question' => "Would you like to apply the changes now?",
					];
				}
				return null;
		}
	}

	private function formatGenericResult($data, $auditId) {
		if (empty($data)) return "Done. (audit #{$auditId})";

		$lines = [];

		// 1. If there's a message field, lead with it
		if (!empty($data['message'])) {
			$lines[] = $data['message'];
		}

		// 2. If there's output (fwconsole-style tools), show it in a code block
		if (!empty($data['output'])) {
			$output = $data['output'];
			// Strip ANSI escape codes and "Privilege: Command\n" prefix
			$output = preg_replace('/\x1B\[[0-9;]*[a-zA-Z]/', '', $output);
			$output = preg_replace('/^Privilege:\s+\w+\n+/i', '', $output);
			$output = trim($output);
			if (!empty($output)) {
				// Cap at 2000 chars to avoid flooding chat
				if (strlen($output) > 2000) {
					$output = substr($output, 0, 2000) . "\n... [truncated]";
				}
				$lines[] = "```\n{$output}\n```";
			}
		}

		// 3. If there's a count + a list array, format as a list
		$listKeys = ['notifications', 'permissions', 'backups', 'certificates',
			'managed_cas', 'filestores', 'saved_queries', 'sounds', 'timegroups',
			'states', 'entries', 'members', 'agents', 'participants', 'processes'];
		foreach ($listKeys as $key) {
			if (isset($data[$key]) && is_array($data[$key])) {
				$labelMap = ['managed_cas' => 'Certificates'];
				$label = $labelMap[$key] ?? ucfirst(str_replace('_', ' ', $key));
				$count = $data['count'] ?? count($data[$key]);
				if (empty($data[$key])) {
					$lines[] = "No {$key} found.";
				} else {
					$lines[] = "**{$label}** ({$count}):";
					foreach (array_slice($data[$key], 0, 25) as $item) {
						if (is_string($item)) {
							$lines[] = "  {$item}";
						} elseif (is_array($item)) {
							// Build a summary from the first few meaningful fields
							$summary = $this->summarizeItem($item);
							$lines[] = "  {$summary}";
						}
					}
					if ($count > 25) {
						$lines[] = "  ... and " . ($count - 25) . " more";
					}
				}
				break; // only format the first list found
			}
		}

		// 3b. Handle key-value map results (e.g. settings)
		if (empty($lines) && isset($data['settings']) && is_array($data['settings'])) {
			$count = count($data['settings']);
			$lines[] = "**Settings** ({$count}):";
			foreach (array_slice($data['settings'], 0, 30, true) as $k => $v) {
				$val = is_bool($v) ? ($v ? 'true' : 'false') : $v;
				if (is_string($val) && strlen($val) > 80) $val = substr($val, 0, 80) . '...';
				$lines[] = "  `{$k}` = {$val}";
			}
			if ($count > 30) {
				$lines[] = "  ... and " . ($count - 30) . " more";
			}
		}

		// 4. For key-value results without output or lists, show fields
		if (empty($lines)) {
			$skip = ['dry_run', 'confirm'];
			foreach ($data as $k => $v) {
				if (in_array($k, $skip)) continue;
				if (is_scalar($v)) {
					$label = ucfirst(str_replace('_', ' ', $k));
					$lines[] = "  {$label}: {$v}";
				}
			}
		}

		if (empty($lines)) {
			return "Done. (audit #{$auditId})";
		}

		return implode("\n", $lines);
	}

	/**
	 * Summarize an array item into a readable one-liner.
	 */
	private function summarizeItem($item) {
		// Try common field combos
		$id = $item['id'] ?? $item['extension'] ?? $item['name'] ?? $item['number'] ?? null;
		$label = $item['text'] ?? $item['description'] ?? $item['name'] ?? $item['label'] ?? $item['module'] ?? null;
		$level = $item['level'] ?? $item['status'] ?? $item['state'] ?? null;

		$parts = [];
		if ($id !== null && $id !== $label) $parts[] = "`{$id}`";
		if ($level !== null) $parts[] = "[{$level}]";
		if ($label !== null) $parts[] = $label;

		if (!empty($parts)) return implode(' ', $parts);

		// Fallback: first 3 scalar values
		$scalars = [];
		foreach ($item as $k => $v) {
			if (is_scalar($v)) $scalars[] = "{$k}: {$v}";
			if (count($scalars) >= 3) break;
		}
		return implode(' | ', $scalars);
	}

	private function handleDownload() {
		$file = isset($_REQUEST['file']) ? basename($_REQUEST['file']) : '';
		if (empty($file) || !preg_match('/^frogman-.*\.csv$/', $file)) {
			http_response_code(400);
			echo 'Invalid file';
			return;
		}
		$path = __DIR__ . '/assets/exports/' . $file;
		if (!file_exists($path)) {
			http_response_code(404);
			echo 'File not found';
			return;
		}
		header('Content-Type: text/csv');
		header('Content-Disposition: attachment; filename="' . $file . '"');
		header('Content-Length: ' . filesize($path));
		readfile($path);
	}

	private function handleAuditFeed() {
		$sth = $this->db->query("SELECT id, tool, status, created_at FROM oc_audit_log WHERE tool != 'fm_audit_search' ORDER BY created_at DESC LIMIT 5");
		$rows = $sth->fetchAll(\PDO::FETCH_ASSOC);
		foreach ($rows as &$row) {
			$row['time'] = date('H:i:s', $row['created_at']);
		}
		unset($row);
		return ['entries' => $rows];
	}

	private function handleCatalog() {
		$tools = $this->getToolList();
		$modInfo = \FreePBX::Modules()->getInfo('frogman');
		$version = $modInfo['frogman']['version'] ?? 'unknown';
		return [
			'status' => 'success',
			'module' => 'frogman',
			'version' => $version,
			'tool_count' => count($tools),
			'tools' => $tools,
		];
	}

	public function getRightNav($request) {
		return '';
	}

	public function showPage() {
		$toolCount = count($this->getToolList());
		$auditCount = $this->getAuditCount();
		$modInfo = \FreePBX::Modules()->getInfo('frogman');
		$moduleVersion = $modInfo['frogman']['version'] ?? '';
		return load_view(__DIR__ . '/views/main.php', [
			'action' => isset($_REQUEST['action']) ? $_REQUEST['action'] : '',
			'toolCount' => $toolCount,
			'auditCount' => $auditCount,
			'moduleVersion' => $moduleVersion,
		]);
	}

	// ── Audit Log ──────────────────────────────────────────────

	public function auditIntent($tool, $params, $userId = null, $sessionId = null) {
		$sql = "INSERT INTO oc_audit_log (tool, params, user_id, session_id, intent, status, created_at)
		        VALUES (?, ?, ?, ?, ?, 'pending', ?)";
		$sth = $this->db->prepare($sql);
		$sth->execute([$tool, json_encode($params), $userId, $sessionId, "Execute {$tool}", time()]);
		return (int) $this->db->lastInsertId();
	}

	public function auditOutcome($auditId, $status, $detail = null) {
		$sql = "UPDATE oc_audit_log SET status = ?, detail = ?, completed_at = ? WHERE id = ?";
		$sth = $this->db->prepare($sql);
		$sth->execute([$status, is_string($detail) ? $detail : json_encode($detail), time(), $auditId]);
	}

	public function getAuditCount() {
		$sth = $this->db->query("SELECT COUNT(*) FROM oc_audit_log");
		return (int) $sth->fetchColumn();
	}

	// ── Tool Registry ──────────────────────────────────────────

	/**
	 * Get the Frogman permission level for a username.
	 * Returns 'read', 'write', or 'admin'. Default is 'read' for unknown users.
	 */
	private function getUserPermissionLevel($username) {
		// Check oc_permissions table first
		$sth = $this->db->prepare("SELECT level FROM oc_permissions WHERE username = ?");
		$sth->execute([$username]);
		$row = $sth->fetch(\PDO::FETCH_ASSOC);
		if ($row) return $row['level'];

		// Fallback: FreePBX admins with all sections (*) get admin level
		$ampuser = new \ampuser($username);
		$sections = $ampuser->getSections();
		if (is_array($sections) && in_array('*', $sections)) {
			return 'admin';
		}

		return 'read';
	}

	/**
	 * Extract username from session ID (format: "username-sessionhash" or "discord-channelid").
	 */
	private function getSessionUsername($sessionId) {
		if (!$sessionId) return null;
		// Web sessions: "adminmike-abc123..."
		if (preg_match('/^([a-zA-Z0-9_]+)-[a-z0-9]{10,}$/', $sessionId, $m)) {
			return $m[1];
		}
		// Check if the session ID itself is stored with a username
		$sth = $this->db->prepare("SELECT user_id FROM oc_sessions WHERE id = ?");
		$sth->execute([$sessionId]);
		$row = $sth->fetch(\PDO::FETCH_ASSOC);
		return $row && $row['user_id'] ? $row['user_id'] : null;
	}

	private function loadTools() {
		if ($this->toolsLoaded) {
			return;
		}
		$dir = __DIR__ . '/Tools';
		if (!is_dir($dir)) {
			$this->toolsLoaded = true;
			return;
		}
		foreach (glob($dir . '/*.php') as $file) {
			$basename = basename($file, '.php');
			if ($basename === 'AbstractTool' || $basename === 'ChatParser') {
				continue;
			}
			require_once $file;
			$class = "\\FreePBX\\modules\\Frogman\\Tools\\{$basename}";
			if (class_exists($class)) {
				$tool = new $class($this->freepbx, $this);
				$this->tools[$tool->name()] = $tool;
			}
		}
		$this->toolsLoaded = true;
	}

	public function getToolList() {
		$this->loadTools();
		$list = [];
		foreach ($this->tools as $name => $tool) {
			$list[] = [
				'name' => $name,
				'description' => $tool->description(),
				'permission' => $tool->requiredPermission(),
			];
		}
		return $list;
	}

	public function getTool($name) {
		$this->loadTools();
		return isset($this->tools[$name]) ? $this->tools[$name] : null;
	}

	public function runTool($name, $params, $userId = null, $sessionId = null) {
		$this->loadTools();

		if (!isset($this->tools[$name])) {
			return ['status' => 'error', 'message' => "Unknown tool: {$name}"];
		}

		$tool = $this->tools[$name];

		// FreePBX section check — user must have 'frogman' section in admin permissions
		if (isset($_SESSION['AMP_user']) && is_object($_SESSION['AMP_user'])) {
			if (!$_SESSION['AMP_user']->checkSection('frogman')) {
				return ['status' => 'error', 'message' => 'Access denied: you do not have Frogman permissions in your admin account.'];
			}
		}

		// Frogman permission level check (read/write/admin).
		// Prefer the explicit level from authContext (token's own level, or localhost-trust)
		// over the session-username → oc_permissions resolver, so an admin-labeled token
		// actually grants admin regardless of source IP.
		$requiredLevel = method_exists($tool, 'permissionLevel') ? $tool->permissionLevel() : 'read';
		$userLevel = null;
		if (!empty($this->authContext['level'])) {
			$userLevel = $this->authContext['level'];
		} else {
			$username = $this->getSessionUsername($sessionId);
			if ($username) {
				$userLevel = $this->getUserPermissionLevel($username);
			}
		}
		if ($userLevel) {
			$levelHierarchy = ['read' => 0, 'write' => 1, 'admin' => 2];
			$required = $levelHierarchy[$requiredLevel] ?? 0;
			$granted = $levelHierarchy[$userLevel] ?? 0;
			if ($required > $granted) {
				return [
					'status' => 'error',
					'message' => "Permission denied: this tool requires '{$requiredLevel}' level but you have '{$userLevel}'. Contact an admin to upgrade your access.",
				];
			}
		}

		$validation = $tool->validate($params);
		if ($validation !== true) {
			return ['status' => 'error', 'message' => "Validation failed: {$validation}"];
		}

		$auditId = $this->auditIntent($name, $params, $userId, $sessionId);

		try {
			$result = $tool->execute($params, [
				'userId' => $userId,
				'sessionId' => $sessionId,
				'auditId' => $auditId,
			]);
			$this->auditOutcome($auditId, 'success', $result);
			return ['status' => 'success', 'auditId' => $auditId, 'data' => $result];
		} catch (\Exception $e) {
			$this->auditOutcome($auditId, 'error', $e->getMessage());
			return ['status' => 'error', 'auditId' => $auditId, 'message' => $e->getMessage()];
		}
	}
}
