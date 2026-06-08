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
			// oc_api_tokens.token stores `sha256$<hash>` — never the raw value. The
			// prefix is self-describing and lets install.php run an idempotent migration.
			// See GHSA-9xf5-9ghq-p6cw.
			$tokenStored = 'sha256$' . hash('sha256', $token);
			$sth = $this->db->prepare("SELECT id, username, level, last_used_at FROM oc_api_tokens WHERE token = ? AND active = 1");
			$sth->execute([$tokenStored]);
			$row = $sth->fetch(\PDO::FETCH_ASSOC);
			if ($row) {
				// Stamp last_used_at for the Tokens sidebar (stale-badge + recency column).
				// Throttled at 60s to keep wallboard-style pollers from hammering the row —
				// the dashboard only needs minute-grade freshness, and a write-per-request
				// would be wasted I/O on hot tokens.
				$now = time();
				if ((int)($row['last_used_at'] ?? 0) < $now - 60) {
					$upd = $this->db->prepare("UPDATE oc_api_tokens SET last_used_at = ? WHERE id = ?");
					$upd->execute([$now, (int)$row['id']]);
				}
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

		// v1.6.7 — preserve the chat-origin natural language in the audit row.
		// $message is what the user typed; $parsed['interpreted_as'] is populated
		// by any upstream natural-language normalisation layer that sits between
		// the user and ChatParser (e.g. an Interpret/expand pass) — NULL if no
		// rewrite occurred or the layer isn't installed. Non-chat invocation
		// paths (HTTP API, GraphQL, CLI, MCP) don't pass these args, leaving
		// chat_input + interpreted_as NULL in oc_audit_log for those calls.
		$interpretedAs = isset($parsed['interpreted_as']) ? $parsed['interpreted_as'] : null;
		$result = $this->runTool($toolName, $params, null, $sessionId, $message, $interpretedAs);

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
	 * Sanitize a free-form field value before interpolating it into chat output
	 * that will be wrapped in inline-code backticks. The client's formatMarkdown
	 * applies escapeHtml only INSIDE its capture groups (backticks, bold, links);
	 * prose text between patterns is rendered as raw HTML. Wrapping a user-
	 * controlled value in backticks engages the escape — but a literal backtick
	 * in the value would let the user break out of the inline-code wrapping, so
	 * we strip backticks here. Control chars are also stripped defensively.
	 *
	 * Pattern: $line = "Field `" . $this->sanitizeForChat($value) . "`";
	 *
	 * Background: GHSA-7qvv (v1.6.6) patched escape-on-capture for the known
	 * markdown patterns, but prose-between-patterns in the chat formatter is
	 * still raw-HTML territory. Any new formatter case that interpolates a
	 * free-form field must use this helper + backtick wrapping.
	 */
	public function sanitizeForChat($value) {
		$value = (string)$value;
		// Strip control chars (CRLF, NUL, etc.) — could disrupt rendering or
		// be used to inject markup pieces.
		$value = preg_replace('/[\x00-\x1F\x7F]/', '', $value);
		// Neutralize characters that could break out of inline-code wrapping
		// OR trigger client-side formatMarkdown patterns that user-controlled
		// fields must not invoke:
		//   `   closes inline-code wrapping (XSS via prose interpolation)
		//   {{  triggers {{cmd:...|...}} / {{download:...|...}} clickable
		//       command patterns — UX confusion / indirect command execution
		//       (e.g. an admin renders a chat audit and sees a fake clickable
		//       "harmless click" that actually fires `delete extension 101`)
		//   [   triggers `[text](url)` markdown link pattern — phishing-ish,
		//       embeds a clickable link in admin chat
		return str_replace(['`', '{{', '['], ["'", '{ {', '('], $value);
	}

	private function appendPcapActionSeparator(&$lines) {
		if (!empty($lines) && end($lines) !== '') {
			$lines[] = "";
		}
	}	

	private function appendPcapSummaryActions(&$lines, $item, $section = null, $callRef = null, $path = null, $callId = null, $indent = '  ') {
		if (!is_array($item)) return;
		$id = $item['id'] ?? null;
		if (!is_string($id) || $id === '' || !is_string($section) || $section === '') return;
		if (!is_string($path) || $path === '') return;
		$hasSimplified = !empty($item['simplified']);
		$hasReExplained = !empty($item['re_explained']);
		$hasEvidence = !empty($item['evidence_text']) && is_array($item['evidence_text']);
		if (!$hasSimplified && !$hasReExplained && !$hasEvidence) return;
		if (!preg_match('/^[a-z0-9_]+$/i', $section) || !preg_match('/^[a-z0-9_:-]+$/i', $id)) return;
		$target = "{$section} {$id}";
		if ($callRef !== null && preg_match('/^[a-f0-9]{12}$/i', (string)$callRef)) $target .= " call " . $callRef;
		$target .= " path " . $this->pcapCommandValue($path);
		if (is_string($callId) && $callId !== '') $target .= " call_id " . $this->pcapCommandValue($callId);
		$actions = [];
		if ($hasSimplified) $actions[] = "{{cmd:pcap action simplify {$target}|Simplify}}";
		if ($hasReExplained) $actions[] = "{{cmd:pcap action explain {$target}|Explain}}";
		if ($hasEvidence) $actions[] = "{{cmd:pcap action evidence {$target}|Evidence}}";
		if (!empty($actions)) {
			$this->appendPcapActionSeparator($lines);
			$lines[] = "{$indent}Actions: " . implode(' · ', $actions);
		}
	}

	private function appendPcapSummaryBlockActions(&$lines, $section = null, $callRef = null, $path = null, $callId = null, $indent = '  ') {
		if (!is_string($section) || $section === '' || !preg_match('/^[a-z0-9_]+$/i', $section)) return;
		if (!is_string($path) || $path === '') return;
		$target = "{$section} block";
		if ($callRef !== null && preg_match('/^[a-f0-9]{12}$/i', (string)$callRef)) $target .= " call " . $callRef;
		$target .= " path " . $this->pcapCommandValue($path);
		if (is_string($callId) && $callId !== '') $target .= " call_id " . $this->pcapCommandValue($callId);
		$actions = [
			"{{cmd:pcap action simplify {$target}|Simplify}}",
			"{{cmd:pcap action explain {$target}|Explain}}",
			"{{cmd:pcap action evidence {$target}|Evidence}}",
		];
		$this->appendPcapActionSeparator($lines);
		$lines[] = "{$indent}Actions: " . implode(' · ', $actions);
	}

	private function appendPcapActionViewActions(&$lines, $data, $indent = '') {
		$available = $data['available_actions'] ?? [];
		if (empty($available) || !is_array($available)) return;
		$section = $data['section'] ?? null;
		$id = $data['item_id'] ?? null;
		$path = $data['path'] ?? null;
		if (!is_string($section) || !preg_match('/^[a-z0-9_]+$/i', $section)) return;
		if (!is_string($id) || !preg_match('/^[a-z0-9_:-]+$/i', $id)) return;
		if (!is_string($path) || $path === '') return;

		$target = "{$section} {$id}";
		if (!empty($data['call_ref']) && preg_match('/^[a-f0-9]{12}$/i', (string)$data['call_ref'])) {
			$target .= " call " . strtolower((string)$data['call_ref']);
		} elseif (isset($data['call_index']) && $data['call_index'] !== null && (int)$data['call_index'] >= 0) {
			$target .= " call " . (int)$data['call_index'];
		}
		$target .= " path " . $this->pcapCommandValue($path);
		if (!empty($data['call_id']) && is_string($data['call_id'])) {
			$target .= " call_id " . $this->pcapCommandValue($data['call_id']);
		}

		$commandNames = [
			'simplify' => 'simplify',
			'explain' => 'explain',
			're_explain' => 'explain',
			'evidence' => 'evidence',
			'show_evidence' => 'evidence',
		];
		$actions = [];
		foreach ($available as $action => $label) {
			if (empty($commandNames[$action])) continue;
			$label = $this->sanitizeForChat($label);
			if ($label === '') continue;
			$actions[] = "{{cmd:pcap action {$commandNames[$action]} {$target}|{$label}}}";
		}
		if (!empty($actions)) {
			$this->appendPcapActionSeparator($lines);
			$lines[] = "{$indent}Actions: " . implode(' · ', $actions);
		}
	}

	private function appendPcapActionFocusContext(&$lines, $data) {
		$context = $data['focus_context'] ?? null;
		if (is_array($context)) {
			$label = isset($context['label']) && is_string($context['label']) ? $this->sanitizeForChat($context['label']) : '';
			$friendly = isset($context['friendly']) && is_string($context['friendly']) ? $this->sanitizeForChat($context['friendly']) : '';
			$callId = isset($context['call_id']) && is_string($context['call_id']) ? $this->sanitizeForChat($context['call_id']) : '';
			if ($label !== '') $lines[] = "Focused item: {$label}";
			if ($friendly !== '') $lines[] = "**{$friendly}**";
			if ($callId !== '') $lines[] = "Call-ID: `{$callId}`";
			if ($label !== '' || $friendly !== '' || $callId !== '') return;
		}
		if (($data['section'] ?? '') === 'response' && ($data['item_id'] ?? '') === 'block') {
			$lines[] = "Scope: Full PCAP response";
		}
	}

	private function pcapCallRef($callId) {
		$callId = (string)$callId;
		return $callId === '' ? null : substr(sha1($callId), 0, 12);
	}

	private function formatPcapFocusDuration($durationMs) {
		$durationMs = max(0, (int)$durationMs);
		if ($durationMs < 1000) return $durationMs . 'ms';
		$seconds = $durationMs / 1000;
		if ($seconds < 10) return rtrim(rtrim(number_format($seconds, 2, '.', ''), '0'), '.') . 's';
		return rtrim(rtrim(number_format($seconds, 1, '.', ''), '0'), '.') . 's';
	}

	private function pcapPluralWord($count, $singular, $plural = null) {
		return ((int)$count === 1) ? $singular : ($plural ?? $singular . 's');
	}

	private function pcapCommandValue($value) {
		$value = preg_replace('/[\x00-\x1F\x7F]/', '', (string)$value);
		return str_replace(['|', '}}', '{{'], ['/', '} }', '{ {'], $value);
	}

	/**
	 * Map a severity label to a visual icon for chat rendering.
	 * Used by the fm_audit_* formatter cases.
	 */
	private function severityIcon($severity) {
		switch ($severity) {
			case 'critical': return '🚨';
			case 'high':     return '🔴';
			case 'medium':   return '🟡';
			case 'info':     return 'ℹ️';
			default:         return '•';
		}
	}

	/**
	 * Return the highest severity present in a severity_counts array, or
	 * null if all counts are zero. Used by fm_audit_posture to pick an icon
	 * for each sub-audit's roll-up line.
	 */
	private function topSeverity($counts) {
		foreach (['critical', 'high', 'medium', 'info'] as $sev) {
			if (!empty($counts[$sev]) && (int)$counts[$sev] > 0) {
				return $sev;
			}
		}
		return null;
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
				$ext = $data['extension'];
				// Live registration cross-reference — handy to know if the phone is actually online.
				$contacts = [];
				try {
					$contacts = \FreePBX::Endpoint()->getpjsipAORContactIpsByExten($ext) ?: [];
				} catch (\Throwable $e) {}
				$reg = !empty($contacts) ? '✓ registered (' . count($contacts) . ')' : '✗ not registered';

				$lines = ["📱 **Extension {$ext}** — " . ($u['name'] ?? '(no name)')];
				$lines[] = "  Tech: " . ($d['tech'] ?? 'n/a') . " · {$reg}";
				if (!empty($d['callerid'])) $lines[] = "  Caller ID: {$d['callerid']}";
				if (!empty($u['outboundcid'])) $lines[] = "  Outbound CID: {$u['outboundcid']}";
				if (!empty($d['context']) && $d['context'] !== 'from-internal') $lines[] = "  Context: {$d['context']}";

				// Voicemail
				$vm = $u['voicemail'] ?? '';
				if ($vm && $vm !== 'novm') $lines[] = "  Voicemail: ✓ ({$vm})";
				else $lines[] = "  Voicemail: ✗";

				// Features
				$features = [];
				if (($u['callwaiting'] ?? '') === 'enabled') $features[] = 'call waiting';
				if (($u['intercom'] ?? '') === 'enabled') $features[] = 'intercom';
				if (($u['answermode'] ?? '') === 'enabled') $features[] = 'auto-answer';
				if (($u['call_screen'] ?? '0') !== '0') $features[] = 'call screen';
				if (!empty($features)) $lines[] = "  Features: " . implode(', ', $features);

				// Recording (only show if any are non-default)
				$recordingFields = ['recording_in_external','recording_out_external','recording_in_internal','recording_out_internal'];
				$rec = [];
				foreach ($recordingFields as $f) {
					$v = $u[$f] ?? 'dontcare';
					if ($v !== 'dontcare') {
						$label = str_replace(['recording_','_'], ['',' '], $f);
						$rec[] = "{$label}={$v}";
					}
				}
				if (!empty($rec)) $lines[] = "  Recording: " . implode(', ', $rec);

				// Ring timer / no-answer destination
				if (!empty($u['ringtimer']) && (int)$u['ringtimer'] > 0) $lines[] = "  Ring timer: {$u['ringtimer']}s";
				if (!empty($u['noanswer_dest'])) $lines[] = "  No-answer dest: {$u['noanswer_dest']}";

				// PJSIP NAT triple — useful diagnostic at a glance
				if (($d['tech'] ?? '') === 'pjsip') {
					$nat = [];
					if (isset($d['rtp_symmetric']))   $nat[] = "rtp_symmetric=" . $d['rtp_symmetric'];
					if (isset($d['force_rport']))     $nat[] = "force_rport=" . $d['force_rport'];
					if (isset($d['rewrite_contact'])) $nat[] = "rewrite_contact=" . $d['rewrite_contact'];
					if (!empty($nat)) $lines[] = "  NAT: " . implode(' · ', $nat);
					if (!empty($d['allow'])) $lines[] = "  Codecs: {$d['allow']}";
					if (!empty($d['transport'])) $lines[] = "  Transport: {$d['transport']}";
				}

				// Quick-action chips
				$lines[] = "";
				$lines[] = "  {{cmd:diagnose ext {$ext}|🔍 Diagnose}} · {{cmd:health {$ext}|🩺 Health}} · {{cmd:endpoint details {$ext}|🔧 PJSIP detail}} · {{cmd:show forward on {$ext}|↪ Forward}} · {{cmd:show dnd on {$ext}|🌙 DND}}";
				return implode("\n", $lines);

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
				$noteCdr = !empty($data['include_non_calls']) ? ' (including non-call records)' : '';
				$lines = ["**CDR** ({$data['count']} records){$noteCdr}:"];
				foreach ($data['records'] as $r) {
					$src = $this->sanitizeForChat($r['src'] ?? '');
					$dst = $this->sanitizeForChat($r['dst'] ?? '');
					$disp = $this->sanitizeForChat($r['disposition'] ?? '');
					$dur = (int)($r['duration'] ?? 0);
					$lines[] = "  {$r['calldate']} | `{$src}` → `{$dst}` | `{$disp}` | {$dur}s";
				}
				return implode("\n", $lines);

			case 'fm_get_busiest_extensions':
				if (empty($data['extensions'])) {
					return "No extension activity found in the window.";
				}
				$noteB = !empty($data['include_non_calls']) ? ' (including non-call records)' : '';
				$lines = ["**Busiest extensions** ({$data['count']}){$noteB}:"];
				foreach ($data['extensions'] as $e) {
					$ext = $this->sanitizeForChat($e['extension']);
					$nm  = $e['name'] !== '' ? ' `' . $this->sanitizeForChat($e['name']) . '`' : '';
					$mix = [];
					if ($e['inbound'])  $mix[] = "{$e['inbound']} in";
					if ($e['outbound']) $mix[] = "{$e['outbound']} out";
					if ($e['internal']) $mix[] = "{$e['internal']} internal";
					$mixStr = $mix ? ' (' . implode(', ', $mix) . ')' : '';
					$lines[] = "  {{cmd:show extension {$ext}|{$ext}}}{$nm} — {$e['calls']} calls{$mixStr} · avg {$e['avg_duration_s']}s";
				}
				return implode("\n", $lines);

			case 'fm_get_peak_hours':
				if (empty($data['hours']) || (int)($data['total_calls'] ?? 0) === 0) {
					return "No call volume in the window.";
				}
				$noteP = !empty($data['include_non_calls']) ? ' (including non-call records)' : '';
				$rawNote = '';
				if (!empty($data['total_raw_rows']) && $data['total_raw_rows'] > $data['total_calls']) {
					$collapsed = $data['total_raw_rows'] - $data['total_calls'];
					$rawNote = " · {$collapsed} multi-leg rows collapsed";
				}
				$max = 0;
				foreach ($data['hours'] as $h) { if ($h['calls'] > $max) $max = $h['calls']; }
				$max = max(1, $max);
				$lines = ["**Peak hours** — {$data['total_calls']} calls{$noteP}{$rawNote}:"];
				foreach ($data['hours'] as $h) {
					if ($h['calls'] === 0) continue;
					$barLen = (int) round(($h['calls'] / $max) * 20);
					$bar = str_repeat('▇', max(1, $barLen));
					$lbl = sprintf('%02d:00', $h['hour']);
					$lines[] = "  {$lbl}  {$bar} {$h['calls']}";
				}
				return implode("\n", $lines);

			case 'fm_get_cdr_stats':
				if (empty($data['by_disposition'])) {
					return "No CDR activity in the window.";
				}
				$noteS = !empty($data['include_non_calls']) ? ' (including non-call records)' : '';
				$totalCalls = (int)($data['total_calls'] ?? 0);
				$totalRaw = (int)($data['total_raw_rows'] ?? 0);
				$collapsed = $totalRaw > $totalCalls ? " · {$totalRaw} raw rows ({$totalCalls} after leg-dedup)" : '';
				$lines = ["**CDR stats** — {$totalCalls} calls{$noteS}{$collapsed}:"];
				foreach ($data['by_disposition'] as $d) {
					$disp = $this->sanitizeForChat($d['disposition']);
					$lines[] = "  `{$disp}` — {$d['count']} calls · avg {$d['avg_duration_s']}s · total {$d['duration_total_s']}s";
				}
				return implode("\n", $lines);

			case 'fm_get_cel':
				if (empty($data['rows'])) return "No CEL events in the window.";
				// Group raw CEL events into per-call digests by linkedid. CEL emits
				// 10-20 events per call (CHAN_START/END, STREAM_*, LOCAL_OPTIMIZE,
				// etc.) so an event-per-line render drowns the interesting calls.
				// Tool output is unchanged — only chat collapses; MCP/CLI clients
				// still receive raw rows for composition.
				$calls = [];
				foreach ($data['rows'] as $r) {
					$lid = (string)($r['linkedid'] ?? '');
					if ($lid === '') continue;
					if (!isset($calls[$lid])) {
						$calls[$lid] = [
							'linkedid' => $lid,
							'start' => $r['eventtime'],
							'end' => $r['eventtime'],
							'cid_num' => '',
							'cid_dnid' => '',
							'answered' => false,
							'bridges' => [],
							'transfers' => 0,
							'parks' => 0,
							'pickups' => 0,
							'complete' => false,
						];
					}
					$c =& $calls[$lid];
					if ($r['eventtime'] < $c['start']) $c['start'] = $r['eventtime'];
					if ($r['eventtime'] > $c['end'])   $c['end']   = $r['eventtime'];
					// First non-empty wins. For typical call flows the parties are
					// stable across events; for transfer chains this picks a
					// representative party (timeline view is the source of truth).
					if ($c['cid_num']  === '' && !empty($r['cid_num']))  $c['cid_num']  = $r['cid_num'];
					if ($c['cid_dnid'] === '' && !empty($r['cid_dnid'])) $c['cid_dnid'] = $r['cid_dnid'];
					switch ($r['eventtype']) {
						case 'ANSWER': $c['answered'] = true; break;
						case 'BRIDGE_ENTER':
							$bid = (is_array($r['extra']) && isset($r['extra']['bridge_id']))
								? $r['extra']['bridge_id'] : null;
							if ($bid !== null) $c['bridges'][$bid] = true;
							break;
						case 'BLINDTRANSFER':
						case 'ATTENDEDTRANSFER': $c['transfers']++; break;
						case 'PARK_START': $c['parks']++; break;
						case 'PICKUP':     $c['pickups']++; break;
						case 'LINKEDID_END': $c['complete'] = true; break;
					}
					unset($c);
				}
				uasort($calls, function($a, $b) { return strcmp($b['start'], $a['start']); });

				$lines = ["**CEL events** — {$data['count']} events across " . count($calls) . " calls"];
				$ec = $data['event_counts'] ?? [];
				if (!empty($ec)) {
					$summary = [];
					foreach (array_slice($ec, 0, 5, true) as $type => $n) {
						$summary[] = "`" . $this->sanitizeForChat($type) . "`:{$n}";
					}
					$lines[] = "  Top types: " . implode(' · ', $summary);
				}
				$lines[] = "";
				foreach (array_slice(array_values($calls), 0, 20) as $c) {
					$cid = $this->sanitizeForChat($c['cid_num']);
					$dnid = $this->sanitizeForChat($c['cid_dnid']);
					$bridgeCount = count($c['bridges']);
					$dur = max(0, strtotime($c['end']) - strtotime($c['start']));
					$marker = '📞';
					if ($c['transfers'] > 0)    $marker = '🔀';
					elseif ($c['parks'] > 0)    $marker = '⏸';
					elseif ($c['pickups'] > 0)  $marker = '📥';
					$ans = $c['answered'] ? '✓ answered' : '✗ no answer';
					$brP = $bridgeCount === 1 ? '1 bridge' : "{$bridgeCount} bridges";
					$tail = [];
					if ($c['transfers'] > 0) $tail[] = $c['transfers'] === 1 ? '1 transfer' : "{$c['transfers']} transfers";
					if ($c['parks']     > 0) $tail[] = 'parked';
					if ($c['pickups']   > 0) $tail[] = 'picked up';
					if (!$c['complete'])     $tail[] = 'partial';
					$tailStr = $tail ? ' · ' . implode(' · ', $tail) : '';
					$rawLid = $c['linkedid'];
					$lidChip = (preg_match('/^\d+\.\d+$/', $rawLid) === 1)
						? "{{cmd:show call timeline {$rawLid}|details}}"
						: '`' . $this->sanitizeForChat($rawLid) . '`';
					$arrow = ($cid !== '' || $dnid !== '') ? " — `{$cid}` → `{$dnid}`" : '';
					$lines[] = "  {$marker} {$c['start']}{$arrow} · {$ans} · {$brP} · {$dur}s{$tailStr} · {$lidChip}";
				}
				if (count($calls) > 20) $lines[] = "  ... " . (count($calls) - 20) . " more";
				return implode("\n", $lines);

			case 'fm_call_timeline':
				if (empty($data['found'])) {
					return "**Call timeline** — no events for that linkedid.";
				}
				$lid = $this->sanitizeForChat($data['linkedid']);
				$dur = (int)$data['duration_seconds'];
				$lines = ["**Call timeline** `{$lid}` — {$dur}s, {$data['event_count']} CEL events"];
				$lines[] = "  Started: {$data['started_at']} · Ended: {$data['ended_at']}";
				if (!empty($data['channels'])) {
					$lines[] = "  **Channels** (" . count($data['channels']) . "):";
					foreach ($data['channels'] as $c) {
						$cn = $this->sanitizeForChat($c['channame']);
						$cid = $this->sanitizeForChat($c['cid_num']);
						$ans = $c['answered'] ? '✓' : '✗';
						$role = $this->sanitizeForChat($c['role']);
						$lines[] = "    `{$cn}` · cid=`{$cid}` · role=`{$role}` · answered={$ans}";
					}
				}
				if (!empty($data['bridges'])) {
					$lines[] = "  **Bridges** (" . count($data['bridges']) . "):";
					foreach ($data['bridges'] as $b) {
						$bid = $this->sanitizeForChat($b['bridge_id']);
						$lines[] = "    `{$bid}` · " . count($b['participants']) . " participant events";
					}
				}
				if (!empty($data['transfers'])) {
					$lines[] = "  **Transfers** (" . count($data['transfers']) . "):";
					foreach ($data['transfers'] as $t) {
						$tt = $this->sanitizeForChat($t['type']);
						$te = $this->sanitizeForChat($t['transferer']['ext'] ?? '');
						$tn = $t['transferer']['name'] !== '' ? ' `' . $this->sanitizeForChat($t['transferer']['name']) . '`' : '';
						$lines[] = "    {$t['at']} `{$tt}` by `{$te}`{$tn}";
					}
				}
				if (!empty($data['ivr_legs'])) {
					$lines[] = "  **IVR legs** (" . count($data['ivr_legs']) . "):";
					foreach ($data['ivr_legs'] as $l) {
						$app = $this->sanitizeForChat($l['app']);
						$d = $l['duration_seconds'] !== null ? "{$l['duration_seconds']}s" : '?';
						$lines[] = "    `{$app}` · {$d}";
					}
				}
				if (!empty($data['park_events'])) $lines[] = "  Park events: " . count($data['park_events']);
				if (!empty($data['pickup_events'])) $lines[] = "  Pickup events: " . count($data['pickup_events']);
				return implode("\n", $lines);

			case 'fm_cel_transfers':
				if (empty($data['rows'])) return "No transfer events in the window.";
				$sum = $data['summary'] ?? [];
				$lines = ["**Transfers** ({$data['count']}) — blind: " . ($sum['blind'] ?? 0) . " · attended: " . ($sum['attended'] ?? 0)];
				foreach (array_slice($data['rows'], 0, 20) as $r) {
					$tt = $this->sanitizeForChat($r['type']);
					$te = $this->sanitizeForChat($r['transferer']['ext'] ?? '');
					$tn = $r['transferer']['name'] !== '' ? ' `' . $this->sanitizeForChat($r['transferer']['name']) . '`' : '';
					$rawLid = (string)($r['linkedid'] ?? '');
					$lidPart = (preg_match('/^\d+\.\d+$/', $rawLid) === 1)
						? " · {{cmd:show call timeline {$rawLid}|timeline}}"
						: " · `" . $this->sanitizeForChat($rawLid) . "`";
					$dur = $r['call_duration_before_transfer_s'] !== null ? " · after {$r['call_duration_before_transfer_s']}s" : '';
					$lines[] = "  {$r['at']} `{$tt}` by `{$te}`{$tn}{$dur}{$lidPart}";
				}
				if (count($data['rows']) > 20) $lines[] = "  ... " . (count($data['rows']) - 20) . " more";
				return implode("\n", $lines);

			case 'fm_get_queue_log':
				if (empty($data['rows'])) {
					$note = !empty($data['note']) ? "\n_{$data['note']}_" : '';
					return "No queue log events in the window.{$note}";
				}
				$lines = ["**Queue log** ({$data['count']} events):"];
				$ec = $data['event_counts'] ?? [];
				if (!empty($ec)) {
					$summary = [];
					foreach ($ec as $type => $n) { $summary[] = "`" . $this->sanitizeForChat($type) . "`:{$n}"; }
					$lines[] = "  " . implode(' · ', array_slice($summary, 0, 10));
				}
				foreach (array_slice($data['rows'], 0, 15) as $r) {
					$ev = $this->sanitizeForChat($r['event']);
					$qn = $this->sanitizeForChat($r['queuename']);
					$ag = $this->sanitizeForChat($r['agent']);
					$lines[] = "  {$r['time']} q=`{$qn}` `{$ev}` agent=`{$ag}`";
				}
				if (count($data['rows']) > 15) $lines[] = "  ... " . (count($data['rows']) - 15) . " more";
				return implode("\n", $lines);

			case 'fm_queue_metrics':
				if (empty($data['rows'])) {
					$note = !empty($data['note']) ? "\n_{$data['note']}_" : '';
					return "No queue activity in the window.{$note}";
				}
				$slT = (int)$data['service_level_threshold_seconds'];
				$lines = ["**Queue metrics** — SL threshold {$slT}s · {$data['summary']['total_offered']} offered, {$data['summary']['total_answered']} answered, {$data['summary']['total_abandoned']} abandoned"];
				foreach ($data['rows'] as $q) {
					$qq = $this->sanitizeForChat($q['queue']);
					$qn = $q['name'] !== '' ? ' `' . $this->sanitizeForChat($q['name']) . '`' : '';
					$lines[] = "  **Queue `{$qq}`**{$qn}";
					$lines[] = "    Offered: {$q['offered']} · Answered: {$q['answered']} · Abandoned: {$q['abandoned']} ({$q['abandonment_rate_display']})";
					$lines[] = "    SL: {$q['service_level_display']} · ASA: {$q['asa_display']} · AHT: {$q['aht_display']} · Talk: {$q['talk_time_display']}";
					if ($q['longest_wait_answered_seconds'] > 0) {
						$lines[] = "    Longest wait — answered: {$q['longest_wait_answered_seconds']}s · abandoned: {$q['longest_wait_abandoned_seconds']}s";
					}
				}
				return implode("\n", $lines);

			case 'fm_agent_metrics':
				if (empty($data['rows'])) {
					$note = !empty($data['note']) ? "\n_{$data['note']}_" : '';
					return "No agent activity in the window.{$note}";
				}
				$lines = ["**Agent metrics** ({$data['count']} agents):"];
				foreach ($data['rows'] as $a) {
					$ext = $this->sanitizeForChat((string)($a['agent']['ext'] ?? ''));
					$nm  = ($a['agent']['name'] ?? '') !== '' ? ' `' . $this->sanitizeForChat($a['agent']['name']) . '`' : '';
					$qs = array_map(function($q) { return $this->sanitizeForChat($q); }, $a['queues']);
					$queueList = !empty($qs) ? ' on `' . implode('`,`', $qs) . '`' : '';
					$lines[] = "  **`{$ext}`**{$nm}{$queueList}";
					$lines[] = "    Calls: {$a['calls_handled']} · Talk: {$a['talk_time_display']} · RNA: {$a['ring_no_answer_count']} · Occupancy: {$a['occupancy_display']}";
					$lines[] = "    Session: {$a['session_time_display']} · Available: {$a['available_time_display']} · Paused: {$a['total_pause_display']}";
					if (!empty($a['pauses'])) {
						$pauseStr = [];
						foreach (array_slice($a['pauses'], 0, 4) as $p) {
							$reason = $this->sanitizeForChat($p['reason']);
							$pauseStr[] = "`{$reason}`:{$p['display']} ({$p['count']}x)";
						}
						$lines[] = "    Pause breakdown: " . implode(' · ', $pauseStr);
					}
				}
				return implode("\n", $lines);

			case 'fm_queue_wallboard':
				$qs = $data['queues'] ?? [];
				if (empty($qs)) return "**Wallboard** — no queues configured.";
				$sum = $data['summary'] ?? [];
				$lines = ["**Wallboard** — {$sum['total_waiting']} waiting · {$sum['agents_available']} avail · {$sum['agents_on_call']} on call · {$sum['agents_paused']} paused"];
				foreach ($qs as $q) {
					$qe = $this->sanitizeForChat($q['queue']);
					$qn = $q['name'] !== '' ? ' `' . $this->sanitizeForChat($q['name']) . '`' : '';
					$lines[] = "  **`{$qe}`**{$qn} — {$q['callers_waiting']} waiting · longest {$q['longest_current_wait_display']}";
					$av = count($q['agents']['available']);
					$oc = count($q['agents']['on_call']);
					$pa = count($q['agents']['paused']);
					$lines[] = "    Agents: {$av} avail · {$oc} on call · {$pa} paused";
					foreach ($q['agents']['on_call'] as $a) {
						$ax = $this->sanitizeForChat((string)($a['ext'] ?? ''));
						$an = ($a['name'] ?? '') !== '' ? ' `' . $this->sanitizeForChat($a['name']) . '`' : '';
						$lines[] = "      📞 `{$ax}`{$an} (on call)";
					}
					foreach ($q['agents']['paused'] as $a) {
						$ax = $this->sanitizeForChat((string)($a['ext'] ?? ''));
						$an = ($a['name'] ?? '') !== '' ? ' `' . $this->sanitizeForChat($a['name']) . '`' : '';
						$reason = $this->sanitizeForChat($a['reason'] ?? 'unspecified');
						$lines[] = "      ⏸ `{$ax}`{$an} (paused: `{$reason}`)";
					}
					foreach ($q['agents']['available'] as $a) {
						$ax = $this->sanitizeForChat((string)($a['ext'] ?? ''));
						$an = ($a['name'] ?? '') !== '' ? ' `' . $this->sanitizeForChat($a['name']) . '`' : '';
						$lines[] = "      ✓ `{$ax}`{$an} (available)";
					}
					foreach ($q['callers'] ?? [] as $c) {
						$cid = $this->sanitizeForChat($c['caller_id']);
						$lines[] = "      ⏱ caller `{$cid}` waiting {$c['wait_display']}";
					}
				}
				return implode("\n", $lines);

			case 'fm_list_trunks':
				if (empty($data['trunks'])) {
					return "No trunks configured.";
				}
				$lines = ["**Trunks** ({$data['count']}):"];
				foreach ($data['trunks'] as $t) {
					$id = $t['trunkid'];
					$isDisabled = ($t['disabled'] !== 'off');
					$status = $isDisabled ? ' [DISABLED]' : '';
					$action = $isDisabled
						? "{{cmd:enable trunk {$id}|Enable}}"
						: "{{cmd:disable trunk {$id}|Disable}}";
					$lines[] = "  {{cmd:show trunk {$id}|{$id}}} — {$t['name']} ({$t['tech']}){$status} • {$action}";
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
				if (empty($data['modules'])) {
					if (!empty($data['licensing'])) {
						return "No commercial modules installed — nothing to license.";
					}
					return "No modules found.";
				}
				$upgCount = $data['upgrades_available'] ?? 0;

				// Licensing view: two bulleted groups (Licensed / Unlicensed) plus a
				// renewal link. Only emitted when the caller passed licensing:true.
				// A module counts as licensed when Sysadmin's license map flags it true
				// OR it has a non-expired expiry date from the CommercialLicense table.
				if (($data['view'] ?? 'list') === 'licensing') {
					$licensed = [];
					$unlicensed = [];
					foreach ($data['modules'] as $m) {
						$hasFutureExpiry = !empty($m['expiry']) && empty($m['expired']);
						if ($m['licensed'] === true || $hasFutureExpiry) {
							$licensed[] = $m;
						} else {
							$unlicensed[] = $m;
						}
					}

					$lines = ["**Module Licensing** — {$data['count']} commercial • " . count($licensed) . " licensed, " . count($unlicensed) . " unlicensed"];
					$support = $data['support_contract'] ?? null;
					if (is_array($support)) {
						if (!empty($support['expired'])) {
							$lines[] = "❌ Support contract **expired** (" . ($support['expiration_date'] ?: 'date unknown') . ")";
						} elseif (!empty($support['expiring_soon'])) {
							$lines[] = "⚠️ Support contract expiring soon (" . ($support['expiration_date'] ?: 'date unknown') . ")";
						} elseif (!empty($support['expiration_date'])) {
							$lines[] = "✅ Support contract active (expires {$support['expiration_date']})";
						}
					}
					if (empty($data['sysadmin_available'])) {
						$lines[] = "ℹ️ Sysadmin module not present — registration data unavailable.";
					}

					$renderRow = function($m) {
						$name = $this->sanitizeForChat($m['name']);
						$ver = $this->sanitizeForChat($m['version']);
						$tail = '';
						if (!empty($m['expired'])) {
							$tail = " • expired {$m['expiry']}";
						} elseif (!empty($m['expiry'])) {
							$tail = " • expires {$m['expiry']}";
						}
						return "- {{cmd:module status {$name}|`{$name}`}} v{$ver}{$tail}";
					};

					if (!empty($licensed)) {
						$lines[] = "\n**✅ Licensed** (" . count($licensed) . ")";
						foreach ($licensed as $m) $lines[] = $renderRow($m);
					}
					if (!empty($unlicensed)) {
						$lines[] = "\n**❌ Unlicensed** (" . count($unlicensed) . ")";
						foreach ($unlicensed as $m) $lines[] = $renderRow($m);
					}

					if (!empty($data['renewal_url'])) {
						$lines[] = "\n[🔗 Renew at Sangoma portal]({$data['renewal_url']})";
					}
					$lines[] = "{{cmd:update activation|🔄 Refresh activation}} • {{cmd:list modules|← back to modules}}";
					return implode("\n", $lines);
				}

				// Summary view: counts per license bucket as clickable filters. Avoids dumping
				// 100+ modules into chat when the user just typed "list modules".
				if (($data['view'] ?? 'list') === 'summary') {
					$buckets = ['Commercial' => 0, 'AGPLv3' => 0, 'GPLv3+' => 0, 'GPLv2' => 0, 'Other' => 0];
					foreach ($data['modules'] as $m) {
						$buckets[$m['bucket'] ?? 'Other']++;
					}
					$lines = ["**Modules** ({$data['count']} installed)"];
					if ($upgCount > 0) {
						$lines[0] .= " — ⬆️ {$upgCount} {{cmd:upgrade all modules|upgrade(s) available}}";
					}
					$lines[] = "\nFilter by license:";
					$bucketKey = ['Commercial' => 'commercial', 'AGPLv3' => 'agpl', 'GPLv3+' => 'gpl3', 'GPLv2' => 'gpl2', 'Other' => 'other'];
					foreach ($buckets as $name => $cnt) {
						if ($cnt === 0) continue;
						$key = $bucketKey[$name];
						$lines[] = "  {{cmd:list modules {$key}|{$name} ({$cnt})}}";
					}
					$footer = "\n{{cmd:list all modules|Show all}} • {{cmd:check for upgrades|⬆️ Check for upgrades}}";
					if (($buckets['Commercial'] ?? 0) > 0) {
						$footer .= " • {{cmd:show licensing|🔐 Licensing}}";
					}
					$lines[] = $footer;
					return implode("\n", $lines);
				}

				// List view: grouped by license. Used when the user filtered or asked for all.
				$grouped = ['Commercial' => [], 'AGPLv3' => [], 'GPLv3+' => [], 'GPLv2' => [], 'Other' => []];
				foreach ($data['modules'] as $m) {
					$grouped[$m['bucket'] ?? 'Other'][] = $m;
				}
				$header = "**Modules** ({$data['count']} shown)";
				if ($upgCount > 0) {
					$header .= " — ⬆️ {$upgCount} {{cmd:upgrade all modules|upgrade(s) available}}";
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

			case 'fm_check_upgrades':
				if (empty($data['upgrades'])) {
					return "All modules are up-to-date. ✅";
				}
				$lines = ["**⬆️ Upgrades Available** ({$data['count']}):"];
				foreach ($data['upgrades'] as $u) {
					$lines[] = "  `{$u['name']}` — current v{$u['current_version']} • {{cmd:upgrade module {$u['name']}|⬆️ Upgrade}}";
				}
				$lines[] = "\n{{cmd:upgrade all modules|⬆️ Upgrade all}}";
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

			// Note on safety: every interpolated user-controlled field below is
			// wrapped in backticks AND passed through sanitizeForChat() first.
			// Backticks engage chat.js::formatMarkdown's escape-on-capture path;
			// sanitizeForChat strips literal backticks so the user can't break
			// out of the inline-code wrapping. Static Frogman strings (issue,
			// recommendation, tool names) are interpolated raw because they're
			// not user-controlled. See sanitizeForChat()'s docblock above.

			case 'fm_audit_voicemail_pins':
				if (empty($data['findings'])) {
					return "✅ **Voicemail PIN Audit** — No weak voicemail PINs found.";
				}
				$lines = ["**Voicemail PIN Audit** — {$data['summary']}", ''];
				foreach ($data['findings'] as $f) {
					$icon = $this->severityIcon($f['severity']);
					$mbox = $this->sanitizeForChat($f['mailbox']);
					$nameSan = $f['name'] !== '' ? " (`" . $this->sanitizeForChat($f['name']) . "`)" : '';
					$lines[] = "  {$icon} Mailbox `{$mbox}`{$nameSan} — {$f['issue']}";
				}
				$lines[] = '';
				$lines[] = "→ Set non-default PINs of 6+ digits via the Voicemail module.";
				return implode("\n", $lines);

			case 'fm_audit_extension_secrets':
				if (empty($data['findings'])) {
					return "✅ **Extension Secret Audit** — No weak extension secrets found.";
				}
				$lines = ["**Extension Secret Audit** — {$data['summary']}", ''];
				foreach ($data['findings'] as $f) {
					$icon = $this->severityIcon($f['severity']);
					$ext = $this->sanitizeForChat($f['extension']);
					$nameSan = $f['name'] !== '' ? " (`" . $this->sanitizeForChat($f['name']) . "`)" : '';
					$tech = $this->sanitizeForChat($f['tech']);
					$lines[] = "  {$icon} Extension `{$ext}`{$nameSan} [`{$tech}`] — {$f['issue']}";
				}
				$lines[] = '';
				$lines[] = "→ Set high-entropy secrets (16+ mixed-case alphanumeric) via the Core module.";
				return implode("\n", $lines);

			case 'fm_audit_orphan_dids':
				if (empty($data['findings'])) {
					return "✅ **Orphan DID Audit** — No orphaned inbound routes found.";
				}
				$lines = ["**Orphan DID Audit** — {$data['summary']}", ''];
				foreach ($data['findings'] as $f) {
					$icon = $this->severityIcon($f['severity']);
					$did = $this->sanitizeForChat($f['did']);
					$cid = $this->sanitizeForChat($f['cid']);
					$desc = $f['description'] !== '' ? " — `" . $this->sanitizeForChat($f['description']) . "`" : '';
					$dest = $this->sanitizeForChat($f['destination']);
					$lines[] = "  {$icon} DID `{$did}` / CID `{$cid}`{$desc}";
					$lines[] = "      Destination: `{$dest}`";
					$lines[] = "      Issue: {$f['issue']}";
				}
				return implode("\n", $lines);

			case 'fm_audit_outbound_international':
				if (empty($data['findings'])) {
					return "✅ **International Dial Audit** — No outbound routes with international dial patterns found.";
				}
				$lines = ["**International Dial Audit** — {$data['summary']}", ''];
				foreach ($data['findings'] as $f) {
					$icon = $this->severityIcon($f['severity']);
					$rid = $this->sanitizeForChat($f['route_id']);
					$nameSan = $f['route_name'] !== '' ? " `" . $this->sanitizeForChat($f['route_name']) . "`" : '';
					$prefix = $this->sanitizeForChat($f['prefix']);
					$matchPat = $this->sanitizeForChat($f['match_pattern']);
					$lines[] = "  {$icon} Route `{$rid}`{$nameSan} — matches international prefix `{$f['international_prefix_detected']}`";
					$lines[] = "      Prefix: `{$prefix}`  Match pattern: `{$matchPat}`";
				}
				$lines[] = '';
				$lines[] = "→ Restrict with a route password or extension allowlist via Outbound Routes.";
				return implode("\n", $lines);

			case 'fm_audit_caller_id_posture':
				if (empty($data['findings'])) {
					return "✅ **Caller ID Posture Audit** — No issues across trunks, routes, or extensions.";
				}
				$lines = ["**Caller ID Posture Audit** — {$data['summary']}", ''];
				foreach ($data['findings'] as $f) {
					$icon = $this->severityIcon($f['severity']);
					$refTypeRaw = (string)$f['ref_type'];
					$refIdRaw = (string)$f['ref_id'];
					$refType = $this->sanitizeForChat($refTypeRaw);
					$refId = $this->sanitizeForChat($refIdRaw);
					$refName = $f['ref_name'] !== '' ? " `" . $this->sanitizeForChat($f['ref_name']) . "`" : '';
					$cidVal = $f['cid_value'] !== '' ? "`" . $this->sanitizeForChat($f['cid_value']) . "`" : '`(empty)`';
					$issueSan = $this->sanitizeForChat($f['issue']);
					$recSan = $this->sanitizeForChat($f['recommendation']);

					// Link to the native FreePBX edit page so the admin fixes
					// it through the GUI (no Frogman writes). Only extension
					// URL form is reliable across FreePBX versions; trunk and
					// route forms vary, leave un-linked for now.
					$editLink = '';
					if ($refTypeRaw === 'extension' && ctype_digit($refIdRaw)) {
						$editUrl = '/admin/config.php?display=extensions&extdisplay=' . urlencode($refIdRaw);
						$editLink = " · [✏️ Edit in GUI]({$editUrl})";
					}

					$lines[] = "  {$icon} " . ucfirst($refType) . " `{$refId}`{$refName} — CID: {$cidVal}{$editLink}";
					$lines[] = "      Issue: {$issueSan}";
					$lines[] = "      → {$recSan}";
				}
				return implode("\n", $lines);

			case 'fm_audit_admin_passwords':
				if (empty($data['findings'])) {
					return "✅ **Admin Password Audit** — No weak admin passwords found.";
				}
				$lines = ["**Admin Password Audit** — {$data['summary']}", ''];
				foreach ($data['findings'] as $f) {
					$icon = $this->severityIcon($f['severity']);
					$user = $this->sanitizeForChat($f['username']);
					$issueSan = $this->sanitizeForChat($f['issue']);
					$recSan = $this->sanitizeForChat($f['recommendation']);
					$lines[] = "  {$icon} Admin `{$user}` — {$issueSan}";
					$lines[] = "      → {$recSan}";
				}
				return implode("\n", $lines);

			case 'fm_audit_open_dial_patterns':
				if (empty($data['findings'])) {
					return "✅ **Open Dial Pattern Audit** — No overly permissive dial patterns found.";
				}
				$lines = ["**Open Dial Pattern Audit** — {$data['summary']}", ''];
				foreach ($data['findings'] as $f) {
					$icon = $this->severityIcon($f['severity']);
					$rid = $this->sanitizeForChat($f['route_id']);
					$nameSan = $f['route_name'] !== '' ? " `" . $this->sanitizeForChat($f['route_name']) . "`" : '';
					$combined = $this->sanitizeForChat($f['combined']);
					$issueSan = $this->sanitizeForChat($f['issue']);
					$lines[] = "  {$icon} Route `{$rid}`{$nameSan} — pattern `{$combined}`";
					$lines[] = "      {$issueSan}";
				}
				$lines[] = '';
				$lines[] = "→ Tighten patterns, add a route password, or restrict via Outbound Routes.";
				return implode("\n", $lines);

			case 'fm_audit_posture':
				$ran = count($data['audits']);
				$withFindings = [];
				$clean = [];
				foreach ($data['audits'] as $a) {
					if ((int)$a['count'] > 0) {
						$withFindings[] = $a;
					} else {
						$clean[] = $a;
					}
				}
				$cleanCount = count($clean);
				$actionCount = count($withFindings);
				$failedCount = !empty($data['failed_audits']) ? count($data['failed_audits']) : 0;

				// All-clean fast path.
				if ($actionCount === 0 && $failedCount === 0) {
					return "✅ **Posture Audit** — All {$ran} audits clean.";
				}

				$lines = ['## Posture Audit'];
				$lines[] = "**Score:** {$cleanCount} of {$ran} clean";
				$lines[] = '';

				if ($actionCount > 0) {
					$lines[] = "🔴 Action needed ({$actionCount}):";
					foreach ($withFindings as $a) {
						$sevParts = [];
						foreach (['critical', 'high', 'medium', 'info'] as $sev) {
							$c = (int)($a['severity_counts'][$sev] ?? 0);
							if ($c > 0) $sevParts[] = "{$c} {$sev}";
						}
						$sevText = $sevParts ? implode(', ', $sevParts) : (string)$a['count'];
						// display_name, drilldown_phrase, severity counts are
						// Frogman-controlled — not user input. Safe to interpolate raw.
						$lines[] = "   • **{$a['display_name']}** — {$sevText}    {{cmd:{$a['drilldown_phrase']}|🔍 drill down}}";
					}
				}

				if ($cleanCount > 0) {
					$lines[] = '';
					$lines[] = "✅ Clean ({$cleanCount}):";
					$cleanNames = array_map(function ($a) { return $a['display_name']; }, $clean);
					$lines[] = '   ' . implode(' · ', $cleanNames);
				}

				if ($failedCount > 0) {
					$lines[] = '';
					$lines[] = "**Errored audits:**";
					foreach ($data['failed_audits'] as $err) {
						// Exception messages can echo user-controlled context;
						// sanitize + wrap defensively.
						$errMsg = $this->sanitizeForChat($err['error']);
						$lines[] = "   ⚠️ `{$err['tool']}` — `{$errMsg}`";
					}
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

			case "fm_list_misc_dests":
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

			case 'fm_list_pcaps':
				if (empty($data['captures'])) {
					return "No packet captures found.\n\nStart one from [Sysadmin → Packet Capture](/admin/config.php?display=sysadmin&view=packetcapture), then run `list pcaps` again.";
				}
				$lines = ["**Packet captures** ({$data['count']} found):"];
				$show = !empty($data['shown']) && (int)$data['shown'] === (int)$data['count']
					? $data['captures']
					: array_slice($data['captures'], 0, 3);
				foreach ($show as $c) {
					$path = $this->sanitizeForChat($c['path']);
					$name = $this->sanitizeForChat($c['name']);
					$meta = $this->sanitizeForChat($c['when'] . ' · ' . round($c['size_bytes'] / 1024) . ' KB');
					$lines[] = "";
					$lines[] = "📄 {{cmd:analyze pcap {$path}|{$name}}}";
					$lines[] = "   {$meta}";
				}
				$remaining = (int)$data['count'] - count($show);
				if ($remaining > 0) {
					$lines[] = "  {{cmd:list pcaps all|Show {$remaining} more}}";
				}
				return implode("\n", $lines);

			case 'fm_analyze_pcap':
				if (($data['mode'] ?? '') === 'summary_action') {
					if (($data['status'] ?? '') !== 'ok') {
						return $this->sanitizeForChat($data['message'] ?? 'PCAP action could not be resolved.');
					}
					$result = $data['result'] ?? [];
					$title = $this->sanitizeForChat($result['title'] ?? 'PCAP Action');
					$confidence = !empty($data['confidence']) ? ' · confidence `' . $this->sanitizeForChat($data['confidence']) . '`' : '';
					$lines = ["**{$title}**{$confidence}"];
					$this->appendPcapActionFocusContext($lines, $data);
					if (($result['kind'] ?? '') === 'evidence') {
						$items = $result['items'] ?? [];
						if (empty($items)) {
							$lines[] = "No compact evidence text is available for this item.";
						} else {
							foreach (array_slice($items, 0, 8) as $evidenceLine) {
								if ($evidenceLine !== '') $lines[] = "- `" . $this->sanitizeForChat($evidenceLine) . "`";
							}
						}
						if (!empty($result['refs'])) {
							$refs = array_map([$this, 'sanitizeForChat'], array_slice($result['refs'], 0, 8));
							$lines[] = "Internal refs: `" . implode('` `', $refs) . "`";
						}
					} else {
						$text = (string)($result['text'] ?? '');
						if ($text === '') {
							$lines[] = 'No follow-up text is available for this item.';
						} else {
							foreach (explode("\n", $text) as $textLine) {
								$textLine = $this->sanitizeForChat($textLine);
								$lines[] = $textLine;
							}
						}
					}
					$this->appendPcapActionViewActions($lines, $data);
					return implode("\n", $lines);
				}
				if (!empty($data['unsupported'])) {
					$reason = $this->sanitizeForChat($data['reason'] ?? 'unsupported');
					$hint = $this->sanitizeForChat($data['hint'] ?? '');
					return "**PCAP analysis unsupported** — `{$reason}`" . ($hint !== '' ? "\n`{$hint}`" : '');
				}
				$pcapActionPath = $data['path'] ?? '';
				$pcapActionCallId = (!empty($data['call_id']) && is_string($data['call_id'])) ? $data['call_id'] : null;
				if ($pcapActionCallId === null && !empty($data['calls']) && count($data['calls']) === 1 && !empty($data['calls'][0]['call_id'])) {
					$pcapActionCallId = $data['calls'][0]['call_id'];
				}
				$unparsed = (int)($data['unparsed_sip_message_count'] ?? 0);
				$unparsedText = $unparsed > 0 ? ", {$unparsed} SIP-like " . $this->pcapPluralWord($unparsed, 'message') . " unparsed" : "";
				$sipTransactionCount = (int)($data['analysis']['sip_transaction_count'] ?? $data['call_count'] ?? 0);
				$inviteCallFlowCount = (int)($data['analysis']['invite_call_flow_count'] ?? ($data['analysis']['evidence_highlights']['invite_call_flow_count'] ?? 0));
				$sipMessageCount = (int)($data['sip_message_count'] ?? 0);
				$lines = ["**PCAP SIP ladders** — {$sipMessageCount} SIP " . $this->pcapPluralWord($sipMessageCount, 'message') . " across {$sipTransactionCount} SIP " . $this->pcapPluralWord($sipTransactionCount, 'transaction') . ", including {$inviteCallFlowCount} INVITE " . $this->pcapPluralWord($inviteCallFlowCount, 'call flow') . "{$unparsedText}"];
				if (!empty($data['analysis']['outcome_counts'])) {
					$parts = [];
					foreach ($data['analysis']['outcome_counts'] as $outcome => $count) {
						$parts[] = $this->sanitizeForChat($outcome) . ': ' . (int)$count;
					}
					$lines[] = "Outcomes: `" . implode('` `', $parts) . "`";
				}
				if (!empty($data['analysis']['transport_counts'])) {
					$parts = [];
					foreach ($data['analysis']['transport_counts'] as $transport => $count) {
						$parts[] = $this->sanitizeForChat($transport) . ': ' . (int)$count;
					}
					$lines[] = "Transports: `" . implode('` `', $parts) . "`";
				}
				if (!empty($data['analysis']['final_status_counts'])) {
					$parts = [];
					foreach ($data['analysis']['final_status_counts'] as $status) {
						$parts[] = (int)$status['code'] . ' ' . $this->sanitizeForChat($status['reason'] ?? '') . ': ' . (int)$status['count'];
					}
					$lines[] = "Final statuses: `" . implode('` `', array_slice($parts, 0, 8)) . "`";
				}
				if (!empty($data['analysis']['observation_counts'])) {
					$parts = [];
					foreach ($data['analysis']['observation_counts'] as $obs => $count) {
						$parts[] = $this->sanitizeForChat($obs) . ': ' . (int)$count;
					}
					$lines[] = "Observations: `" . implode('` `', array_slice($parts, 0, 8)) . "`";
				}
				if (!empty($data['analysis']['top_calls']) && ($data['call_count'] ?? 0) > 1) {
					$lines[] = "";
					$lines[] = "Call picker";
					$callRows = [];
					$otherRows = [];
					foreach (array_slice($data['analysis']['top_calls'], 0, 3) as $idx => $top) {
						$topId = $this->sanitizeForChat($top['call_id'] ?? '');
						$outcome = $this->sanitizeForChat($top['outcome'] ?? 'unknown');
						$outcomeLabel = ucwords(str_replace('_', ' ', $outcome));
						if (($top['outcome'] ?? '') === 'cancelled'
							&& !empty($top['observations'])
							&& is_array($top['observations'])
							&& in_array('cancelled_before_answer', $top['observations'], true)
						) {
							$outcomeLabel = 'Cancelled before answer';
						}
						$isInvite = !empty($top['is_invite_call_flow']);
						$method = $this->sanitizeForChat($top['primary_method'] ?? '');
						$typeLabel = $isInvite ? '' : $method;
						$msgCount = (int)($top['message_count'] ?? 0);
						$messageLabel = $msgCount === 1 ? 'message' : 'messages';
						$duration = $this->formatPcapFocusDuration((int)($top['duration_ms'] ?? 0));
						$final = '';
						if (!empty($top['final_status'])) {
							$final = ', final ' . (int)$top['final_status']['code'];
							$reason = $this->sanitizeForChat($top['final_status']['reason'] ?? '');
							if ($reason !== '') $final .= ' ' . $reason;
						}
						$label = trim($outcomeLabel . ' ' . $typeLabel) . " — {$duration}, {$msgCount} {$messageLabel}{$final}";
						$rawTopId = $top['call_id'] ?? '';
						if (!empty($data['path']) && is_string($rawTopId) && $rawTopId !== '') {
							$focusPath = $this->sanitizeForChat($data['path']);
							$focusCallId = $this->pcapCommandValue($rawTopId);
							$primary = "{{cmd:analyze pcap {$focusPath} call_id {$focusCallId}|{$label}}}";
						} else {
							$primary = $label;
						}
						$friendly = isset($top['friendly']) && is_string($top['friendly']) ? $this->sanitizeForChat($top['friendly']) : '';
						if ($isInvite) {
							$callRows[] = [$primary, $topId, $friendly];
						} else {
							$otherRows[] = [$primary, $topId, $friendly];
						}
					}
					$num = 0;
					if (!empty($callRows)) {
						$lines[] = "";
						$lines[] = "📞 Calls found";
						foreach ($callRows as $row) {
							$num++;
							$lines[] = "{$num}. {$row[0]}";
							$lines[] = "   Call-ID: `{$row[1]}`";
							if (!empty($row[2])) $lines[] = "   **{$row[2]}**";
						}
					} else {
						$lines[] = "";
						$lines[] = "📞 Calls found";
						$lines[] = "None. No INVITE call flows were decoded in this capture.";
					}
					if (!empty($otherRows)) {
						$lines[] = "";
						$lines[] = "⚙️ Other SIP transactions";
						foreach ($otherRows as $row) {
							$num++;
							$lines[] = "{$num}. {$row[0]}";
							$lines[] = "   Call-ID: `{$row[1]}`";
							if (!empty($row[2])) $lines[] = "   **{$row[2]}**";
						}
					}
				}
				if (!empty($data['truncated'])) {
					$lines[] = "`Output was capped; use call_id or lower limits to narrow the result.`";
				}
				if (!empty($data['warnings'])) {
					foreach (array_slice($data['warnings'], 0, 3) as $warning) {
						$lines[] = "  Warning: `" . $this->sanitizeForChat($warning) . "`";
					}
				}
				$displayedCalls = array_slice($data['calls'] ?? [], 0, 5);
				if (!empty($displayedCalls)) {
					$lines[] = "";
					$lines[] = "Detailed SIP analysis";
				}
				foreach ($displayedCalls as $call) {
					$callId = $this->sanitizeForChat($call['call_id'] ?? '');
					$duration = (int)($call['duration_ms'] ?? 0);
					$count = (int)($call['message_count'] ?? 0);
					$outcome = !empty($call['summary']['outcome']) ? ' — `' . $this->sanitizeForChat($call['summary']['outcome']) . '`' : '';
					$lines[] = "";
					$lines[] = "Call-ID `{$callId}`{$outcome} — {$count} " . $this->pcapPluralWord($count, 'message') . ", {$duration}ms";
					if (!empty($call['summary'])) {
						$summaryParts = [];
						if (!empty($call['summary']['from'])) {
							$summaryParts[] = "from " . $this->sanitizeForChat($call['summary']['from']);
						}
						if (!empty($call['summary']['to'])) {
							$summaryParts[] = "to " . $this->sanitizeForChat($call['summary']['to']);
						}
						if (!empty($call['summary']['invite_final_status'])) {
							$code = (int)$call['summary']['invite_final_status']['code'];
							$reasonText = $this->sanitizeForChat($call['summary']['invite_final_status']['reason'] ?? '');
							$summaryParts[] = "INVITE final {$code} {$reasonText}";
						} elseif (!empty($call['summary']['final_status'])) {
							$code = (int)$call['summary']['final_status']['code'];
							$reasonText = $this->sanitizeForChat($call['summary']['final_status']['reason'] ?? '');
							$methodText = $this->sanitizeForChat($call['summary']['final_status']['cseq_method'] ?? '');
							$summaryParts[] = trim("final {$code} {$reasonText} {$methodText}");
						}
						if (!empty($call['summary']['release_reason'])) {
							$summaryParts[] = "reason " . $this->sanitizeForChat($call['summary']['release_reason']);
						}
						if (!empty($call['summary']['observations'])) {
							$summaryParts[] = "obs " . implode(', ', array_map([$this, 'sanitizeForChat'], array_slice($call['summary']['observations'], 0, 4)));
						}
						if (!empty($call['summary']['retransmissions'])) {
							$summaryParts[] = "retrans " . (int)$call['summary']['retransmissions'];
						}
						if (!empty($call['summary']['largest_gap_ms'])) {
							$summaryParts[] = "largest gap " . (int)$call['summary']['largest_gap_ms'] . "ms";
						}
						if (!empty($summaryParts)) {
							$lines[] = "  Summary: `" . implode('` `', $summaryParts) . "`";
						}
						if (!empty($call['summary']['rtp'])) {
							$rtp = $call['summary']['rtp'];
							$status = $this->sanitizeForChat($rtp['status'] ?? 'unknown');
							$confidence = $this->sanitizeForChat($rtp['confidence'] ?? 'low');
							$streamCount = count($rtp['streams'] ?? []);
							$rtcp = !empty($rtp['rtcp_seen']) ? ', RTCP seen' : '';
							$hasSeqNotes = !empty($rtp['sequence_notes']);
							$lossSuffix = $hasSeqNotes ? '% on estimable streams' : '%';
							$loss = isset($rtp['sequence_gap_estimate_percent']) && $rtp['sequence_gap_estimate_percent'] !== null ? ', seq gaps ~' . $this->sanitizeForChat((string)$rtp['sequence_gap_estimate_percent']) . $lossSuffix : '';
							$seqNote = !empty($rtp['sequence_notes']) ? ', ' . implode(', ', array_map([$this, 'sanitizeForChat'], $rtp['sequence_notes'])) : '';
							$lines[] = "  RTP: `{$status}`, confidence `{$confidence}`, {$streamCount} " . $this->pcapPluralWord($streamCount, 'stream') . "{$rtcp}{$loss}{$seqNote}";
						}
						if (!empty($call['summary']['diagnostic_hints'])) {
							foreach (array_slice($call['summary']['diagnostic_hints'], 0, 3) as $hint) {
								if (is_array($hint)) {
									$text = $this->sanitizeForChat($hint['text'] ?? '');
									$confidence = $this->sanitizeForChat($hint['confidence'] ?? 'low');
									$obs = !empty($hint['observations']) ? ' obs ' . implode(', ', array_map([$this, 'sanitizeForChat'], $hint['observations'])) : '';
									$lines[] = "  Hint ({$confidence}): `{$text}{$obs}`";
								} else {
									$lines[] = "  Hint: `" . $this->sanitizeForChat($hint) . "`";
								}
							}
						}
						if (!empty($call['summary']['media'])) {
							$mediaLines = [];
							foreach (array_slice($call['summary']['media'], 0, 2) as $media) {
								$mediaParts = [];
								if (!empty($media['connection'])) {
									$mediaParts[] = 'c=' . $this->sanitizeForChat($media['connection']);
								}
								foreach (array_slice($media['media'] ?? [], 0, 3) as $mline) {
									$mediaParts[] = 'm=' . $this->sanitizeForChat($mline);
								}
								if (!empty($mediaParts)) $mediaLines[] = implode(' | ', $mediaParts);
							}
							if (!empty($mediaLines)) {
								$lines[] = "  Media: `" . implode('` `', $mediaLines) . "`";
							}
						}
					}
					foreach (array_slice($call['messages'] ?? [], 0, 20) as $msg) {
						$t = isset($msg['t_ms']) ? '+' . (int)$msg['t_ms'] . 'ms' : '';
						$src = $this->sanitizeForChat($msg['src'] ?? '');
						$dst = $this->sanitizeForChat($msg['dst'] ?? '');
						$line = $this->sanitizeForChat($msg['line'] ?? '');
						$cseq = !empty($msg['cseq']) ? " CSeq `" . $this->sanitizeForChat($msg['cseq']) . "`" : '';
						$reason = !empty($msg['reason']) ? " Reason `" . $this->sanitizeForChat($msg['reason']) . "`" : '';
						$lines[] = "  {$t} `{$src}` -> `{$dst}` `{$line}`{$cseq}{$reason}";
						if (!empty($msg['sdp'])) {
							$sdpParts = [];
							if (!empty($msg['sdp']['connection'])) {
								$sdpParts[] = 'c=' . $this->sanitizeForChat($msg['sdp']['connection']);
							}
							foreach (array_slice($msg['sdp']['media'] ?? [], 0, 3) as $media) {
								$sdpParts[] = 'm=' . $this->sanitizeForChat($media);
							}
							if (!empty($sdpParts)) {
								$lines[] = "      SDP `" . implode('` `', $sdpParts) . "`";
							}
						}
					}
					if ($count > 20) {
						$remainingMessages = $count - 20;
						$lines[] = "  ... and {$remainingMessages} more " . $this->pcapPluralWord($remainingMessages, 'message') . " in this call";
					}
				}
				if (($data['call_count'] ?? 0) > 5) {
					$lines[] = "";
					$remainingTransactions = (int)$data['call_count'] - 5;
					$lines[] = "... and {$remainingTransactions} more SIP " . $this->pcapPluralWord($remainingTransactions, 'transaction') . ". Re-run with `call_id` to focus one ladder.";
				}
				if (!empty($data['analysis']['reader_summary'])) {
					$lines[] = "";
					$lines[] = "**Reader summary**";
					foreach (array_slice($data['analysis']['reader_summary'], 0, 5) as $summaryLine) {
						$lines[] = "- " . $this->sanitizeForChat($summaryLine);
					}
					if (!empty($data['analysis']['support_summary'])) {
						$lines[] = "";
						$lines[] = "**Support summary**";
						foreach (array_slice($data['analysis']['support_summary'], 0, 4) as $item) {
							$text = $this->sanitizeForChat(is_array($item) ? ($item['text'] ?? '') : $item);
							$confidence = is_array($item) ? $this->sanitizeForChat($item['confidence'] ?? 'low') : 'low';
							if ($text !== '') {
								$lines[] = "- ({$confidence}) {$text}";
							}
						}
					}
					if (!empty($data['analysis']['likely_next_checks'])) {
						$lines[] = "";
						$lines[] = "**Likely next checks**";
						foreach (array_slice($data['analysis']['likely_next_checks'], 0, 3) as $item) {
							$text = $this->sanitizeForChat(is_array($item) ? ($item['text'] ?? '') : $item);
							$confidence = is_array($item) ? $this->sanitizeForChat($item['confidence'] ?? 'low') : 'low';
							if ($text !== '') {
								$lines[] = "- ({$confidence}) {$text}";
							}
						}
					}
					if (!empty($data['analysis']['confidence_notes'])) {
						$lines[] = "";
						$lines[] = "**Confidence notes**";
						foreach (array_slice($data['analysis']['confidence_notes'], 0, 3) as $item) {
							$text = $this->sanitizeForChat(is_array($item) ? ($item['text'] ?? '') : $item);
							if ($text !== '') {
								$lines[] = "- {$text}";
							}
						}
					}
				}
				$this->appendPcapSummaryBlockActions($lines, 'response', null, $pcapActionPath, $pcapActionCallId, '');
				return implode("\n", $lines);

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
				// mid-restart) and surface a clickable show-license follow-up so the user
				// can verify the new license once apache returns. (fm_sc_status was parked
				// in v1.6.0 along with other commercial-module integrations; show license
				// reads license state via BMO and works regardless.)
				if (!empty($data['background'])) {
					$msg = $data['message'] ?? 'Activation refresh started.';
					$msg = str_replace('`show license`', '{{cmd:show license|show license}}', $msg);
					// No bold wrap on $msg — it contains a {{cmd:...}} chip, and the chat
					// formatter runs the chip-to-span conversion before the bold regex.
					// Wrapping `**...**` around an already-rendered span causes the bold
					// regex to escapeHtml the span markup back to literal text. Keep the
					// emphasis on just the leading icon/keyword instead.
					$lines = ["✅ {$msg}"];
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
				$lines[] = "  Body: `{\"tool\":\"fm_list_extensions\",\"params\":{}}`";
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
						$ext = !empty($c['extension']) ? $c['extension'] : null;
						$displayName = !empty($c['name']) ? $c['name'] : ($ext ? "Ext {$ext}" : '(unnamed)');
						$nums = [];
						if ($ext && !in_array((string)$ext, $c['numbers'] ?? [], true)) {
							$nums[] = (string)$ext;
						}
						foreach ($c['numbers'] ?? [] as $n) $nums[] = $n;
						$numStr = !empty($nums) ? ' — ' . implode(', ', $nums) : '';
						$company = !empty($c['company']) ? " ({$c['company']})" : '';
						$lines[] = "  👤 **{$displayName}**{$company}{$numStr}";
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
					$destLabel = !empty($d['destination_label']) ? $d['destination_label'] : ($d['destination'] ?? '');
					$dest = $destLabel !== '' ? " → {$destLabel}" : '';
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
				// FreePBX modules sometimes embed raw HTML in display_text + extended_text
				// (Font Awesome icon spans, inline styles, even full HTML tables for things
				// like the Commercial Module Maintenance notification). The chat client's
				// escapeHtml renders that markup as literal text in the output, and a naive
				// strip_tags glues adjacent block elements ("ModuleExpires OnPaging Pro08
				// Jun 2026"). Inject visible separators at block boundaries before stripping,
				// then sanitize for chat-formatter breakout chars.
				$cleanNotifText = function($raw) {
					$raw = (string)$raw;
					// Preserve `<a href='X'>label</a>` as a markdown link. FreePBX modules
					// embed admin-page links in notifications (e.g. "Port Management Page" in
					// the scd_requirement_* notifications); strip_tags would otherwise erase
					// them. We can't emit the `[...](url)` here because sanitizeForChat below
					// will neutralize the `[`, so we stash each rendered link under a sentinel
					// that survives both strip_tags and sanitize, and restore them last.
					$linkPlaceholders = [];
					$raw = preg_replace_callback(
						'#<a\b[^>]*\bhref\s*=\s*[\'"]([^\'"]+)[\'"][^>]*>(.*?)</a>#is',
						function($m) use (&$linkPlaceholders) {
							$url = trim($m[1]);
							$label = trim(html_entity_decode(strip_tags($m[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
							if ($url === '' || $label === '') return $label;
							// Bare FreePBX admin paths ("config.php?...") become root-relative;
							// the chat formatter's link regex requires a leading "/" or "http(s)://".
							if (!preg_match('#^(https?:|/)#i', $url)) {
								$url = '/admin/' . ltrim($url, './');
							}
							// Same scheme allowlist the client-side renderer enforces — bail to
							// plain label text on anything dangerous so the link is dropped, not
							// neutered into an exploitable shape.
							if (preg_match('/^(?:javascript|data|vbscript):/i', $url)) return $label;
							// Escape characters that would terminate the markdown-link syntax.
							$label = str_replace(']', ')', $label);
							$url = str_replace([')', ' ', '"'], ['%29', '%20', '%22'], $url);
							$idx = count($linkPlaceholders);
							$linkPlaceholders[$idx] = "[{$label}]({$url})";
							return "__FMLINK{$idx}END__";
						},
						$raw
					);
					// Specific separators for block-level / table elements before stripping.
					$raw = preg_replace('#</(?:p|div|h[1-6]|tr|li|ul|ol)>#i', ' · ', $raw);
					$raw = preg_replace('#</(?:td|th)>#i', ' | ', $raw);
					$raw = preg_replace('#<br\s*/?>#i', ' · ', $raw);
					// Inline tags (e.g. </strong><table>) still glue their text together.
					// Inject a space before every remaining tag so strip_tags leaves a gap.
					$raw = str_replace('<', ' <', $raw);
					$plain = html_entity_decode(strip_tags($raw), ENT_QUOTES | ENT_HTML5, 'UTF-8');
					$plain = preg_replace('/\s+/', ' ', trim($plain));
					// Collapse doubled separators that adjacent closing tags produced.
					$plain = preg_replace('/(?:\s*·\s*){2,}/', ' · ', $plain);
					$plain = preg_replace('/(?:\s*\|\s*){2,}/', ' | ', $plain);
					// A pipe ending a table row immediately before the row separator just
					// reads as noise. `</td></tr>` → ` | ` + ` · ` → ` | · ` → ` · `.
					$plain = preg_replace('/\s*\|\s*·\s*/', ' · ', $plain);
					$plain = trim($plain, " ·|");
					$plain = $this->sanitizeForChat($plain);
					// Linkify GHSA IDs to the FreePBX security-reporting repo's advisory page,
					// which is where FreePBX-module advisories are published (the global
					// /advisories/<id> endpoint 404s because the entries aren't promoted to
					// GitHub's global database). Pattern is fixed-format so the regex captures
					// cleanly; the resulting `[...](url)` is formatter-controlled output, not
					// user input, so it intentionally lands after sanitizeForChat (which would
					// otherwise neutralize the leading `[`).
					$plain = preg_replace_callback('/\bGHSA(?:-[a-z0-9]{4}){3}\b/i', function($m) {
						return "[{$m[0]}](https://github.com/FreePBX/security-reporting/security/advisories/{$m[0]})";
					}, $plain);
					// Restore link placeholders. Built from validated URLs + sanitized labels
					// above, so this is trusted formatter output, not user input.
					if (!empty($linkPlaceholders)) {
						$plain = preg_replace_callback('/__FMLINK(\d+)END__/', function($m) use ($linkPlaceholders) {
							return $linkPlaceholders[(int)$m[1]] ?? '';
						}, $plain);
					}
					return $plain;
				};

				// Single notification detail view
				if (!empty($data['single'])) {
					$levelIcons = ['error' => '🔴', 'warning' => '🟡', 'update' => '🔵', 'notice' => '💬', 'critical' => '🚨', 'security' => '🔒'];
					$icon = $levelIcons[$data['level']] ?? '📋';
					$titleText = $cleanNotifText($data['text']);
					$lines = ["{$icon} **{$titleText}**"];
					$isTampered = $data['id'] === 'FW_TAMPERED';
					$isUnsigned = $data['id'] === 'FW_UNSIGNED';
					if (!empty($data['details'])) {
						$isUpdates = $data['id'] === 'NEWUPDATES';
						$hasPendingSecurity = false;
						// Some FreePBX notifications separate their detail lines with `<br>`
						// (FW_TAMPERED, FW_UNSIGNED) rather than literal newlines — normalize
						// to "\n" before splitting so per-line regexes see one finding at a time.
						$detailsForSplit = preg_replace('#<br\s*/?>#i', "\n", (string)$data['details']);
						// Some FreePBX notifications duplicate their detail lines (notably
						// VULNERABILITIES_FIXED, which lists each module's fix twice). Dedupe
						// by exact line content so the user sees each finding once.
						$rawDetails = array_unique(array_map('trim', explode("\n", $detailsForSplit)));
						foreach ($rawDetails as $detail) {
							if (empty($detail)) continue;
							// Make upgrade lines clickable. Module names from FreePBX are tightly
							// constrained (alpha + _) so the regex captures are safe to inline raw.

							// NEWUPDATES shape: "<module> <new_version> (current: <cur_version>)"
							if ($isUpdates && preg_match('/^(\S+)\s+(\S+)\s+\(current:\s+(\S+)\)/', $detail, $um)) {
								$lines[] = "  {{cmd:module status {$um[1]}|{$um[1]}}} v{$um[3]} ⬆️ {{cmd:upgrade module {$um[1]}|v{$um[2]}}}";
								continue;
							}

							// VULNERABILITIES shape: "<module> (Cur v. <cur>) should be upgraded to v. <target> to fix security issues: GHSA-..."
							// Action-required line → render with the same chip pair plus linkified GHSA refs.
							// The "to fix security issues:" prose is redundant once the GHSA link is
							// rendered, so strip the lead-in and just emit the GHSA reference(s) directly.
							if (preg_match('/^(\S+)\s+\(Cur v\.\s+(\S+)\)\s+should be upgraded to v\.\s+(\S+)(.*)$/i', $detail, $vm)) {
								$mod = $vm[1];
								$cur = $vm[2];
								$target = $vm[3];
								$tail = trim($vm[4]);
								$tail = preg_replace('/^to fix security issues?:?\s*/i', '', $tail);
								$tailRendered = $tail !== '' ? ' · ' . $cleanNotifText($tail) : '';
								$lines[] = "  {{cmd:module status {$mod}|{$mod}}} v{$cur} ⬆️ {{cmd:upgrade module {$mod}|v{$target}}}{$tailRendered}";
								$hasPendingSecurity = true;
								continue;
							}

							// FW_TAMPERED shape: `Module: "<display>", File: "<path> altered"`.
							// Extract internal module name from the path (everything between
							// /modules/ and the next /) so the chip resolves to a usable
							// `module status <internal>` command — display names like "CDR Reports"
							// don't address the module in FreePBX, the internal name "cdr" does.
							if ($isTampered && preg_match('/^Module:\s*"([^"]+)",\s*File:\s*"(.+?)\s+altered"\s*$/i', $detail, $tm)) {
								$displayName = $tm[1];
								$filePath = $tm[2];
								$internal = '';
								if (preg_match('#/modules/([^/]+)/#', $filePath, $pm)) {
									$internal = $pm[1];
								}
								$dispSan = $this->sanitizeForChat($displayName);
								$pathSan = $this->sanitizeForChat($filePath);
								if ($internal !== '' && preg_match('/^[a-zA-Z0-9_]+$/', $internal)) {
									$lines[] = "  {{cmd:module status {$internal}|{$dispSan}}} · `{$pathSan}`";
								} else {
									$lines[] = "  **{$dispSan}** · `{$pathSan}`";
								}
								continue;
							}

							// FW_UNSIGNED shape: `Module "<display>" is unsigned and should be re-downloaded`.
							// No internal name in the text — best we can do is bold the display name
							// and let the user re-download via module install/upgrade tools.
							if ($isUnsigned && preg_match('/^Module\s+"([^"]+)"\s+is unsigned/i', $detail, $um)) {
								$displayName = $this->sanitizeForChat($um[1]);
								$lines[] = "  **{$displayName}** is unsigned and should be re-downloaded";
								continue;
							}

							$lines[] = "  " . $cleanNotifText($detail);
						}
						if ($isUpdates) {
							$lines[] = "\n{{cmd:upgrade all modules|⬆️ Upgrade All}}";
						} elseif ($hasPendingSecurity) {
							$lines[] = "\n{{cmd:upgrade all modules|⬆️ Upgrade All}}";
						}
					} else {
						$lines[] = "  No additional details.";
					}
					// Sangoma portal renewal links for module-maintenance notifications.
					// The FW about_to_exp_* notification family points at different portal
					// "viewReport" pages depending on which entitlement type is expiring:
					// commercial sales modules, 1-year sales-by-deployment, and softphone.
					// Populated as we identify the notification ID for each variant in the
					// wild — about_to_exp_WL is the standard commercial-module case.
					$renewalUrls = [
						'about_to_exp_WL' => 'https://portal.sangoma.com/index.php/report/viewReport/283',
						// TODO: add IDs for "1-Year Sales Modules By Deployment" and
						// "Softphone-Module Renewal By Deployment" once their notifications
						// surface so we can map each to its own portal report URL.
					];
					$notifId = $data['id'] ?? '';
					if (!empty($renewalUrls[$notifId])) {
						$lines[] = "\n[🔗 Renew at Sangoma portal]({$renewalUrls[$notifId]})";
					}

					// Dismiss chip — only emit when FreePBX flags the notification as
					// user-dismissable (candelete=1). BADDEST and similar config-error
					// notifications are candelete=0 and silently no-op through safe_delete,
					// so offering the chip would be misleading. The chat-formatter check
					// keeps the UI honest; the tool also fails loudly server-side if
					// someone hand-types `dismiss notification BADDEST`.
					$notifModule = $data['module'] ?? '';
					$canDismiss = !empty($data['candelete']);
					if ($canDismiss && $notifModule !== '' && $notifId !== ''
						&& preg_match('/^[a-zA-Z0-9_-]+$/', $notifModule)
						&& preg_match('/^[a-zA-Z0-9_-]+$/', $notifId)
					) {
						$lines[] = "\n{{cmd:dismiss notification {$notifModule} {$notifId}|⛔ Dismiss notification}}";
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
					$text = $cleanNotifText($n['text']);
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

			case 'fm_did_destination_map':
				if (empty($data['did_count'])) {
					$f = !empty($data['filter']) ? " (filter: \"{$data['filter']}\")" : '';
					return "📞 No inbound routes found{$f}.";
				}
				$lines = ["📞 **Inbound DID Map** — {$data['did_count']} DID" . ($data['did_count']===1?'':'s') . " → {$data['destination_count']} unique destination" . ($data['destination_count']===1?'':'s')];
				if (!empty($data['filter']) || !empty($data['to'])) {
					$bits = [];
					if (!empty($data['filter'])) $bits[] = "filter: \"{$data['filter']}\"";
					if (!empty($data['to'])) $bits[] = "to: \"{$data['to']}\"";
					$lines[] = "  " . implode(', ', $bits);
				}
				if (!empty($data['summary'])) {
					$summaryBits = [];
					$labels = ['extension'=>'extensions', 'ringgroup'=>'ring groups', 'queue'=>'queues', 'ivr'=>'IVRs', 'voicemail'=>'voicemail', 'timecondition'=>'time conditions', 'announcement'=>'announcements', 'terminate'=>'terminations', 'unknown'=>'unconfigured'];
					foreach ($data['summary'] as $type => $count) {
						$summaryBits[] = "{$count} → " . ($labels[$type] ?? $type);
					}
					$lines[] = "  " . implode(', ', $summaryBits);
				}
				$lines[] = "";
				$lines[] = "```mermaid";
				$lines[] = rtrim($data['mermaid']);
				$lines[] = "```";
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

			case 'fm_list_backup_jobs':
				if (empty($data['jobs'])) return "No backup jobs configured.";
				$lines = ["**Backup jobs** ({$data['count']}):"];
				foreach ($data['jobs'] as $job) {
					$enabled = !empty($job['schedule_enabled']) ? '✓' : '✗';
					$schedule = !empty($job['schedule']) ? "`{$job['schedule']}`" : '(no schedule)';
					$destNames = array_map(function($d) {
						return ($d['driver'] ?? '?') . ': ' . ($d['name'] ?? $d['id'] ?? '?');
					}, $job['destinations'] ?? []);
					$dest = !empty($destNames) ? implode(', ', $destNames) : '(no destination)';
					// No bold around the {{cmd:...}} chip — the chat formatter's bold regex
					// escapeHtml's its captured body, mangling the chip's rendered <span>
					// into literal text. The chip's clickable styling provides enough
					// emphasis on its own.
					$lines[] = "  {$enabled} {{cmd:backup status for {$job['name']}|{$job['name']}}} — {$schedule} → {$dest}";
				}
				return implode("\n", $lines);

			case 'fm_backup_status':
				$summary = $data['summary'] ?? [];
				$lines = ["**Backup status** — {$summary['total']} total · {$summary['scheduled']} scheduled · ⚠ {$summary['missed']} missed · 🔄 {$summary['in_flight']} in-flight"];
				if (empty($data['jobs'])) {
					$lines[] = "\nNo matching jobs.";
					return implode("\n", $lines);
				}
				foreach ($data['jobs'] as $job) {
					$lines[] = '';
					$lastOk = $job['last_successful_run']['timestamp'] ?? null;
					$inFlight = $job['in_flight'] ?? null;
					if ($inFlight) {
						$icon = '🔄';
						$detail = "running ({$inFlight['status']})";
					} elseif (!empty($job['missed'])) {
						$icon = '⚠';
						$detail = "MISSED — " . ($job['missed_reason'] ?? 'no recent success');
					} elseif ($lastOk) {
						$icon = '✓';
						$detail = "last success: {$lastOk}";
					} else {
						$icon = '·';
						$detail = 'no runs on record';
					}
					$lines[] = "{$icon} **{$job['name']}** — {$detail}";
					if (!empty($job['next_scheduled_run'])) {
						$lines[] = "  next: {$job['next_scheduled_run']}";
					}
					if (!empty($job['last_successful_run']['file_size'])) {
						$mb = round($job['last_successful_run']['file_size'] / 1048576, 1);
						$lines[] = "  last artifact: {$mb} MB";
					}
				}
				return implode("\n", $lines);

			case 'fm_list_backup_runs':
				if (empty($data['runs'])) return $data['message'] ?? 'No backup runs to show.';
				$lines = ["**Backup runs** ({$data['count']}" . (!empty($data['truncated']) ? ", truncated" : "") . "):"];
				foreach ($data['runs'] as $run) {
					$icon = ['success' => '✓', 'running' => '🔄', 'failed' => '✗', 'failed_inferred' => '⚠'][$run['status']] ?? '·';
					$when = $run['finished_at'] ?? '(no timestamp)';
					$line = "  {$icon} **{$run['job_name']}** — `{$run['status']}` @ {$when}";
					if (!empty($run['file_size'])) {
						$mb = round($run['file_size'] / 1048576, 1);
						$line .= " · {$mb} MB";
					}
					if (!empty($run['error'])) $line .= "\n     {$run['error']}";
					$lines[] = $line;
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

	// Keys whose values should never land in oc_audit_log. Matched case-insensitively
	// against array keys before JSON-encoding either params or the tool response. See
	// GHSA-3p65-2prr-cfvf — without this, fm_reset_password's outcome (which returns
	// the new password) and fm_add_extension's outcome (which returns the device
	// secret) become readable by anyone who can call fm_audit_search.
	//
	// IMPORTANT CONSTRAINT: this redactor matches by ARRAY KEY name, not by value
	// content. If a tool returns a sensitive value inside a free-text string
	// (e.g. `'message' => "Password reset. New password: hunter2"`), the redactor
	// will NOT catch it — `message` is not in the set, and the password is part
	// of the value, not under its own key.
	//
	// When adding new tools that return secrets:
	//   - Always surface secrets under one of these keys (or extend this list).
	//   - Never embed secrets in `message` / `note` / `summary` / arbitrary text.
	//   - If a new key name is needed, ADD IT HERE and to install.php (which uses
	//     a duplicate of this list for the historical scrub migration).
	private static $SENSITIVE_AUDIT_KEYS = [
		'password', 'secret', 'token', 'vmpwd', 'umpassword', 'umpwd', 'api_key', 'apikey',
	];

	private function redactSensitive($data) {
		if (!is_array($data)) return $data;
		$redactSet = array_flip(self::$SENSITIVE_AUDIT_KEYS);
		foreach ($data as $key => $value) {
			if (is_string($key) && isset($redactSet[strtolower($key)])) {
				$data[$key] = '[REDACTED]';
			} elseif (is_array($value)) {
				$data[$key] = $this->redactSensitive($value);
			}
		}
		return $data;
	}

	// Free-text counterpart to redactSensitive(). chat_input is a raw string
	// the user typed, so the array-key approach above doesn't apply — a user
	// can type "set vmpwd 1234 for 1001" and the secret is positional, not
	// under a key. This redactor matches a known secret-keyword followed by
	// the next whitespace-delimited token and replaces that token with
	// [REDACTED]. Conservative by design: only the listed keywords trigger
	// it; we don't try to defang arbitrary high-entropy strings. fm_audit_search
	// is PERM_ADMIN regardless, which bounds exposure even if a keyword slips.
	private function redactChatInput($text) {
		if (!is_string($text) || $text === '') return $text;
		$pattern = '/\b(password|passwd|pwd|vmpwd|umpassword|umpwd|secret|token|api_key|apikey)\s+\S+/i';
		return preg_replace($pattern, '$1 [REDACTED]', $text);
	}

	public function auditIntent($tool, $params, $userId = null, $sessionId = null,
	                            $chatInput = null, $interpretedAs = null) {
		$sql = "INSERT INTO oc_audit_log
		          (tool, params, user_id, session_id, intent,
		           chat_input, interpreted_as, status, created_at)
		        VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', ?)";
		$sth = $this->db->prepare($sql);
		$sth->execute([
			$tool,
			json_encode($this->redactSensitive($params)),
			$userId,
			$sessionId,
			"Execute {$tool}",
			$chatInput     !== null ? $this->redactChatInput($chatInput)     : null,
			$interpretedAs !== null ? $this->redactChatInput($interpretedAs) : null,
			time(),
		]);
		return (int) $this->db->lastInsertId();
	}

	public function auditOutcome($auditId, $status, $detail = null) {
		$sql = "UPDATE oc_audit_log SET status = ?, detail = ?, completed_at = ? WHERE id = ?";
		$sth = $this->db->prepare($sql);
		$encoded = is_string($detail) ? $detail : json_encode($this->redactSensitive($detail));
		$sth->execute([$status, $encoded, time(), $auditId]);
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
			if ($basename === 'AbstractTool' || $basename === 'ChatParser' || $basename === 'Interpret') {
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

	public function runTool($name, $params, $userId = null, $sessionId = null,
	                        $chatInput = null, $interpretedAs = null) {
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

		$auditId = $this->auditIntent($name, $params, $userId, $sessionId, $chatInput, $interpretedAs);

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
