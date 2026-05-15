<?php
namespace FreePBX\modules\Frogman;

if (!class_exists(__NAMESPACE__ . '\\Interpret', false)) {
	require_once realpath(__DIR__ . '/Interpret.php');
}

class ChatParser {

	private static $db = null;

	private static function getDb() {
		if (self::$db === null) {
			self::$db = \FreePBX::Database();
		}
		return self::$db;
	}

	private static function getPending($sessionId) {
		$db = self::getDb();
		$sth = $db->prepare("SELECT context FROM oc_sessions WHERE id = ? AND status = 'pending_confirm'");
		$sth->execute([$sessionId]);
		$row = $sth->fetch(\PDO::FETCH_ASSOC);
		if ($row && !empty($row['context'])) {
			return json_decode($row['context'], true);
		}
		return null;
	}

	private static function setPending($sessionId, $tool, $params) {
		$db = self::getDb();
		$data = json_encode(['tool' => $tool, 'params' => $params]);
		$sth = $db->prepare("SELECT id FROM oc_sessions WHERE id = ?");
		$sth->execute([$sessionId]);
		if ($sth->fetch()) {
			$sth = $db->prepare("UPDATE oc_sessions SET context = ?, status = 'pending_confirm', last_activity = ? WHERE id = ?");
			$sth->execute([$data, time(), $sessionId]);
		} else {
			$sth = $db->prepare("INSERT INTO oc_sessions (id, user_id, started_at, last_activity, context, status) VALUES (?, NULL, ?, ?, ?, 'pending_confirm')");
			$sth->execute([$sessionId, time(), time(), $data]);
		}
	}

	private static function clearPending($sessionId) {
		$db = self::getDb();
		$sth = $db->prepare("UPDATE oc_sessions SET status = 'active', context = NULL WHERE id = ?");
		$sth->execute([$sessionId]);
	}

	public static function setFollowUp($sessionId, $tool, $params, $needsInput = null, $inputPrompt = null) {
		$db = self::getDb();
		$payload = ['tool' => $tool, 'params' => $params, 'type' => 'followup'];
		if ($needsInput) {
			$payload['needs_input'] = $needsInput;
			if ($inputPrompt) $payload['input_prompt'] = $inputPrompt;
		}
		$data = json_encode($payload);
		$sth = $db->prepare("SELECT id FROM oc_sessions WHERE id = ?");
		$sth->execute([$sessionId]);
		if ($sth->fetch()) {
			$sth = $db->prepare("UPDATE oc_sessions SET context = ?, status = 'pending_confirm', last_activity = ? WHERE id = ?");
			$sth->execute([$data, time(), $sessionId]);
		} else {
			$sth = $db->prepare("INSERT INTO oc_sessions (id, user_id, started_at, last_activity, context, status) VALUES (?, NULL, ?, ?, ?, 'pending_confirm')");
			$sth->execute([$sessionId, time(), time(), $data]);
		}
	}

	private static function getInputPending($sessionId) {
		$db = self::getDb();
		$sth = $db->prepare("SELECT context FROM oc_sessions WHERE id = ? AND status = 'pending_input'");
		$sth->execute([$sessionId]);
		$row = $sth->fetch(\PDO::FETCH_ASSOC);
		if ($row && !empty($row['context'])) {
			return json_decode($row['context'], true);
		}
		return null;
	}

	public static function setInputPrompt($sessionId, $tool, $params, $inputParam) {
		$db = self::getDb();
		$data = json_encode([
			'tool' => $tool,
			'params' => $params,
			'type' => 'input',
			'input_param' => $inputParam,
		]);
		$sth = $db->prepare("SELECT id FROM oc_sessions WHERE id = ?");
		$sth->execute([$sessionId]);
		if ($sth->fetch()) {
			$sth = $db->prepare("UPDATE oc_sessions SET context = ?, status = 'pending_input', last_activity = ? WHERE id = ?");
			$sth->execute([$data, time(), $sessionId]);
		} else {
			$sth = $db->prepare("INSERT INTO oc_sessions (id, user_id, started_at, last_activity, context, status) VALUES (?, NULL, ?, ?, ?, 'pending_input')");
			$sth->execute([$sessionId, time(), time(), $data]);
		}
	}

	// Multi-step input wizard. $prompts is an ordered list of:
	//   ['param' => 'ext', 'prompt' => 'Which extension?']
	//   ['param' => 'ringtime', 'prompt' => '...', 'skip_default' => 20]
	// A prompt with skip_default lets the user type "skip" to take the default;
	// without skip_default, "skip" cancels the wizard. After all prompts are answered
	// the tool runs WITHOUT confirm:true so its dry-run preview surfaces.
	public static function setInputWizard($sessionId, $tool, $params, $prompts) {
		$db = self::getDb();
		$data = json_encode([
			'tool' => $tool,
			'params' => $params,
			'type' => 'wizard',
			'prompts' => $prompts,
		]);
		$sth = $db->prepare("SELECT id FROM oc_sessions WHERE id = ?");
		$sth->execute([$sessionId]);
		if ($sth->fetch()) {
			$sth = $db->prepare("UPDATE oc_sessions SET context = ?, status = 'pending_input', last_activity = ? WHERE id = ?");
			$sth->execute([$data, time(), $sessionId]);
		} else {
			$sth = $db->prepare("INSERT INTO oc_sessions (id, user_id, started_at, last_activity, context, status) VALUES (?, NULL, ?, ?, ?, 'pending_input')");
			$sth->execute([$sessionId, time(), time(), $data]);
		}
	}

	// ── Macro engine ─────────────────────────────────────────────
	// A macro chains multiple tools together with optional gates (yes/no branch
	// points) and a final summary-preview step. Steps are walked one at a time;
	// gates skip a block on "no". On final confirm, all qualifying actions fire
	// in order with confirm:true and a single summary is returned.
	//
	// Step kinds:
	//   prompt   — collect a param ($skip_default optional)
	//   gate     — yes/no question; "no" advances by 1+skip_count, "yes" by 1
	//   confirm  — summary preview of pending actions; "yes" runs them
	//
	// Action shape:
	//   ['tool' => 'fm_x', 'params' => ['k' => '$param_name'], 'always' => true]
	//   ['tool' => 'fm_x', 'params' => [...], 'if_set' => 'param_name']
	//   'critical' => true on the foundational tool aborts the rest if it fails.

	public static function setMacro($sessionId, $macroName) {
		$macro = self::getMacroDef($macroName);
		if (!$macro) return ['response' => "Unknown wizard: {$macroName}"];
		$state = ['type' => 'macro', 'name' => $macroName, 'step_idx' => 0, 'params' => []];
		self::saveMacroState($sessionId, $state);
		return self::advanceMacro($sessionId, null);
	}

	private static function saveMacroState($sessionId, $state) {
		$db = self::getDb();
		$data = json_encode($state);
		$sth = $db->prepare("SELECT id FROM oc_sessions WHERE id = ?");
		$sth->execute([$sessionId]);
		if ($sth->fetch()) {
			$sth = $db->prepare("UPDATE oc_sessions SET context = ?, status = 'pending_input', last_activity = ? WHERE id = ?");
			$sth->execute([$data, time(), $sessionId]);
		} else {
			$sth = $db->prepare("INSERT INTO oc_sessions (id, user_id, started_at, last_activity, context, status) VALUES (?, NULL, ?, ?, ?, 'pending_input')");
			$sth->execute([$sessionId, time(), time(), $data]);
		}
	}

	public static function advanceMacro($sessionId, $userInput) {
		$db = self::getDb();
		$sth = $db->prepare("SELECT context FROM oc_sessions WHERE id = ? AND status = 'pending_input'");
		$sth->execute([$sessionId]);
		$row = $sth->fetch(\PDO::FETCH_ASSOC);
		if (!$row) return ['response' => 'Macro state lost.'];
		$state = json_decode($row['context'], true);
		if (($state['type'] ?? '') !== 'macro') return ['response' => 'Not in a macro flow.'];

		$macro = self::getMacroDef($state['name']);
		$steps = $macro['steps'];

		// Apply user input to current step (if any)
		if ($userInput !== null) {
			if (preg_match('/^(cancel|abort|stop)$/i', $userInput) || Interpret::isCorrectionCancel($userInput)) {
				self::clearPending($sessionId);
				return ['response' => 'Cancelled.'];
			}
			$current = $steps[$state['step_idx']] ?? null;
			if (!$current) {
				self::clearPending($sessionId);
				return ['response' => 'Macro ended unexpectedly.'];
			}

			if ($current['kind'] === 'prompt') {
				if (preg_match('/^(no|skip|nevermind|nope)$/i', $userInput)) {
					if (array_key_exists('skip_default', $current)) {
						if ($current['skip_default'] !== null) {
							$state['params'][$current['param']] = $current['skip_default'];
						}
					} else {
						self::clearPending($sessionId);
						return ['response' => 'OK, cancelled. (That step was required.)'];
					}
				} else {
					$state['params'][$current['param']] = $userInput;
				}
				$state['step_idx']++;
			} elseif ($current['kind'] === 'gate') {
				if (preg_match('/^(yes|y|ok|sure|yep|yeah)$/i', $userInput)) {
					$state['step_idx']++;
				} else {
					$state['step_idx'] += 1 + ($current['skip_count'] ?? 0);
				}
			} elseif ($current['kind'] === 'confirm') {
				if (preg_match('/^(yes|y|ok|sure|yep|yeah)$/i', $userInput)) {
					self::clearPending($sessionId);
					return self::runMacroActions($state['params'], $macro, $sessionId);
				}
				self::clearPending($sessionId);
				return ['response' => 'Cancelled — nothing changed.'];
			}
		}

		// Walk steps until we hit one that needs user input (or run off the end)
		while ($state['step_idx'] < count($steps)) {
			$step = $steps[$state['step_idx']];
			if ($step['kind'] === 'prompt' || $step['kind'] === 'gate') {
				self::saveMacroState($sessionId, $state);
				return ['response' => $step['prompt']];
			}
			if ($step['kind'] === 'confirm') {
				self::saveMacroState($sessionId, $state);
				return ['response' => self::renderMacroPreview($macro, $state['params'])];
			}
			$state['step_idx']++;
		}

		// Fell off the end without a confirm — run actions anyway
		self::clearPending($sessionId);
		return self::runMacroActions($state['params'], $macro, $sessionId);
	}

	private static function renderMacroPreview($macro, $params) {
		$lines = ["**{$macro['title']}** — review and confirm:"];
		foreach ($macro['actions'] as $action) {
			if (empty($action['always']) && !empty($action['if_set']) && empty($params[$action['if_set']])) continue;
			if (empty($action['always']) && empty($action['if_set'])) continue;
			$desc = $action['preview'] ?? $action['tool'];
			// Substitute $param tokens in the preview string
			$desc = preg_replace_callback('/\$([a-z_]+)/', function($m) use ($params) {
				return $params[$m[1]] ?? "({$m[1]} unset)";
			}, $desc);
			$lines[] = "  • {$desc}";
		}
		$lines[] = "\n{{cmd:yes|✅ Yes, do it all}} {{cmd:no|❌ Cancel}}";
		return implode("\n", $lines);
	}

	private static function runMacroActions($params, $macro, $sessionId) {
		$frogman = \FreePBX::Frogman();
		$results = [];
		foreach ($macro['actions'] as $action) {
			$shouldRun = !empty($action['always']);
			if (!$shouldRun && !empty($action['if_set'])) {
				$shouldRun = !empty($params[$action['if_set']]);
			}
			if (!$shouldRun) continue;

			$toolParams = [];
			foreach (($action['params'] ?? []) as $k => $v) {
				if (is_string($v) && substr($v, 0, 1) === '$') {
					$key = substr($v, 1);
					if (isset($params[$key]) && $params[$key] !== '') {
						$toolParams[$k] = $params[$key];
					}
				} else {
					$toolParams[$k] = $v;
				}
			}
			$toolParams['confirm'] = true;

			$result = $frogman->runTool($action['tool'], $toolParams, null, $sessionId);
			$status = $result['status'] ?? 'error';
			$results[] = [
				'tool' => $action['tool'],
				'status' => $status,
				'message' => $result['message'] ?? ($result['data']['message'] ?? ''),
				'data' => $result['data'] ?? null,
			];
			if ($status !== 'success' && !empty($action['critical'])) {
				return ['response' => self::renderMacroResults($macro, $params, $results, true)];
			}
		}
		return ['response' => self::renderMacroResults($macro, $params, $results, false)];
	}

	private static function renderMacroResults($macro, $params, $results, $aborted) {
		$lines = $aborted
			? ["**{$macro['title']} — aborted.** A required step failed; remaining steps were skipped."]
			: ["**{$macro['title']} — done.**"];
		$credentials = [];
		foreach ($results as $r) {
			$icon = $r['status'] === 'success' ? '✅' : '❌';
			// Use only the first line of the tool's message — keeps each bullet to one line.
			// The detailed messages get hoisted into a footer (credentials, etc.).
			$msg = $r['message'] ?: $r['tool'];
			$msg = preg_split('/\r?\n/', $msg, 2)[0];
			$lines[] = "  {$icon} `{$r['tool']}` — {$msg}";
			if ($r['tool'] === 'fm_add_extension' && !empty($r['data']['umpassword'])) {
				$credentials[] = "UCP password for `{$params['ext']}`: `{$r['data']['umpassword']}` — save it now. Reset later in User Manager or via the UCP \"Forgot Password\" link.";
			}
		}
		if (!empty($credentials)) {
			$lines[] = "\n**🔑 Credentials**";
			foreach ($credentials as $c) $lines[] = "  {$c}";
		}
		return implode("\n", $lines);
	}

	private static function getMacroDef($name) {
		if ($name === 'onboard_employee') {
			return [
				'name' => 'onboard_employee',
				'title' => 'Onboard new employee',
				'steps' => [
					['kind' => 'prompt', 'param' => 'ext', 'prompt' => 'Extension number for the new hire? (e.g. `1010`)'],
					['kind' => 'prompt', 'param' => 'name', 'prompt' => 'Full name? (e.g. `Jane Smith`)'],
					['kind' => 'prompt', 'param' => 'email', 'prompt' => 'Email address? (used for voicemail-to-email and UCP password reset, or {{cmd:skip|⏭ Skip}})', 'skip_default' => null],

					// Follow Me: gate skips next 2 steps
					['kind' => 'gate', 'prompt' => 'Set up Follow Me (ring desk + cell)? {{cmd:yes|✅ Yes}} {{cmd:no|⏭ Skip}}', 'skip_count' => 2],
					['kind' => 'prompt', 'param' => 'fm_numbers', 'prompt' => 'Numbers to ring (comma-separated, include their extension first): e.g. `1010,5551234567`'],
					['kind' => 'prompt', 'param' => 'fm_ringtime', 'prompt' => 'Ring time? {{cmd:15|15s}} {{cmd:20|20s}} {{cmd:30|30s}} {{cmd:45|45s}} {{cmd:60|60s}} {{cmd:skip|⏭ Default (20s)}}', 'skip_default' => null],

					// Ring group: gate skips next 1
					['kind' => 'gate', 'prompt' => 'Add to a ring group? {{cmd:yes|✅ Yes}} {{cmd:no|⏭ Skip}}', 'skip_count' => 1],
					['kind' => 'prompt', 'param' => 'rg_id', 'prompt' => 'Which ring group ID? (e.g. `600`)'],

					// Inbound DID: gate skips next 2
					['kind' => 'gate', 'prompt' => 'Assign an inbound DID? {{cmd:yes|✅ Yes}} {{cmd:no|⏭ Skip}}', 'skip_count' => 2],
					['kind' => 'prompt', 'param' => 'did_number', 'prompt' => 'DID to route to this extension?'],
					['kind' => 'prompt', 'param' => 'did_description', 'prompt' => 'Description for the DID? Optional, or {{cmd:skip|⏭ Skip}}.', 'skip_default' => null],

					// Outbound CID: gate skips next 1
					['kind' => 'gate', 'prompt' => 'Set outbound caller ID? {{cmd:yes|✅ Yes}} {{cmd:no|⏭ Skip}}', 'skip_count' => 1],
					['kind' => 'prompt', 'param' => 'out_cid', 'prompt' => 'Outbound caller ID number?'],

					// Final preview / confirm
					['kind' => 'confirm'],
				],
				'actions' => [
					[
						'tool' => 'fm_add_extension',
						'params' => ['ext' => '$ext', 'name' => '$name', 'email' => '$email'],
						'preview' => 'Create extension `$ext` ($name)',
						'always' => true,
						'critical' => true,
					],
					[
						'tool' => 'fm_set_followme',
						'params' => ['ext' => '$ext', 'numbers' => '$fm_numbers', 'ringtime' => '$fm_ringtime'],
						'preview' => 'Follow Me on `$ext` → ring `$fm_numbers`',
						'if_set' => 'fm_numbers',
					],
					[
						'tool' => 'fm_ringgroup_add_member',
						'params' => ['id' => '$rg_id', 'member' => '$ext'],
						'preview' => 'Add `$ext` to ring group `$rg_id`',
						'if_set' => 'rg_id',
					],
					[
						'tool' => 'fm_add_inbound_route',
						'params' => ['extension' => '$did_number', 'destination' => '$ext', 'description' => '$did_description'],
						'preview' => 'Inbound route DID `$did_number` → `$ext`',
						'if_set' => 'did_number',
					],
					[
						'tool' => 'fm_set_caller_id',
						'params' => ['ext' => '$ext', 'cid' => '$out_cid'],
						'preview' => 'Outbound CID on `$ext` → `$out_cid`',
						'if_set' => 'out_cid',
					],
					[
						'tool' => 'fm_reload',
						'params' => [],
						'preview' => 'Apply config changes (reload)',
						'always' => true,
					],
				],
			];
		}
		return null;
	}

	public static function parse($message, $sessionId = 'default', $skipFuzzy = false) {
		$msg = trim($message);
		$lower = strtolower($msg);

		// ── Free-text Input Prompt (e.g. "what email?") + Multi-step Wizard + Macro ──
		// Must come BEFORE yes/no so a yes-as-value still works as input.
		$inputPending = self::getInputPending($sessionId);
		if ($inputPending) {
			$isSkip = (bool)preg_match('/^(no|cancel|skip|nevermind|nope|abort)$/i', $msg);
			$isCorrectionCancel = Interpret::isCorrectionCancel($msg);
			$isWizard = ($inputPending['type'] ?? 'input') === 'wizard';
			$isMacro = ($inputPending['type'] ?? 'input') === 'macro';

			if ($isMacro) {
				return self::advanceMacro($sessionId, $msg);
			}

			if ($isCorrectionCancel) {
				self::clearPending($sessionId);
				return ['response' => 'OK, cancelled. Try again with the command you want.'];
			}

			if ($isWizard) {
				$prompts = $inputPending['prompts'] ?? [];
				$current = array_shift($prompts);
				if (!$current) {
					self::clearPending($sessionId);
					return ['response' => 'Cancelled.'];
				}
				if ($isSkip) {
					if (array_key_exists('skip_default', $current)) {
						if ($current['skip_default'] !== null) {
							$inputPending['params'][$current['param']] = $current['skip_default'];
						}
						// fall through to advance / finish
					} else {
						self::clearPending($sessionId);
						return ['response' => 'OK, cancelled. (That step was required.)'];
					}
				} else {
					$inputPending['params'][$current['param']] = $msg;
				}
				if (!empty($prompts)) {
					self::setInputWizard($sessionId, $inputPending['tool'], $inputPending['params'], $prompts);
					return ['response' => $prompts[0]['prompt']];
				}
				// All prompts answered — fire the tool WITHOUT confirm so its dry-run preview shows.
				self::clearPending($sessionId);
				self::setPending($sessionId, $inputPending['tool'], $inputPending['params']);
				return ['tool' => $inputPending['tool'], 'params' => $inputPending['params']];
			}

			// Single-input flow (existing)
			if ($isSkip) {
				self::clearPending($sessionId);
				return ['response' => 'OK, skipped.'];
			}
			self::clearPending($sessionId);
			$param = $inputPending['input_param'] ?? 'value';
			$inputPending['params'][$param] = $msg;
			$inputPending['params']['confirm'] = true;
			return ['tool' => $inputPending['tool'], 'params' => $inputPending['params']];
		}

		// ── Confirm / Cancel ──
		$pending = self::getPending($sessionId);
		if ($pending && preg_match('/^(yes|y|confirm|do it|go|go ahead|ok|sure|yep|yeah)$/i', $msg)) {
			self::clearPending($sessionId);
			// If pending has needs_input, transition to input-prompt instead of firing the tool
			if (!empty($pending['needs_input'])) {
				$param = $pending['needs_input'];
				$prompt = $pending['input_prompt'] ?? "What's the {$param}?";
				self::setInputPrompt($sessionId, $pending['tool'], $pending['params'], $param);
				return ['response' => $prompt];
			}
			$isFollowUp = !empty($pending['type']) && $pending['type'] === 'followup';
			if ($isFollowUp) {
				$pending['params']['confirm'] = true;
				return ['tool' => $pending['tool'], 'params' => $pending['params']];
			}
			$pending['params']['confirm'] = true;
			return ['tool' => $pending['tool'], 'params' => $pending['params']];
		}
		if ($pending && (preg_match('/^(no|n|cancel|nevermind|nope|nah|abort)$/i', $msg) || Interpret::isCorrectionCancel($msg))) {
			self::clearPending($sessionId);
			$isFollowUp = !empty($pending['type']) && $pending['type'] === 'followup';
			return ['response' => $isFollowUp ? 'OK, no problem.' : 'Cancelled.'];
		}

		// ── Help ──
		if (preg_match('/^(help|commands|tools|\?)$/i', $msg)) {
			return ['response' => self::helpText()];
		}

		// ── Connection Guide ──
		if (preg_match('/^(connect|mcp|mcp\s*config|how\s+to\s+connect|connection\s*guide|setup\s+mcp|api\s+config)$/i', $lower)) {
			return ['tool' => 'fm_get_mcp_config', 'params' => []];
		}

		// ── Dashboard ──
		if (preg_match('/^(status|dashboard|overview|how.s\s+(my|the)\s+(pbx|system))$/i', $lower)) {
			return ['tool' => 'fm_system_dashboard', 'params' => []];
		}

		// ── Export ──
		if (preg_match('/^export\s+(extensions?|ringgroups?|dids?|trunks?|cdr|queues?)$/i', $msg, $m)) {
			$type = strtolower($m[1]);
			$type = rtrim($type, 's') . 's'; // normalize plural
			if ($type === 'dids') $type = 'dids';
			return ['tool' => 'fm_export', 'params' => ['type' => $type]];
		}

		// ── CDR Analytics ──
		if (preg_match('/^(cdr\s+)?stats$/i', $lower)) {
			return ['tool' => 'fm_get_cdr_stats', 'params' => []];
		}
		if (preg_match('/^(busiest|top)\s+(ext|extensions?)$/i', $lower)) {
			return ['tool' => 'fm_get_busiest_extensions', 'params' => []];
		}
		if (preg_match('/^peak\s+hours?$/i', $lower)) {
			return ['tool' => 'fm_get_peak_hours', 'params' => []];
		}
		// DID destination map — Mermaid flowchart LR of every DID and where it terminates.
		// Optional "filter X" (DID/description match) and "to Y" (destination match).
		if (preg_match('/^(?:did\s+(?:destination\s+)?map|inbound\s+map|show\s+(?:did\s+)?(?:destination\s+)?map|where\s+do\s+(?:my\s+)?dids\s+go)(?:\s+filter\s+(\S+))?(?:\s+to\s+(.+))?$/i', $msg, $m)) {
			$params = [];
			if (!empty($m[1])) $params['filter'] = $m[1];
			if (!empty($m[2])) $params['to'] = trim($m[2]);
			return ['tool' => 'fm_did_destination_map', 'params' => $params];
		}
		if (preg_match('/^failed\s+calls?$/i', $lower)) {
			return ['tool' => 'fm_get_failed_calls', 'params' => []];
		}
		if (preg_match('/^disk\s+space$/i', $lower)) {
			return ['tool' => 'fm_get_disk_space', 'params' => []];
		}
		if (preg_match('/^(sys|system)\s+info$/i', $lower)) {
			return ['tool' => 'fm_get_sys_info', 'params' => []];
		}

		// ── Call Flow Trace ──
		if (preg_match('/^(?:trace|show|get)\s+(?:call\s*)?flow\s+(?:for\s+)?(?:did\s+)?(\S+)$/i', $msg, $m)) {
			$val = $m[1];
			$key = preg_match('/^\d{7,}$/', $val) ? 'did' : 'ext';
			return ['tool' => 'fm_trace_call_flow', 'params' => [$key => $val]];
		}
		if (preg_match('/^(?:where\s+does|how\s+does)\s+(?:did\s+)?(\S+)\s+(?:go|route|ring)/i', $msg, $m)) {
			$val = $m[1];
			$key = preg_match('/^\d{7,}$/', $val) ? 'did' : 'ext';
			return ['tool' => 'fm_trace_call_flow', 'params' => [$key => $val]];
		}

		// ── Who's on the phone ──
		if (preg_match('/^(who.s\s+on\s+the\s+phone|who.s\s+talking|who.s\s+on\s+a\s+call|current\s+calls|live\s+calls)$/i', $lower)) {
			return ['tool' => 'fm_whos_on_the_phone', 'params' => []];
		}

		// ── Search ──
		if (preg_match('/^(kb|docs|knowledge\s*base|how\s+do\s+i|how\s+to|troubleshoot)\s+(.+)$/i', $msg, $m)) {
			return ['tool' => 'fm_search_docs', 'params' => ['query' => trim($m[2])]];
		}
		if (preg_match('/^(search|find|where\s+is|who\s+is)\s+(.+)$/i', $msg, $m)) {
			return ['tool' => 'fm_search', 'params' => ['query' => trim($m[2])]];
		}

		// ── Onboard new employee (macro wizard) ──
		// Has to come before generic create-extension patterns so it isn't shadowed.
		if (preg_match('/^(onboard(\s+(new\s+)?(employee|hire|user|person))?|new\s+(employee|hire))$/i', $lower)) {
			return self::setMacro($sessionId, 'onboard_employee');
		}

		// ── Extensions ──
		if (preg_match('/^(list|show|get)\s+(all\s+)?(ext|extensions?)$/i', $lower)) {
			return ['tool' => 'fm_list_extensions', 'params' => []];
		}
		// Digit-only "show extension <num>" must come BEFORE the search pattern below,
		// otherwise "show extension 100" hits the list-with-search and substring-matches
		// 100, 1000, 1001, etc.
		if (preg_match('/^(get|show|info|details?)\s+(ext|extension)\s+(\d+)$/i', $msg, $m)) {
			return ['tool' => 'fm_get_extension', 'params' => ['ext' => $m[3]]];
		}
		if (preg_match('/^(list|show|search|find)\s+(ext|extensions?)\s+(.+)$/i', $msg, $m)) {
			return ['tool' => 'fm_list_extensions', 'params' => ['search' => trim($m[3])]];
		}
		if (preg_match('/^(health|status|check)\s+(ext|extension)?\s*(\d+)$/i', $msg, $m)) {
			return ['tool' => 'fm_get_extension_health', 'params' => ['ext' => $m[3]]];
		}
		if (preg_match('/^(health|status)\s+check\s+(on\s+)?(\d+)$/i', $msg, $m)) {
			return ['tool' => 'fm_get_extension_health', 'params' => ['ext' => $m[3]]];
		}
		// ── Combo: extension + email (must come before name-only patterns to avoid the trailing-words greedy match) ──
		if (preg_match('/^(create|add|new)\s+(ext|extension)\s+(\d+)\s+(?:for\s+|named?\s+)?(.+?)\s+email\s+(\S+@\S+\.\S+)$/i', $msg, $m)) {
			$params = ['ext' => $m[3], 'name' => rtrim(trim($m[4]), '.'), 'email' => $m[5]];
			self::setPending($sessionId, 'fm_add_extension', $params);
			return ['tool' => 'fm_add_extension', 'params' => $params];
		}

		// ── Set extension email standalone ──
		// "set email 1099 foo@bar.com" / "set extension email 1099 foo@bar.com" / "email 1099 foo@bar.com"
		if (preg_match('/^(?:set\s+)?(?:extension\s+)?email\s+(?:for\s+)?(\d+)\s+(\S+@\S+\.\S+)$/i', $msg, $m)) {
			$params = ['ext' => $m[1], 'email' => $m[2]];
			self::setPending($sessionId, 'fm_set_extension_email', $params);
			return ['tool' => 'fm_set_extension_email', 'params' => $params];
		}

		// ── Combo: extension + voicemail ──
		if (preg_match('/^(create|add|new)\s+(ext|extension)\s+(\d+)\s+(?:(?:with|and)\s+)?(?:voicemail|vm)\s+(?:for\s+|named?\s+)?(.+)$/i', $msg, $m)) {
			$params = ['ext' => $m[3], 'name' => rtrim(trim($m[4]), '.'), 'vm' => 'yes'];
			self::setPending($sessionId, 'fm_add_extension', $params);
			return ['tool' => 'fm_add_extension', 'params' => $params];
		}
		if (preg_match('/^(create|add|new)\s+(ext|extension)\s+(\d+)\s+(?:for\s+|named?\s+)?(.+?)\s+(?:with|and)\s+(?:voicemail|vm)$/i', $msg, $m)) {
			$params = ['ext' => $m[3], 'name' => rtrim(trim($m[4]), '.'), 'vm' => 'yes'];
			self::setPending($sessionId, 'fm_add_extension', $params);
			return ['tool' => 'fm_add_extension', 'params' => $params];
		}

		// ── Combo: extension + forward ──
		if (preg_match('/^(create|add|new)\s+(ext|extension)\s+(\d+)\s+(?:for\s+|named?\s+)?(.+?)\s+(?:and\s+)?forward\s+(?:to\s+)?(\d+)$/i', $msg, $m)) {
			$params = ['ext' => $m[3], 'name' => rtrim(trim($m[4]), '.'), '_chain_forward' => $m[5]];
			self::setPending($sessionId, 'fm_add_extension', $params);
			return ['tool' => 'fm_add_extension', 'params' => $params];
		}

		// ── Combo: extension + ring group ──
		if (preg_match('/^(create|add|new)\s+(ext|extension)\s+(\d+)\s+(?:for\s+|named?\s+)?(.+?)\s+(?:and\s+)?(?:add\s+to\s+)?(?:ring\s*group|rg)\s+(\d+)$/i', $msg, $m)) {
			$params = ['ext' => $m[3], 'name' => rtrim(trim($m[4]), '.'), '_chain_ringgroup' => $m[5]];
			self::setPending($sessionId, 'fm_add_extension', $params);
			return ['tool' => 'fm_add_extension', 'params' => $params];
		}

		if (preg_match('/^(create|add|new)\s+(ext|extension)\s+(\d+)\s+(?:for\s+|named?\s+)?(.+)$/i', $msg, $m)) {
			$params = ['ext' => $m[3], 'name' => rtrim(trim($m[4]), '.') ];
			self::setPending($sessionId, 'fm_add_extension', $params);
			return ['tool' => 'fm_add_extension', 'params' => $params];
		}
		if (preg_match('/^(create|add|new)\s+(ext|extension)\s+(\d+)$/i', $msg, $m)) {
			$params = ['ext' => $m[3], 'name' => 'Extension ' . $m[3]];
			self::setPending($sessionId, 'fm_add_extension', $params);
			return ['tool' => 'fm_add_extension', 'params' => $params];
		}
		if (preg_match('/^(update|rename|change)\s+(ext|extension)\s+(\d+)\s+(?:to\s+|name\s+)(.+)$/i', $msg, $m)) {
			$params = ['ext' => $m[3], 'name' => rtrim(trim($m[4]), '.')];
			self::setPending($sessionId, 'fm_update_extension', $params);
			return ['tool' => 'fm_update_extension', 'params' => $params];
		}
		if (preg_match('/^(delete|remove|disable|drop)\s+(ext|extension)\s+(\d+)$/i', $msg, $m)) {
			$params = ['ext' => $m[3]];
			self::setPending($sessionId, 'fm_disable_extension', $params);
			return ['tool' => 'fm_disable_extension', 'params' => $params];
		}

		// ── Calls & CDR ──
		if (preg_match('/^(show\s+)?(active\s+)?calls$|^who.s\s+(on\s+)?(the\s+)?phone/i', $lower)) {
			return ['tool' => 'fm_list_active_calls', 'params' => []];
		}
		if (preg_match('/^(show\s+|get\s+)?(cdr|call\s+(log|history|records?))(\s+(\d+))?$/i', $msg, $m)) {
			$params = [];
			if (!empty($m[5])) $params['limit'] = (int) $m[5];
			return ['tool' => 'fm_get_cdr', 'params' => $params];
		}
		if (preg_match('/^(calls?|cdr)\s+(from|for|to)\s+(\d+)$/i', $msg, $m)) {
			$key = strtolower($m[2]) === 'to' ? 'dst' : 'src';
			return ['tool' => 'fm_get_cdr', 'params' => [$key => $m[3]]];
		}

		// ── Trunks ──
		if (preg_match('/^(list|show|get)\s+(all\s+)?trunks?$/i', $lower)) {
			return ['tool' => 'fm_list_trunks', 'params' => []];
		}
		if (preg_match('/^(show|get|status)\s+trunk\s+(\d+)$/i', $msg, $m)) {
			return ['tool' => 'fm_get_trunk_status', 'params' => ['id' => $m[2]]];
		}

		// ── Ring Groups ──
		if (preg_match('/^(list|show|get)\s+(all\s+)?(ring\s*groups?|rg)$/i', $lower)) {
			return ['tool' => 'fm_list_ringgroups', 'params' => []];
		}
		if (preg_match('/^(show|get|info)\s+(ring\s*group|rg)\s+(\d+)$/i', $msg, $m)) {
			return ['tool' => 'fm_get_ringgroup', 'params' => ['id' => $m[3]]];
		}
		if (preg_match('/^add\s+(\d+)\s+to\s+(ring\s*group|rg)\s+(\d+)$/i', $msg, $m)) {
			$params = ['id' => $m[3], 'member' => $m[1]];
			self::setPending($sessionId, 'fm_ringgroup_add_member', $params);
			return ['tool' => 'fm_ringgroup_add_member', 'params' => $params];
		}
		if (preg_match('/^remove\s+(\d+)\s+from\s+(ring\s*group|rg)\s+(\d+)$/i', $msg, $m)) {
			$params = ['id' => $m[3], 'member' => $m[1]];
			self::setPending($sessionId, 'fm_ringgroup_remove_member', $params);
			return ['tool' => 'fm_ringgroup_remove_member', 'params' => $params];
		}

		// ── Follow Me ──
		if (preg_match('/^(set|configure|enable|setup)\s+follow\s*me(?:\s+(?:on\s+|for\s+)?(\d+))?$/i', $msg, $m)) {
			$preset = !empty($m[2]) ? ['ext' => $m[2]] : [];
			$prompts = [];
			if (empty($preset['ext'])) {
				$prompts[] = ['param' => 'ext', 'prompt' => 'Which extension? (e.g. `1001`)'];
			}
			$prompts[] = ['param' => 'numbers', 'prompt' => "What numbers should ring? (comma-separated, e.g. `1001,5551234567`)"];
			$prompts[] = ['param' => 'ringtime', 'prompt' => "Ring time? {{cmd:15|15s}} {{cmd:20|20s}} {{cmd:30|30s}} {{cmd:45|45s}} {{cmd:60|60s}} {{cmd:skip|⏭ Default (20s)}}", 'skip_default' => null];
			self::setInputWizard($sessionId, 'fm_set_followme', $preset, $prompts);
			return ['response' => $prompts[0]['prompt']];
		}
		if (preg_match('/^(set|configure|enable)\s+follow\s*me\s+(on\s+|for\s+)?(\d+)\s+(?:to\s+|ring\s+)?(.+)$/i', $msg, $m)) {
			$params = ['ext' => $m[3], 'numbers' => trim($m[4])];
			self::setPending($sessionId, 'fm_set_followme', $params);
			return ['tool' => 'fm_set_followme', 'params' => $params];
		}
		if (preg_match('/^(clear|remove|disable|delete)\s+follow\s*me\s+(on\s+|for\s+|from\s+)?(\d+)$/i', $msg, $m)) {
			$params = ['ext' => $m[3]];
			self::setPending($sessionId, 'fm_clear_followme', $params);
			return ['tool' => 'fm_clear_followme', 'params' => $params];
		}

		// ── Call Forward ──
		if (preg_match('/^(forward|fwd)\s+(ext|extension)?\s*(\d+)\s+(?:to\s+)(\d+)$/i', $msg, $m)) {
			$params = ['ext' => $m[3], 'number' => $m[4]];
			self::setPending($sessionId, 'fm_set_call_forward', $params);
			return ['tool' => 'fm_set_call_forward', 'params' => $params];
		}
		if (preg_match('/^(set\s+)?call\s*forward\s+(on\s+|for\s+)?(\d+)\s+(?:to\s+)(\d+)/i', $msg, $m)) {
			$params = ['ext' => $m[3], 'number' => $m[4]];
			self::setPending($sessionId, 'fm_set_call_forward', $params);
			return ['tool' => 'fm_set_call_forward', 'params' => $params];
		}
		if (preg_match('/^(clear|remove|cancel|disable)\s+(call\s*)?forward\s*(on\s+|for\s+|from\s+)?(\d+)$/i', $msg, $m)) {
			$params = ['ext' => $m[4]];
			self::setPending($sessionId, 'fm_clear_call_forward', $params);
			return ['tool' => 'fm_clear_call_forward', 'params' => $params];
		}
		if (preg_match('/^(show|get|check)\s+(call\s*)?forward\s*(on\s+|for\s+)?(\d+)$/i', $msg, $m)) {
			return ['tool' => 'fm_get_call_forward', 'params' => ['ext' => $m[4]]];
		}

		// ── DND ──
		if (preg_match('/^(enable|set|turn\s+on)\s+dnd\s+(on\s+|for\s+)?(\d+)$/i', $msg, $m)) {
			$params = ['ext' => $m[3], 'state' => 'on'];
			self::setPending($sessionId, 'fm_toggle_dnd', $params);
			return ['tool' => 'fm_toggle_dnd', 'params' => $params];
		}
		if (preg_match('/^(disable|clear|turn\s+off|remove)\s+dnd\s+(on\s+|for\s+|from\s+)?(\d+)$/i', $msg, $m)) {
			$params = ['ext' => $m[3], 'state' => 'off'];
			self::setPending($sessionId, 'fm_toggle_dnd', $params);
			return ['tool' => 'fm_toggle_dnd', 'params' => $params];
		}
		if (preg_match('/^(toggle)\s+dnd\s+(on\s+|for\s+)?(\d+)$/i', $msg, $m)) {
			$params = ['ext' => $m[3]];
			self::setPending($sessionId, 'fm_toggle_dnd', $params);
			return ['tool' => 'fm_toggle_dnd', 'params' => $params];
		}
		if (preg_match('/^(show|get|check)\s+dnd\s+(on\s+|for\s+)?(\d+)$/i', $msg, $m)) {
			return ['tool' => 'fm_get_dnd', 'params' => ['ext' => $m[3]]];
		}

		// ── Caller ID ──
		if (preg_match('/^set\s+(caller\s*id|cid|outbound\s*cid)\s+(on\s+|for\s+)?(\d+)\s+(?:to\s+)?(\S+)$/i', $msg, $m)) {
			$params = ['ext' => $m[3], 'cid' => $m[4]];
			self::setPending($sessionId, 'fm_set_caller_id', $params);
			return ['tool' => 'fm_set_caller_id', 'params' => $params];
		}
		if (preg_match('/^(clear|remove)\s+(caller\s*id|cid|outbound\s*cid)\s+(on\s+|for\s+|from\s+)?(\d+)$/i', $msg, $m)) {
			$params = ['ext' => $m[4], 'cid' => ''];
			self::setPending($sessionId, 'fm_set_caller_id', $params);
			return ['tool' => 'fm_set_caller_id', 'params' => $params];
		}

		// ── Recording ──
		if (preg_match("/^(enable|set|turn\\s+on)\\s+(?:call\\s+)?(recording|record)\\s+(on\\s+|for\\s+)?(\\d+)(\\s+(?:to\\s+)?(force|yes|don'?t\\s*care|dontcare|no|never|always|on|off|enable|enabled|disable|disabled))?$/i", $msg, $m)) {
			$mode = !empty($m[6]) ? strtolower(preg_replace("/[^a-z]/i", '', $m[6])) : 'always';
			$params = ['ext' => $m[4], 'mode' => $mode];
			self::setPending($sessionId, 'fm_set_recording', $params);
			return ['tool' => 'fm_set_recording', 'params' => $params];
		}
		if (preg_match('/^(disable|stop|turn\s+off)\s+(?:call\s+)?(recording|record)\s+(on\s+|for\s+)?(\d+)$/i', $msg, $m)) {
			$params = ['ext' => $m[4], 'mode' => 'never'];
			self::setPending($sessionId, 'fm_set_recording', $params);
			return ['tool' => 'fm_set_recording', 'params' => $params];
		}

		// ── Ring Timer ──
		if (preg_match('/^set\s+ring\s*(?:time(?:r|out)?)\s+(on\s+|for\s+)?(\d+)\s+(?:to\s+)?(\d+)s?$/i', $msg, $m)) {
			$params = ['ext' => $m[2], 'seconds' => (int)$m[3]];
			self::setPending($sessionId, 'fm_set_ringtimer', $params);
			return ['tool' => 'fm_set_ringtimer', 'params' => $params];
		}

		// ── Route Patterns ──
		if (preg_match('/^(show|get)\s+(route\s+)?patterns?\s+(for\s+)?(?:route\s+)?(\d+)$/i', $msg, $m)) {
			return ['tool' => 'fm_get_route_patterns', 'params' => ['id' => $m[4]]];
		}

		// ── All DIDs ──
		if (preg_match('/^(list|show)\s+(all\s+)?dids$/i', $lower)) {
			return ['tool' => 'fm_list_all_dids', 'params' => []];
		}

		// ── API Tokens ──
		if (preg_match('/^(create|generate)\s+(api\s+)?token\s+(?:for\s+)?(\S+)(?:\s+(?:as|with|level)\s+(read|write|admin))?$/i', $msg, $m)) {
			$params = ['username' => $m[3], 'level' => $m[4] ?? 'read'];
			self::setPending($sessionId, 'fm_create_api_token', $params);
			return ['tool' => 'fm_create_api_token', 'params' => $params];
		}
		if (preg_match('/^(list|show)\s+(api\s+)?tokens?$/i', $lower)) {
			return ['tool' => 'fm_list_api_tokens', 'params' => []];
		}
		if (preg_match('/^revoke\s+(api\s+)?token\s+(\d+)$/i', $msg, $m)) {
			$params = ['id' => $m[2]];
			self::setPending($sessionId, 'fm_revoke_api_token', $params);
			return ['tool' => 'fm_revoke_api_token', 'params' => $params];
		}
		if (preg_match('/^delete\s+(api\s+)?token\s+(\d+)$/i', $msg, $m)) {
			$params = ['id' => $m[2]];
			self::setPending($sessionId, 'fm_delete_api_token', $params);
			return ['tool' => 'fm_delete_api_token', 'params' => $params];
		}

		// ── Admin Users ──
		if (preg_match('/^create\s+admin\s+(\S+)(?:\s+(?:for|named?)\s+(.+))?$/i', $msg, $m)) {
			$params = ['username' => $m[1]];
			if (!empty($m[2])) $params['name'] = rtrim(trim($m[2]), '.');
			self::setPending($sessionId, 'fm_create_admin', $params);
			return ['tool' => 'fm_create_admin', 'params' => $params];
		}
		if (preg_match('/^reset\s+password\s+(?:for\s+)?(\S+)$/i', $msg, $m)) {
			$params = ['username' => $m[1]];
			self::setPending($sessionId, 'fm_reset_password', $params);
			return ['tool' => 'fm_reset_password', 'params' => $params];
		}
		// Delete a User Manager user. Accept either a numeric uid or a username —
		// the tool routes either to the right BMO lookup.
		if (preg_match('/^(?:delete|remove)\s+(?:user\s*manager|userman)\s+user\s+(\S+)$/i', $msg, $m)) {
			$target = $m[1];
			$params = preg_match('/^\d+$/', $target) ? ['id' => $target] : ['username' => $target];
			self::setPending($sessionId, 'fm_delete_userman_user', $params);
			return ['tool' => 'fm_delete_userman_user', 'params' => $params];
		}

		// ── Allowlist ──
		if (preg_match('/^(list|show)\s+(all\s+)?(allowlist|allow\s*list|whitelist|allowed|allowed\s+numbers?)$/i', $lower)) {
			return ['tool' => 'fm_list_allowlist', 'params' => []];
		}
		if (preg_match('/^(allow|whitelist)\s+(number\s+)?(\d+)$/i', $msg, $m)) {
			$params = ['number' => $m[3]];
			self::setPending($sessionId, 'fm_add_allowlist', $params);
			return ['tool' => 'fm_add_allowlist', 'params' => $params];
		}
		if (preg_match('/^(unallow|remove\s+from\s+allowlist|remove\s+allowed)\s+(number\s+)?(\d+)$/i', $msg, $m)) {
			$params = ['number' => $m[3]];
			self::setPending($sessionId, 'fm_remove_allowlist', $params);
			return ['tool' => 'fm_remove_allowlist', 'params' => $params];
		}

		// ── Contacts ──
		if (preg_match('/^(list|show)\s+(all\s+)?(contacts?|contact\s*groups?|phonebook|directory)$/i', $lower)) {
			return ['tool' => 'fm_list_contacts', 'params' => []];
		}
		if (preg_match('/^(show|get)\s+contacts?\s+(in\s+|for\s+)?group\s+(\d+)$/i', $msg, $m)) {
			return ['tool' => 'fm_list_contacts', 'params' => ['group_id' => $m[3]]];
		}
		if (preg_match('/^(list|show)\s+(all\s+)?speed\s*dials?$/i', $lower)) {
			return ['tool' => 'fm_list_speed_dials', 'params' => []];
		}

		// ── Blacklist ──
		if (preg_match('/^(list|show)\s+(all\s+)?(blacklist|blocked|blocked\s+numbers?)$/i', $lower)) {
			return ['tool' => 'fm_list_blacklist', 'params' => []];
		}
		if (preg_match('/^(block|blacklist|ban)\s+(number\s+)?(\d+)$/i', $msg, $m)) {
			$params = ['number' => $m[3]];
			self::setPending($sessionId, 'fm_add_blacklist', $params);
			return ['tool' => 'fm_add_blacklist', 'params' => $params];
		}
		if (preg_match('/^(unblock|unblacklist|unban)\s+(number\s+)?(\d+)$/i', $msg, $m)) {
			$params = ['number' => $m[3]];
			self::setPending($sessionId, 'fm_remove_blacklist', $params);
			return ['tool' => 'fm_remove_blacklist', 'params' => $params];
		}

		// ── Callbacks ──
		if (preg_match('/^(list|show)\s+(all\s+)?callbacks?$/i', $lower)) {
			return ['tool' => 'fm_list_callbacks', 'params' => []];
		}

		// ── CID Lookup ──
		if (preg_match('/^(list|show)\s+(all\s+)?(cid\s*lookup|caller\s*id\s*lookup)s?$/i', $lower)) {
			return ['tool' => 'fm_list_cid_lookup', 'params' => []];
		}

		// ── Parking Lot Detail ──
		if (preg_match('/^(show|get)\s+parking\s*(lot)?\s+(\d+)$/i', $msg, $m)) {
			return ['tool' => 'fm_get_parking_lot', 'params' => ['id' => $m[3]]];
		}

		// ── Page Group Detail ──
		if (preg_match('/^(show|get)\s+(page|paging)\s*(group)?\s+(\d+)$/i', $msg, $m)) {
			return ['tool' => 'fm_get_page_group', 'params' => ['id' => $m[4]]];
		}

		// ── Queue Details ──
		if (preg_match('/^(show|get)\s+queue\s+details?\s+(\d+)$/i', $msg, $m)) {
			return ['tool' => 'fm_get_queue_details', 'params' => ['id' => $m[2]]];
		}

		// ── Inbound Routes ──
		if (preg_match('/^(list|show)\s+(all\s+)?(inbound|incoming)\s*(routes?|dids?)?$/i', $lower)) {
			return ['tool' => 'fm_list_inbound_routes', 'params' => []];
		}
		if (preg_match('/^(list|show)\s+(all\s+)?dids?$/i', $lower)) {
			return ['tool' => 'fm_list_inbound_routes', 'params' => []];
		}

		// ── Outbound Routes ──
		if (preg_match('/^(list|show)\s+(all\s+)?(outbound|outgoing)\s*(routes?)?$/i', $lower)) {
			return ['tool' => 'fm_list_outbound_routes', 'params' => []];
		}
		if (preg_match('/^(show|get)\s+(outbound\s+)?route\s+(\d+)$/i', $msg, $m)) {
			return ['tool' => 'fm_get_outbound_route', 'params' => ['id' => $m[3]]];
		}

		// ── Queues ──
		if (preg_match('/^(list|show)\s+(all\s+)?queues?$/i', $lower)) {
			return ['tool' => 'fm_list_queues', 'params' => []];
		}
		if (preg_match('/^(show|get)\s+queue\s+(\d+)$/i', $msg, $m)) {
			return ['tool' => 'fm_get_queue', 'params' => ['id' => $m[2]]];
		}

		// ── Time Conditions ──
		if (preg_match('/^(list|show)\s+(all\s+)?time\s*conditions?$/i', $lower)) {
			return ['tool' => 'fm_list_time_conditions', 'params' => []];
		}
		if (preg_match('/^toggle\s+time\s*condition\s+(\d+)$/i', $msg, $m)) {
			$params = ['id' => $m[1]];
			self::setPending($sessionId, 'fm_toggle_time_condition', $params);
			return ['tool' => 'fm_toggle_time_condition', 'params' => $params];
		}

		// ── Time Group Detail ──
		if (preg_match('/^(show|get)\s+time\s*group\s+(\d+)$/i', $msg, $m)) {
			return ['tool' => 'fm_get_time_group', 'params' => ['id' => $m[2]]];
		}

		// ── Day/Night ──
		if (preg_match('/^(list|show)\s+(all\s+)?(day\s*\/?\s*night|call\s*flows?)$/i', $lower)) {
			return ['tool' => 'fm_list_daynight', 'params' => []];
		}
		if (preg_match('/^toggle\s+(day\s*\/?\s*night|call\s*flow)\s+(\d+)$/i', $msg, $m)) {
			$params = ['id' => $m[2]];
			self::setPending($sessionId, 'fm_toggle_daynight', $params);
			return ['tool' => 'fm_toggle_daynight', 'params' => $params];
		}
		if (preg_match('/^(set)\s+(day\s*\/?\s*night|call\s*flow)\s+(\d+)\s+(?:to\s+)?(day|night)$/i', $msg, $m)) {
			$params = ['id' => $m[3], 'state' => strtolower($m[4])];
			self::setPending($sessionId, 'fm_toggle_daynight', $params);
			return ['tool' => 'fm_toggle_daynight', 'params' => $params];
		}

		// ── Voicemail ──
		if (preg_match('/^(list|show)\s+(all\s+)?voicemail\s+settings?$/i', $lower)) {
			return ['tool' => 'fm_list_voicemail', 'params' => ['type' => 'settings']];
		}
		if (preg_match('/^(list|show)\s+(all\s+)?voicemail(s|\s+boxes?)?$/i', $lower)) {
			return ['tool' => 'fm_list_voicemail', 'params' => []];
		}
		if (preg_match('/^(show|get|check)\s+voicemail\s+(for\s+|on\s+)?(\d+)$/i', $msg, $m)) {
			return ['tool' => 'fm_get_voicemail', 'params' => ['ext' => $m[3]]];
		}

		// ── Conferences ──
		if (preg_match('/^(list|show)\s+(all\s+)?(conferences?|conf\s*(rooms?|bridges?)?)$/i', $lower)) {
			return ['tool' => 'fm_list_conferences', 'params' => []];
		}
		if (preg_match('/^(show|get)\s+(conference|conf)\s+(room\s+)?(\d+)$/i', $msg, $m)) {
			return ['tool' => 'fm_get_conference', 'params' => ['id' => $m[4]]];
		}

		// ── Paging ──
		if (preg_match('/^(list|show)\s+(all\s+)?(paging|page|intercom)\s*(groups?)?$/i', $lower)) {
			return ['tool' => 'fm_list_paging', 'params' => []];
		}

		// ── Parking ──
		if (preg_match('/^(list|show)\s+(all\s+)?(parking|parked)(\s+lots?|\s+calls?)?$/i', $lower)) {
			return ['tool' => 'fm_list_parking', 'params' => []];
		}

		// ── IVRs ──
		if (preg_match('/^(list|show)\s+(all\s+)?ivrs?$/i', $lower)) {
			return ['tool' => 'fm_list_ivrs', 'params' => []];
		}
		if (preg_match('/^(show|get)\s+ivr\s+(\d+)$/i', $msg, $m)) {
			return ['tool' => 'fm_get_ivr', 'params' => ['id' => $m[2]]];
		}

		// ── Announcements ──
		if (preg_match('/^(list|show)\s+(all\s+)?announcements?$/i', $lower)) {
			return ['tool' => 'fm_list_announcements', 'params' => []];
		}

		// ── Call Waiting ──
		if (preg_match('/^(show|get|check)\s+call\s*waiting\s+(on\s+|for\s+)?(\d+)$/i', $msg, $m)) {
			return ['tool' => 'fm_get_call_waiting', 'params' => ['ext' => $m[3]]];
		}
		if (preg_match('/^(enable|set)\s+call\s*waiting\s+(on\s+|for\s+)?(\d+)$/i', $msg, $m)) {
			$params = ['ext' => $m[3], 'state' => 'on'];
			self::setPending($sessionId, 'fm_set_call_waiting', $params);
			return ['tool' => 'fm_set_call_waiting', 'params' => $params];
		}
		if (preg_match('/^(disable)\s+call\s*waiting\s+(on\s+|for\s+)?(\d+)$/i', $msg, $m)) {
			$params = ['ext' => $m[3], 'state' => 'off'];
			self::setPending($sessionId, 'fm_set_call_waiting', $params);
			return ['tool' => 'fm_set_call_waiting', 'params' => $params];
		}

		// ── Recording Rules ──
		if (preg_match('/^(list|show)\s+(all\s+)?(call\s+)?recording\s*rules?$/i', $lower)) {
			return ['tool' => 'fm_list_recording_rules', 'params' => []];
		}

		// ── Calendars ──
		if (preg_match('/^(list|show)\s+(all\s+)?calendars?$/i', $lower)) {
			return ['tool' => 'fm_list_calendars', 'params' => []];
		}

		// ── Announcement Detail ──
		if (preg_match('/^(show|get)\s+announcement\s+(\d+)$/i', $msg, $m)) {
			return ['tool' => 'fm_get_announcement', 'params' => ['id' => $m[2]]];
		}

		// ── Recordings ──
		if (preg_match('/^(list|show)\s+all\s+(system\s+)?recordings?$/i', $lower)) {
			return ['tool' => 'fm_list_recordings', 'params' => ['type' => 'all']];
		}
		if (preg_match('/^(list|show)\s+(system\s+)?recordings?$/i', $lower)) {
			return ['tool' => 'fm_list_recordings', 'params' => []];
		}

		// ── Feature Codes ──
		if (preg_match('/^(list|show)\s+(all\s+)?feature\s*codes?$/i', $lower)) {
			return ['tool' => 'fm_list_feature_codes', 'params' => []];
		}

		// ── Music on Hold ──
		if (preg_match('/^(list|show)\s+(all\s+)?(moh|music\s*on\s*hold|hold\s+music)$/i', $lower)) {
			return ['tool' => 'fm_list_moh', 'params' => []];
		}

		// ── Firewall ──
		if (preg_match('/^(show|get|check)\s+(firewall)(\s+status)?$/i', $lower)) {
			return ['tool' => 'fm_get_firewall_status', 'params' => []];
		}

		// ── Asterisk Info ──
		if (preg_match('/^(show|get)?\s*(asterisk|system)\s*(info|status|uptime)$/i', $lower)) {
			return ['tool' => 'fm_get_asterisk_info', 'params' => []];
		}
		if (preg_match('/^(uptime|system\s+status)$/i', $lower)) {
			return ['tool' => 'fm_get_asterisk_info', 'params' => []];
		}

		// ── SIP Settings ──
		if (preg_match('/^(show|get)\s+(sip|pjsip)\s*(settings?|config)?$/i', $lower)) {
			return ['tool' => 'fm_get_sip_settings', 'params' => []];
		}

		// ── Reload ──
		if (preg_match('/^(reload|apply|apply\s+config|apply\s+changes)$/i', $lower)) {
			self::setPending($sessionId, 'fm_reload', []);
			return ['tool' => 'fm_reload', 'params' => []];
		}

		// ── Modules ──
		// "list all modules" / "list modules all" → full grouped list (escape hatch for the wall view)
		if (preg_match('/^(list|show)\s+(all\s+modules?|modules?\s+all)$/i', $lower)) {
			return ['tool' => 'fm_module_list', 'params' => ['all' => true]];
		}
		// "list modules <license-bucket>" → filter by license bucket
		if (preg_match('/^(list|show)\s+modules?\s+(commercial|agpl|agplv3|agpl3|gpl|gpl2|gplv2|gpl3|gplv3|other)$/i', $lower, $m)) {
			return ['tool' => 'fm_module_list', 'params' => ['license' => strtolower($m[2])]];
		}
		// "list modules enabled/disabled" → status filter
		if (preg_match('/^(list|show)\s+modules?\s+(enabled|disabled)$/i', $lower, $m)) {
			return ['tool' => 'fm_module_list', 'params' => ['status' => strtolower($m[2])]];
		}
		// "list modules" → summary view (counts per license, clickable buckets)
		if (preg_match('/^(list|show)\s+modules?$/i', $lower)) {
			return ['tool' => 'fm_module_list', 'params' => []];
		}
		// "check for upgrades" / "check upgrades" → online check (slow, ~10s) → fm_check_upgrades
		if (preg_match('/^check\s+(for\s+)?upgrades?$/i', $lower)) {
			return ['tool' => 'fm_check_upgrades', 'params' => []];
		}
		if (preg_match('/^(module|mod)\s+(status|info|details?)\s+(\S+)$/i', $msg, $m)) {
			return ['tool' => 'fm_module_status', 'params' => ['name' => $m[3]]];
		}

		// ── Audit ──
		if (preg_match('/^(show\s+)?(audit|log|history)(\s+(\d+))?$/i', $msg, $m)) {
			$params = [];
			if (!empty($m[4])) $params['limit'] = (int) $m[4];
			return ['tool' => 'fm_audit_search', 'params' => $params];
		}

		// ── Misc Destinations ──
		if (preg_match('/^(list|show)\s+(misc\s+)?(dest|destinations?)$/i', $lower)) {
			return ['tool' => 'fm_list_misc_dests', 'params' => []];
		}
		if (preg_match('/^(add|create)\s+(misc\s+)?dest(?:ination)?\s+(.+?)\s+(?:to|dial)\s+(.+)$/i', $msg, $m)) {
			$params = ['description' => trim($m[3], '"\''), 'dial' => trim($m[4])];
			self::setPending($sessionId, 'fm_add_misc_dest', $params);
			return ['tool' => 'fm_add_misc_dest', 'params' => $params];
		}
		if (preg_match('/^(remove|delete)\s+(misc\s+)?dest(?:ination)?\s+(\d+)$/i', $msg, $m)) {
			$params = ['id' => $m[3]];
			self::setPending($sessionId, 'fm_remove_misc_dest', $params);
			return ['tool' => 'fm_remove_misc_dest', 'params' => $params];
		}

		// ── Saved Queries ──
		if (preg_match('/^(list|show)\s+saved\s+quer/i', $lower)) {
			return ['tool' => 'fm_list_saved_queries', 'params' => []];
		}

		// ── Dialplan: show contexts ──
		if (preg_match('/^(show|list)\s+(custom\s+)?(dialplan|contexts?)$/i', $lower)) {
			return ['tool' => 'fm_dialplan_show', 'params' => []];
		}
		if (preg_match('/^(show|get)\s+(dialplan\s+)?(context)\s+(.+)$/i', $msg, $m)) {
			return ['tool' => 'fm_dialplan_get_context', 'params' => ['name' => trim($m[4])]];
		}
		if (preg_match('/^(show|list)\s+(dialplan\s+)?templates?$/i', $lower)) {
			return ['tool' => 'fm_dialplan_templates', 'params' => []];
		}
		if (preg_match('/^(remove|delete)\s+(dialplan\s+)?(context)\s+(.+)$/i', $msg, $m)) {
			$params = ['name' => trim($m[4])];
			self::setPending($sessionId, 'fm_dialplan_remove', $params);
			return ['tool' => 'fm_dialplan_remove', 'params' => $params];
		}

		// ── Dialplan: create IVR ──
		if (preg_match('/^(?:create|build|make)\s+(?:a\s+)?(?:menu|ivr)\s+(?:on\s+)?(?:ext(?:ension)?\s+)?(\d+)/i', $msg, $m)) {
			$ext = $m[1];
			$options = [];
			preg_match_all('/(?:press\s+)?(\d)\s+(?:for\s+)?(?:\w+\s+)?(?:ring\s+|ext\s+|extension\s+|to\s+)?(\d{3,6})/i', $msg, $opts);
			if (!empty($opts[1])) {
				for ($i = 0; $i < count($opts[1]); $i++) {
					$options[$opts[1][$i]] = $opts[2][$i];
				}
			}
			if (empty($options)) {
				return ['response' => "I need the menu options. Example: `create menu on 8000 press 1 for 600 press 2 for 601`"];
			}
			$params = ['template' => 'ivr', 'extension' => $ext, 'options' => $options];
			self::setPending($sessionId, 'fm_dialplan_apply', $params);
			return ['tool' => 'fm_dialplan_apply', 'params' => $params];
		}

		// ── Dialplan: time route ──
		if (preg_match('/^(?:create|build|make|set\s+up)\s+(?:a\s+)?time\s*(?:[-\s])?route/i', $msg)) {
			$biz = null; $after = null; $ext = 's';
			if (preg_match('/(?:on|for|ext(?:ension)?)\s+(\d+)/i', $msg, $m)) $ext = $m[1];
			if (preg_match('/(?:business|hours?|daytime|open)\s+(?:to\s+|ring\s+|goto?\s+)?(\d+|voicemail\s*\d*)/i', $msg, $m)) $biz = trim($m[1]);
			if (preg_match('/(?:after\s*hours?|closed|night|off\s*hours?)\s+(?:to\s+|ring\s+|goto?\s+)?(\d+|voicemail\s*\d*)/i', $msg, $m)) $after = trim($m[1]);
			if (!$biz || !$after) {
				return ['response' => "I need business hours and after hours destinations. Example: `create time route for 1001 business hours to 600 after hours to voicemail`"];
			}
			$params = ['template' => 'time-route', 'extension' => $ext, 'business_dest' => $biz, 'after_dest' => $after];
			self::setPending($sessionId, 'fm_dialplan_apply', $params);
			return ['tool' => 'fm_dialplan_apply', 'params' => $params];
		}

		// ── Dialplan: webhook ──
		if (preg_match('/^(?:create|build|make|send|set\s+up)\s+(?:a\s+)?webhook/i', $msg)) {
			$url = null; $event = 'hangup';
			if (preg_match('/(https?:\/\/\S+)/i', $msg, $m)) $url = $m[1];
			if (preg_match('/(?:on|after|at)\s+(hangup|answer|call\s*end|disconnect)/i', $msg, $m)) {
				$event = (stripos($m[1], 'answer') !== false) ? 'answer' : 'hangup';
			}
			if (!$url) {
				return ['response' => "I need a URL. Example: `send webhook to https://hooks.slack.com/xxx after every call`"];
			}
			$fields = ['caller', 'destination', 'duration', 'disposition', 'timestamp'];
			$params = ['template' => 'webhook', 'url' => $url, 'event' => $event, 'fields' => $fields];
			self::setPending($sessionId, 'fm_dialplan_apply', $params);
			return ['tool' => 'fm_dialplan_apply', 'params' => $params];
		}

		// ── Dialplan: API lookup ──
		if (preg_match('/^(?:when|if)\s+(?:someone|a\s+call|caller)\s+(?:calls?|rings?)/i', $msg) && preg_match('/(https?:\/\/\S+)/i', $msg, $urlMatch)) {
			$url = $urlMatch[1];
			$ext = '_X.';
			if (preg_match('/(?:calls?|rings?)\s+(\d+)/i', $msg, $m)) $ext = $m[1];
			$action = 'set-callerid';
			if (preg_match('/(?:route|send|transfer)/i', $msg)) $action = 'route';
			$params = ['template' => 'api-lookup', 'url' => $url, 'extension' => $ext, 'action' => $action];
			self::setPending($sessionId, 'fm_dialplan_apply', $params);
			return ['tool' => 'fm_dialplan_apply', 'params' => $params];
		}

		// ── Dialplan: caller ID routing ──
		if (preg_match('/^(?:when|if)\s+call(?:s|er)?\s+(?:come|from|with)\s+(?:from\s+)?(?:the\s+)?(\d{3})\s+(?:area\s+code\s+)?(?:route|send|go)\s+(?:to\s+)?(\d+)/i', $msg, $m)) {
			$params = ['template' => 'cid-route', 'rules' => [['pattern' => $m[1], 'dest' => $m[2]]]];
			self::setPending($sessionId, 'fm_dialplan_apply', $params);
			return ['tool' => 'fm_dialplan_apply', 'params' => $params];
		}
		if (preg_match('/^(?:route|send)\s+calls?\s+from\s+(\d+)\s+(?:to)\s+(\d+)/i', $msg, $m)) {
			$params = ['template' => 'cid-route', 'rules' => [['pattern' => $m[1], 'dest' => $m[2]]]];
			self::setPending($sessionId, 'fm_dialplan_apply', $params);
			return ['tool' => 'fm_dialplan_apply', 'params' => $params];
		}

		// ── Dialplan: failover ──
		if (preg_match('/^(?:create|build|make|set\s+up)\s+(?:a\s+)?(?:failover|ring\s+chain)/i', $msg)) {
			$exts = [];
			preg_match_all('/(\d{3,6})/', $msg, $nums);
			if (!empty($nums[1])) {
				foreach ($nums[1] as $num) $exts[] = ['dest' => $num, 'timeout' => 15];
			}
			if (count($exts) < 2) {
				return ['response' => "I need at least 2 extensions. Example: `create failover 1001 1002 1003 then voicemail`"];
			}
			$final = 'hangup';
			if (preg_match('/(?:then|finally)\s+(voicemail|vm|hangup)/i', $msg, $m)) {
				$final = stripos($m[1], 'vm') !== false ? 'voicemail ' . $exts[0]['dest'] : 'hangup';
			}
			$params = ['template' => 'failover', 'steps' => $exts, 'final' => $final, 'extension' => $exts[0]['dest']];
			self::setPending($sessionId, 'fm_dialplan_apply', $params);
			return ['tool' => 'fm_dialplan_apply', 'params' => $params];
		}

		// ── Dialplan: feature code ──
		if (preg_match('/^(?:create|build|make)\s+(?:a\s+)?(?:feature\s+code|star\s+code)\s+(\*\d+)/i', $msg, $m)) {
			$code = $m[1]; $action = 'readback'; $action_params = [];
			if (preg_match('/(?:time|clock)/i', $msg)) $action = 'time';
			elseif (preg_match('/(?:forward|fwd)\s+(?:to\s+)?(\d+)/i', $msg, $fm)) { $action = 'forward'; $action_params['destination'] = $fm[1]; }
			elseif (preg_match('/(?:speed\s*dial|dial)\s+(?:to\s+)?(\d+)/i', $msg, $fm)) { $action = 'speed-dial'; $action_params['destination'] = $fm[1]; }
			elseif (preg_match('/echo\s*test/i', $msg)) $action = 'echo-test';
			$params = ['template' => 'feature-code', 'code' => $code, 'action' => $action, 'action_params' => $action_params];
			self::setPending($sessionId, 'fm_dialplan_apply', $params);
			return ['tool' => 'fm_dialplan_apply', 'params' => $params];
		}

		// ── Dialplan: announcement ──
		if (preg_match('/^(?:create|build|make|play)\s+(?:an?\s+)?announcement\s+(?:on\s+)?(?:ext(?:ension)?\s+)?(\d+)\s+(?:then\s+)?(?:transfer|send|goto?)\s+(?:to\s+)?(\d+)/i', $msg, $m)) {
			$params = ['template' => 'announcement', 'extension' => $m[1], 'destination' => $m[2]];
			self::setPending($sessionId, 'fm_dialplan_apply', $params);
			return ['tool' => 'fm_dialplan_apply', 'params' => $params];
		}

		// ── Dialplan: collect + API ──
		if (preg_match('/^(?:create|build|make|set\s+up)\s+(?:a\s+)?(?:collect|gather|input)/i', $msg) && preg_match('/(https?:\/\/\S+)/i', $msg, $urlMatch)) {
			$url = $urlMatch[1]; $ext = '9000'; $digits = 5;
			if (preg_match('/(?:on|ext(?:ension)?)\s+(\d+)/i', $msg, $m)) $ext = $m[1];
			if (preg_match('/(\d+)\s+digit/i', $msg, $m)) $digits = (int) $m[1];
			$action = 'readback';
			if (preg_match('/(?:verify|check|validate|auth)/i', $msg)) $action = 'verify';
			if (preg_match('/(?:route|transfer|send)/i', $msg)) $action = 'route';
			$params = ['template' => 'collect-query', 'extension' => $ext, 'digits' => $digits, 'url' => $url, 'action' => $action];
			self::setPending($sessionId, 'fm_dialplan_apply', $params);
			return ['tool' => 'fm_dialplan_apply', 'params' => $params];
		}


		// ── Inbound Route CRUD ──
		if (preg_match('/^(show|get)\s+(inbound\s+)?route\s+(.+)$/i', $msg, $m)) {
			return ['tool' => 'fm_get_inbound_route', 'params' => ['extension' => trim($m[3])]];
		}
		// One-shot: "add inbound route 5551234567 to 1001". Tool's resolveDestination()
		// handles plain numbers + shorthand (vm/rg/ivr/tc), so we just pass through.
		if (preg_match('/^(add|create)\s+(inbound\s+)?route\s+(\S+)\s+(?:to|→)\s+(.+)$/i', $msg, $m)) {
			$params = ['extension' => $m[3], 'destination' => trim($m[4])];
			self::setPending($sessionId, 'fm_add_inbound_route', $params);
			return ['tool' => 'fm_add_inbound_route', 'params' => $params];
		}
		// Wizard: bare "add inbound route" (or with DID pre-filled). Asks DID, description,
		// destination, optional CID match.
		if (preg_match('/^(add|create)\s+(inbound\s+)?route(?:\s+(\S+))?$/i', $msg, $m)) {
			$preset = !empty($m[3]) ? ['extension' => $m[3]] : [];
			$prompts = [];
			if (empty($preset['extension'])) {
				$prompts[] = ['param' => 'extension', 'prompt' => "What's the inbound DID? (e.g. `5551234567` or `+15551234567`)"];
			}
			$prompts[] = ['param' => 'description', 'prompt' => "Description? Short label for the route (e.g. `Main line`, `Sales DID`), or {{cmd:skip|⏭ Skip}}.", 'skip_default' => null];
			$prompts[] = ['param' => 'destination', 'prompt' => "Where should it route? Type an extension number (e.g. `1001`), `vm 1001`, `rg 600`, `ivr 1`, `tc 1`, or a full destination string."];
			$prompts[] = ['param' => 'cidnum', 'prompt' => "Optional CID match — number to match in the caller ID, or {{cmd:skip|⏭ Skip (any caller)}}.", 'skip_default' => null];
			self::setInputWizard($sessionId, 'fm_add_inbound_route', $preset, $prompts);
			return ['response' => $prompts[0]['prompt']];
		}
		if (preg_match('/^(remove|delete)\s+(inbound\s+)?route\s+(\S+)$/i', $msg, $m)) {
			$params = ['extension' => $m[3]];
			self::setPending($sessionId, 'fm_remove_inbound_route', $params);
			return ['tool' => 'fm_remove_inbound_route', 'params' => $params];
		}

		// ── Ring Group CRUD ──
		if (preg_match('/^(add|create)\s+(ring\s*group|rg)\s+(\d+)\s+(?:with|members?|ext)\s+(.+?)(?:\s+strategy\s+(\w+))?$/i', $msg, $m)) {
			$params = ['grpnum' => $m[3], 'members' => trim($m[4]), 'description' => "Ring Group {$m[3]}"];
			if (!empty($m[5])) $params['strategy'] = $m[5];
			self::setPending($sessionId, 'fm_add_ringgroup', $params);
			return ['tool' => 'fm_add_ringgroup', 'params' => $params];
		}
		if (preg_match('/^delete\s+(ring\s*group|rg)\s+(\d+)$/i', $msg, $m)) {
			$params = ['id' => $m[2]];
			self::setPending($sessionId, 'fm_delete_ringgroup', $params);
			return ['tool' => 'fm_delete_ringgroup', 'params' => $params];
		}

		// ── Module Management ──
		if (preg_match('/^(install|uninstall|enable|disable|upgrade)\s+module\s+(\S+)$/i', $msg, $m)) {
			$action = strtolower($m[1]);
			$name = $m[2];
			$tool = "fm_module_{$action}";
			$params = ['name' => $name];
			self::setPending($sessionId, $tool, $params);
			return ['tool' => $tool, 'params' => $params];
		}
		if (preg_match('/^upgrade\s+all\s+modules$/i', $lower)) {
			$params = ['name' => 'all'];
			self::setPending($sessionId, 'fm_module_upgrade', $params);
			return ['tool' => 'fm_module_upgrade', 'params' => $params];
		}
		if (preg_match('/^(need|check)\s*reload$/i', $lower)) {
			return ['tool' => 'fm_need_reload', 'params' => []];
		}

		// ── Voicemail Enable/Disable ──
		if (preg_match('/^enable\s+voicemail\s+(on\s+|for\s+)?(\d+)$/i', $msg, $m)) {
			$params = ['ext' => $m[2]];
			self::setPending($sessionId, 'fm_enable_voicemail', $params);
			return ['tool' => 'fm_enable_voicemail', 'params' => $params];
		}
		if (preg_match('/^disable\s+voicemail\s+(on\s+|for\s+)?(\d+)$/i', $msg, $m)) {
			$params = ['ext' => $m[2]];
			self::setPending($sessionId, 'fm_disable_voicemail', $params);
			return ['tool' => 'fm_disable_voicemail', 'params' => $params];
		}

		// ── Advanced Settings ──
		if (preg_match('/^(show|get)\s+(advanced\s+)?setting\s+(\S+)$/i', $msg, $m)) {
			return ['tool' => 'fm_get_advanced_setting', 'params' => ['key' => $m[3]]];
		}
		if (preg_match('/^list\s+(advanced\s+)?settings$/i', $lower)) {
			return ['tool' => 'fm_get_advanced_setting', 'params' => ['key' => 'list']];
		}
		if (preg_match('/^set\s+(advanced\s+)?setting\s+(\S+)\s+(?:to\s+)?(.+)$/i', $msg, $m)) {
			$params = ['key' => $m[2], 'value' => trim($m[3])];
			self::setPending($sessionId, 'fm_set_advanced_setting', $params);
			return ['tool' => 'fm_set_advanced_setting', 'params' => $params];
		}

		// ── Firewall Network ──
		if (preg_match('/^(add|allow)\s+(\S+)\s+(?:to\s+)?(?:firewall\s+)?zone\s+(\w+)$/i', $msg, $m)) {
			$params = ['network' => $m[2], 'zone' => $m[3]];
			self::setPending($sessionId, 'fm_firewall_add_network', $params);
			return ['tool' => 'fm_firewall_add_network', 'params' => $params];
		}

		// ── Backups ──
		if (preg_match('/^list\s+backup\s+jobs?$/i', $lower)
			|| preg_match('/^list\s+backups?$/i', $lower)
			|| preg_match('/^show\s+backup\s+jobs?$/i', $lower)) {
			return ['tool' => 'fm_list_backup_jobs', 'params' => []];
		}
		if (preg_match('/^(?:show|get)\s+backup\s+status$/i', $lower)
			|| preg_match('/^backup\s+status$/i', $lower)) {
			return ['tool' => 'fm_backup_status', 'params' => []];
		}
		if (preg_match('/^(?:show|get)?\s*backup\s+status\s+(?:for\s+)?(.+)$/i', $msg, $m)) {
			return ['tool' => 'fm_backup_status', 'params' => ['job_name' => trim($m[1])]];
		}
		if (preg_match('/^list\s+(?:failed\s+backups?(?:\s+jobs?)?|backup\s+failures)$/i', $lower)) {
			return ['tool' => 'fm_list_backup_runs', 'params' => ['status' => 'failed_inferred']];
		}
		if (preg_match('/^list\s+backup\s+runs?$/i', $lower)) {
			return ['tool' => 'fm_list_backup_runs', 'params' => []];
		}
		if (preg_match('/^list\s+backup\s+runs?\s+for\s+(.+)$/i', $msg, $m)) {
			return ['tool' => 'fm_list_backup_runs', 'params' => ['job_name' => trim($m[1])]];
		}
		if (preg_match('/^(show|get)\s+backup\s+(\S+)$/i', $msg, $m)) {
			return ['tool' => 'fm_get_backup', 'params' => ['id' => $m[2]]];
		}

		// ── Certificates ──
		if (preg_match('/^list\s+(cert|certificate|ssl|tls)s?$/i', $lower)) {
			return ['tool' => 'fm_list_certificates', 'params' => []];
		}

		// ── Filestores ──
		if (preg_match('/^list\s+(filestore|storage)s?$/i', $lower)) {
			return ['tool' => 'fm_list_filestores', 'params' => []];
		}

		// ── PM2 ──
		if (preg_match('/^(show|list|get)\s+(pm2|services?|processes?)$/i', $lower)) {
			return ['tool' => 'fm_get_pm2_status', 'params' => []];
		}

		// ── License ──
		if (preg_match('/^(show|get)\s+(license|licence|activation)$/i', $lower)) {
			return ['tool' => 'fm_get_license_info', 'params' => []];
		}
		if (preg_match('/^(update|refresh|renew)\s+(activation|license|licence)$/i', $lower)) {
			self::setPending($sessionId, 'fm_update_activation', []);
			return ['tool' => 'fm_update_activation', 'params' => []];
		}

		if (preg_match('/^diagnose\s+(ext(ension)?)\s+(\d+)$/i', $msg, $m)) {
			return ['tool' => 'fm_diagnose_extension', 'params' => ['ext' => $m[3]]];
		}
		if (preg_match('/^(troubleshoot|debug)\s+(ext(ension)?)?\s*(\d+)$/i', $msg, $m)) {
			return ['tool' => 'fm_diagnose_extension', 'params' => ['ext' => $m[4]]];
		}
		if (preg_match('/^(why\s+can.?t|what.?s\s+wrong\s+with)\s+(ext(ension)?)?\s*(\d+)/i', $msg, $m)) {
			return ['tool' => 'fm_diagnose_extension', 'params' => ['ext' => $m[4]]];
		}
		if (preg_match('/^diagnose\s+trunk\s+(\d+)$/i', $msg, $m)) {
			return ['tool' => 'fm_diagnose_trunk', 'params' => ['id' => $m[1]]];
		}
		if (preg_match('/^(troubleshoot|debug)\s+trunk\s+(\d+)$/i', $msg, $m)) {
			return ['tool' => 'fm_diagnose_trunk', 'params' => ['id' => $m[2]]];
		}
		if (preg_match('/^(show|get)\s+endpoint\s+(?:details?\s+)?([a-zA-Z0-9_\-]+)$/i', $msg, $m)) {
			return ['tool' => 'fm_pjsip_endpoint_details', 'params' => ['endpoint' => $m[2]]];
		}
		if (preg_match('/^endpoint\s+(?:details?\s+)?([a-zA-Z0-9_\-]+)$/i', $msg, $m)) {
			if (strtolower($m[1]) !== 'details' && strtolower($m[1]) !== 'detail') {
				return ['tool' => 'fm_pjsip_endpoint_details', 'params' => ['endpoint' => $m[1]]];
			}
		}
		if (preg_match('/^(show\s+)?(sip|pjsip)\s+channels?$/i', $lower)) {
			return ['tool' => 'fm_pjsip_show_channels', 'params' => []];
		}
		if (preg_match('/^(show\s+)?(sip|pjsip)\s+channels?\s+(for\s+)?(\S+)$/i', $msg, $m)) {
			return ['tool' => 'fm_pjsip_show_channels', 'params' => ['endpoint' => $m[4]]];
		}
		if (preg_match('/^(start|begin)\s+(sip\s+)?trace(\s+(\d+)s?)?$/i', $msg, $m)) {
			$params = ['action' => 'start'];
			if (!empty($m[4])) $params['duration'] = (int)$m[4];
			return ['tool' => 'fm_sip_trace', 'params' => $params];
		}
		if (preg_match('/^stop\s+(sip\s+)?trace$/i', $lower)) {
			return ['tool' => 'fm_sip_trace', 'params' => ['action' => 'stop']];
		}
		if (preg_match('/^(sip\s+)?trace\s+status$/i', $lower)) {
			return ['tool' => 'fm_sip_trace', 'params' => ['action' => 'status']];
		}

		// ── PJSIP Diagnostics ──
		if (preg_match('/^ping\s+(\S+)$/i', $msg, $m)) {
			return ['tool' => 'fm_pjsip_qualify', 'params' => ['ext' => $m[1]]];
		}
		if (preg_match('/^(show\s+)?registrations?$/i', $lower)) {
			return ['tool' => 'fm_pjsip_registrations', 'params' => []];
		}
		if (preg_match('/^extension\s+states?$/i', $lower)) {
			return ['tool' => 'fm_extension_states', 'params' => []];
		}
		if (preg_match('/^rotate\s+logs?$/i', $lower)) {
			return ['tool' => 'fm_rotate_logs', 'params' => []];
		}

		// ── Live Call Control ──
		if (preg_match('/^call\s+(\d+)\s+(?:to\s+)(\S+)$/i', $msg, $m)) {
			$params = ['ext' => $m[1], 'dest' => $m[2]];
			self::setPending($sessionId, 'fm_originate_call', $params);
			return ['tool' => 'fm_originate_call', 'params' => $params];
		}
		if (preg_match('/^hangup\s+(\S+)$/i', $msg, $m)) {
			$params = ['channel' => $m[1]];
			self::setPending($sessionId, 'fm_hangup_call', $params);
			return ['tool' => 'fm_hangup_call', 'params' => $params];
		}
		if (preg_match('/^transfer\s+(\S+)\s+(?:to\s+)(\S+)$/i', $msg, $m)) {
			$params = ['channel' => $m[1], 'ext' => $m[2]];
			self::setPending($sessionId, 'fm_transfer_call', $params);
			return ['tool' => 'fm_transfer_call', 'params' => $params];
		}
		if (preg_match('/^park\s+(\S+)$/i', $msg, $m)) {
			$params = ['channel' => $m[1]];
			self::setPending($sessionId, 'fm_park_call', $params);
			return ['tool' => 'fm_park_call', 'params' => $params];
		}
		if (preg_match('/^record\s+(\S+)$/i', $msg, $m)) {
			$params = ['channel' => $m[1]];
			self::setPending($sessionId, 'fm_monitor_call', $params);
			return ['tool' => 'fm_monitor_call', 'params' => $params];
		}
		if (preg_match('/^stop\s+record(ing)?\s+(\S+)$/i', $msg, $m)) {
			$params = ['channel' => $m[2]];
			self::setPending($sessionId, 'fm_stop_monitor_call', $params);
			return ['tool' => 'fm_stop_monitor_call', 'params' => $params];
		}
		if (preg_match('/^mute\s+(\S+)$/i', $msg, $m)) {
			$params = ['channel' => $m[1], 'state' => 'on'];
			self::setPending($sessionId, 'fm_mute_call', $params);
			return ['tool' => 'fm_mute_call', 'params' => $params];
		}
		if (preg_match('/^unmute\s+(\S+)$/i', $msg, $m)) {
			$params = ['channel' => $m[1], 'state' => 'off'];
			self::setPending($sessionId, 'fm_mute_call', $params);
			return ['tool' => 'fm_mute_call', 'params' => $params];
		}

		// ── Queue Agents ──
		if (preg_match('/^add\s+(\d+)\s+to\s+queue\s+(\d+)$/i', $msg, $m)) {
			$params = ['ext' => $m[1], 'queue' => $m[2]];
			self::setPending($sessionId, 'fm_queue_add_agent', $params);
			return ['tool' => 'fm_queue_add_agent', 'params' => $params];
		}
		if (preg_match('/^remove\s+(\d+)\s+from\s+queue\s+(\d+)$/i', $msg, $m)) {
			$params = ['ext' => $m[1], 'queue' => $m[2]];
			self::setPending($sessionId, 'fm_queue_remove_agent', $params);
			return ['tool' => 'fm_queue_remove_agent', 'params' => $params];
		}
		if (preg_match('/^pause\s+(\d+)\s+in\s+queue\s+(\d+)$/i', $msg, $m)) {
			$params = ['ext' => $m[1], 'queue' => $m[2], 'paused' => true];
			self::setPending($sessionId, 'fm_queue_pause_agent', $params);
			return ['tool' => 'fm_queue_pause_agent', 'params' => $params];
		}
		if (preg_match('/^unpause\s+(\d+)\s+in\s+queue\s+(\d+)$/i', $msg, $m)) {
			$params = ['ext' => $m[1], 'queue' => $m[2], 'paused' => false];
			self::setPending($sessionId, 'fm_queue_pause_agent', $params);
			return ['tool' => 'fm_queue_pause_agent', 'params' => $params];
		}
		if (preg_match('/^queue\s+status(?:\s+(\d+))?$/i', $msg, $m)) {
			$params = !empty($m[1]) ? ['queue' => $m[1]] : [];
			return ['tool' => 'fm_queue_status', 'params' => $params];
		}

		// ── Conference Control ──
		// All Confbridge* tools expect `room` (not `id`) and use `action` (not `state`)
		// with values matching their description ("mute"/"unmute", "lock"/"unlock").
		if (preg_match('/^who.s\s+in\s+conference\s+(\d+)$/i', $msg, $m)) {
			return ['tool' => 'fm_conference_participants', 'params' => ['room' => $m[1]]];
		}
		if (preg_match('/^kick\s+(\S+)\s+from\s+conference\s+(\d+)$/i', $msg, $m)) {
			$params = ['room' => $m[2], 'channel' => $m[1]];
			self::setPending($sessionId, 'fm_conference_kick', $params);
			return ['tool' => 'fm_conference_kick', 'params' => $params];
		}
		if (preg_match('/^(mute|unmute)\s+(\S+)\s+in\s+conference\s+(\d+)$/i', $msg, $m)) {
			$params = ['room' => $m[3], 'channel' => $m[2], 'action' => strtolower($m[1])];
			self::setPending($sessionId, 'fm_conference_mute', $params);
			return ['tool' => 'fm_conference_mute', 'params' => $params];
		}
		if (preg_match('/^lock\s+conference\s+(\d+)$/i', $msg, $m)) {
			$params = ['room' => $m[1], 'action' => 'lock'];
			self::setPending($sessionId, 'fm_conference_lock', $params);
			return ['tool' => 'fm_conference_lock', 'params' => $params];
		}
		if (preg_match('/^unlock\s+conference\s+(\d+)$/i', $msg, $m)) {
			$params = ['room' => $m[1], 'action' => 'unlock'];
			self::setPending($sessionId, 'fm_conference_lock', $params);
			return ['tool' => 'fm_conference_lock', 'params' => $params];
		}

		// ── SIP NAT ──
		if (preg_match('/^set\s+external\s*ip\s+(?:to\s+)?(\S+)$/i', $msg, $m)) {
			$params = ['external_ip' => $m[1]];
			self::setPending($sessionId, 'fm_update_sip_nat', $params);
			return ['tool' => 'fm_update_sip_nat', 'params' => $params];
		}

		// ── fwconsole ──
		if (preg_match('/^fwconsole\s+(.+)$/i', $msg, $m)) {
			$params = ['args' => trim($m[1])];
			self::setPending($sessionId, 'fm_fwconsole', $params);
			return ['tool' => 'fm_fwconsole', 'params' => $params];
		}



		// ── Permissions ──
		if (preg_match('/^list\s+permissions?$/i', $lower)) {
			return ['tool' => 'fm_list_permissions', 'params' => []];
		}
		if (preg_match('/^set\s+permission\s+(\S+)\s+(?:to\s+)?(read|write|admin)$/i', $msg, $m)) {
			$params = ['username' => $m[1], 'level' => strtolower($m[2])];
			self::setPending($sessionId, 'fm_set_permission', $params);
			return ['tool' => 'fm_set_permission', 'params' => $params];
		}


		// ── Start/Stop/Restart ──
		if (preg_match('/^start\s+(freepbx|pbx|asterisk|services?)$/i', $lower)) {
			self::setPending($sessionId, 'fm_start', []);
			return ['tool' => 'fm_start', 'params' => []];
		}
		if (preg_match('/^stop\s+(freepbx|pbx|asterisk|services?)$/i', $lower)) {
			self::setPending($sessionId, 'fm_stop', []);
			return ['tool' => 'fm_stop', 'params' => []];
		}
		if (preg_match('/^restart\s+(freepbx|pbx|asterisk|services?)$/i', $lower)) {
			self::setPending($sessionId, 'fm_restart', []);
			return ['tool' => 'fm_restart', 'params' => []];
		}

		// ── Trunk enable/disable ──
		if (preg_match('/^enable\s+trunk\s+(\d+)$/i', $msg, $m)) {
			$params = ['id' => $m[1]];
			self::setPending($sessionId, 'fm_enable_trunk', $params);
			return ['tool' => 'fm_enable_trunk', 'params' => $params];
		}
		if (preg_match('/^disable\s+trunk\s+(\d+)$/i', $msg, $m)) {
			$params = ['id' => $m[1]];
			self::setPending($sessionId, 'fm_disable_trunk', $params);
			return ['tool' => 'fm_disable_trunk', 'params' => $params];
		}
		if (preg_match('/^(enable|disable)\s+trunks?$/i', $msg)) {
			return ['tool' => 'fm_list_trunks', 'params' => []];
		}

		// ── Validate ──
		if (preg_match('/^validate|^security\s+scan/i', $lower)) {
			return ['tool' => 'fm_validate', 'params' => []];
		}

		// ── Chown ──
		if (preg_match('/^(fix\s+permissions|chown)$/i', $lower)) {
			self::setPending($sessionId, 'fm_chown', []);
			return ['tool' => 'fm_chown', 'params' => []];
		}

		// ── Repair User Manager links (UCP login fix) ──
		if (preg_match('/^(repair|fix)\s+(userman(?:\s+links?)?|ucp(?:\s+logins?)?)(?:\s+(?:for\s+|on\s+)?(\d+))?$/i', $msg, $m)) {
			$params = !empty($m[3]) ? ['ext' => $m[3]] : [];
			self::setPending($sessionId, 'fm_repair_userman_links', $params);
			return ['tool' => 'fm_repair_userman_links', 'params' => $params];
		}

		// ── External IP ──
		if (preg_match('/^(external\s+ip|public\s+ip|my\s+ip|what.s\s+my\s+ip)$/i', $lower)) {
			return ['tool' => 'fm_get_external_ip', 'params' => []];
		}

		// ── Notifications ──
		if (preg_match('/^(list|show)\s+notifications?$/i', $lower)) {
			return ['tool' => 'fm_list_notifications', 'params' => []];
		}
		if (preg_match('/^show\s+notification\s+(\S+)$/i', $msg, $m)) {
			return ['tool' => 'fm_list_notifications', 'params' => ['id' => $m[1]]];
		}

		// ── Show any dialplan context ──
		if (preg_match('/^(show|get)\s+asterisk\s+context\s+(\S+)$/i', $msg, $m)) {
			return ['tool' => 'fm_show_context', 'params' => ['name' => $m[2]]];
		}

		// ── Sounds ──
		if (preg_match('/^(list|show)\s+(sound|language)\s*packs?$/i', $lower)) {
			return ['tool' => 'fm_list_sounds', 'params' => []];
		}

		// ── Sync userman ──
		if (preg_match('/^sync\s+(userman|users?|directory)$/i', $lower)) {
			self::setPending($sessionId, 'fm_sync_userman', []);
			return ['tool' => 'fm_sync_userman', 'params' => []];
		}

		// ── System update ──
		if (preg_match('/^(system\s+update|update\s+system)$/i', $lower)) {
			self::setPending($sessionId, 'fm_system_update', []);
			return ['tool' => 'fm_system_update', 'params' => []];
		}

		// ── PM2 manage ──
		if (preg_match('/^(restart|stop)\s+(pm2|service|process)\s+(\S+)$/i', $msg, $m)) {
			$params = ['action' => strtolower($m[1]), 'name' => $m[3]];
			self::setPending($sessionId, 'fm_pm2_manage', $params);
			return ['tool' => 'fm_pm2_manage', 'params' => $params];
		}

		// ── Update certificates ──
		if (preg_match('/^(update|renew)\s+(all\s+)?(cert|certificate|ssl)s?$/i', $lower)) {
			self::setPending($sessionId, 'fm_update_certificates', []);
			return ['tool' => 'fm_update_certificates', 'params' => []];
		}

		// ── Shorthand: just a number = get extension ──
		if (preg_match('/^(\d{3,6})$/', $msg, $m)) {
			return ['tool' => 'fm_get_extension', 'params' => ['ext' => $m[1]]];
		}
		
		// ── Interpretation Layer ──
		// Strip filler, normalise phrasing. If it changes the input,
		// re-parse the normalised form. Off-switchable via kvstore key
		// 'frogman_interpret_mode'.
		if (!$skipFuzzy) {
			$interpreted = Interpret::interpret($msg);
			if (is_array($interpreted) && !empty($interpreted['text']) && $interpreted['text'] !== $msg) {
				$expanded = $interpreted['text'];
				if (!Interpret::shouldRun($interpreted)) {
					return ['response' => Interpret::rephrasePrompt($msg, $expanded)];
				}
				$result = self::parse($expanded, $sessionId, true);
				if (is_array($result) && isset($result['tool']) && !isset($result['interpreted_as'])) {
					$result['interpreted_as'] = $expanded;
				}
				if (is_array($result) && isset($result['response']) && strpos($result['response'], "don't understand") !== false) {
					return ['response' => Interpret::rephrasePrompt($msg, $expanded)];
				}
				return $result;
			}
		}

		// ── Fuzzy Intent Matching ──
		// Normalize synonyms and try keyword extraction before giving up
		if (!$skipFuzzy) {
			$result = self::fuzzyMatch($msg, $lower, $sessionId);
			if ($result) return $result;
		}

		// Questions ending with ? — try the knowledge base
		if (substr(trim($msg), -1) === '?') {
			$query = rtrim(trim($msg), '?');
			$query = preg_replace('/^(what|how|why|where|when|can|does|is|are|do)\s+/i', '', $query);
			if (strlen($query) > 2) {
				return ['tool' => 'fm_search_docs', 'params' => ['query' => $query]];
			}
		}

		return ['response' => "I don't understand that. Type **help** to see what I can do."];
	}

	private static function fuzzyMatch($msg, $lower, $sessionId) {
		// Step 1: Normalize common synonyms and retry
		$synonyms = [
			// Action synonyms
			'/\b(make|build|setup|configure|set\s*up)\b/i' => 'create',
			'/\b(destroy|kill|wipe|nuke)\b/i' => 'delete',
			'/\b(view|display|what|whats|what\'s|tell\s+me)\b/i' => 'show',
			'/\b(check|inspect|test|verify)\b/i' => 'diagnose',
			'/\b(fix|repair)\b/i' => 'troubleshoot',
			// Object synonyms
			'/\b(exts?|extensions?|phone|phones|handset)\b/i' => 'extension',
			'/\b(ring\s*groups?|rgs?)\b/i' => 'ringgroup',
			'/\b(vm|voicemails?|mailbox|mailboxes)\b/i' => 'voicemail',
			// Outbound first so its routes? gets claimed before the inbound pattern runs
			'/\boutbound\s+routes?\b/i' => 'outbound routes',
			'/\binbound\s+routes?\b/i' => 'inbound routes',
			'/\b(dids?)\b/i' => 'inbound routes',
			// Bare "inbound" (not already followed by route/routes) → inbound routes
			'/\binbound\b(?!\s+routes?\b)/i' => 'inbound routes',
			'/\b(fw|firewall|iptables)\b/i' => 'firewall',
			'/\b(cdr|call\s*log|call\s*history|call\s*records?)\b/i' => 'call history',
			'/\b(calls?|channels?)\s+(active|live|current|now)\b/i' => 'active calls',
			'/\b(active|live|current)\s+(calls?|channels?)\b/i' => 'active calls',
		];

		$normalized = $lower;
		foreach ($synonyms as $pattern => $replacement) {
			$normalized = preg_replace($pattern, $replacement, $normalized);
		}
		$normalized = preg_replace('/\s+/', ' ', trim($normalized));

		// If normalization changed something, retry parsing (skip fuzzy to avoid recursion blowup)
		if ($normalized !== $lower) {
			$retry = self::parse($normalized, $sessionId, true);
			if (!isset($retry['response']) || strpos($retry['response'], "don't understand") === false) {
				return $retry;
			}
		}

		// Step 2: Keyword extraction — find action + object + number
		$actions = [
			'list' => ['list', 'show', 'display', 'view', 'get', 'all'],
			'create' => ['create', 'add', 'new', 'make', 'build', 'setup'],
			'delete' => ['delete', 'remove', 'destroy', 'drop', 'kill'],
			'diagnose' => ['diagnose', 'troubleshoot', 'debug', 'check', 'test', 'inspect', 'fix'],
		];
		$objects = [
			'extension' => ['extension', 'extensions', 'ext', 'exts', 'phone', 'phones'],
			'ringgroup' => ['ringgroup', 'ringgroups', 'ring group', 'ring groups', 'rg'],
			'trunk' => ['trunk', 'trunks'],
			'queue' => ['queue', 'queues'],
			'ivr' => ['ivr', 'ivrs', 'menu', 'menus'],
			'conference' => ['conference', 'conferences', 'conf'],
			'voicemail' => ['voicemail', 'voicemails', 'vm', 'mailbox'],
			'route' => ['route', 'routes', 'did', 'dids', 'inbound'],
		];

		$foundAction = null;
		$foundObject = null;
		$foundNumber = null;
		$words = preg_split('/\s+/', $lower);

		foreach ($actions as $action => $keywords) {
			foreach ($keywords as $kw) {
				if (in_array($kw, $words)) { $foundAction = $action; break 2; }
			}
		}
		foreach ($objects as $object => $keywords) {
			foreach ($keywords as $kw) {
				if (strpos($lower, $kw) !== false) { $foundObject = $object; break 2; }
			}
		}
		if (preg_match('/\b(\d{3,6})\b/', $msg, $m)) {
			$foundNumber = $m[1];
		}

		// If we have an action + number but no object, assume extension
		if ($foundAction && $foundNumber && !$foundObject) {
			$foundObject = 'extension';
		}

		// Map to commands
		if ($foundAction && $foundObject) {
			$key = "{$foundAction}:{$foundObject}";

			// Syntax prompts for commands that need extra args (clickable examples)
			$syntaxPrompts = [
				'create:route' => "To create an inbound route, type: `add inbound route <DID> to <ext>`\n\nExample: {{cmd:add inbound route 5551234567 to 1001|Try this}}",
				'delete:route' => "To remove an inbound route, type: `remove inbound route <DID>`\n\nExample: {{cmd:remove inbound route 5551234567|Try this}}",
			];
			if (isset($syntaxPrompts[$key])) {
				return ['response' => $syntaxPrompts[$key]];
			}

			$toolMap = [
				'list:extension' => 'list extensions',
				'list:ringgroup' => 'list ringgroups',
				'list:trunk' => 'list trunks',
				'list:queue' => 'list queues',
				'list:ivr' => 'list ivrs',
				'list:conference' => 'list conferences',
				'list:voicemail' => 'list voicemails',
				'list:route' => 'list inbound routes',
				'diagnose:extension' => $foundNumber ? "diagnose ext {$foundNumber}" : null,
				'diagnose:trunk' => $foundNumber ? "diagnose trunk {$foundNumber}" : null,
			];
			if (isset($toolMap[$key]) && $toolMap[$key]) {
				$retry = self::parse($toolMap[$key], $sessionId, true);
				if (!isset($retry['response']) || strpos($retry['response'], "don't understand") === false) {
					return $retry;
				}
			}
		}

		// Step 3: Fuzzy suggest — find the closest known command
		$knownCommands = [
			'list extensions', 'list trunks', 'list queues', 'list ivrs',
			'list ringgroups', 'list conferences', 'list voicemails',
			'list inbound routes', 'list outbound routes', 'list blacklist',
			'list time conditions', 'list call flows', 'list recordings',
			'list moh', 'list feature codes', 'list paging groups', 'list parking',
			'list announcements', 'list modules', 'list notifications',
			'list certificates', 'list filestores', 'list backup jobs', 'backup status',
			'list backup runs', 'list failed backups', 'list failed backup jobs',
			'list destinations', 'list sound packs', 'list settings',
			'list permissions', 'list voicemail settings',
			'active calls', 'call history', 'queue status',
			'show firewall', 'show sip settings', 'show license',
			'show pm2', 'show dialplan', 'show templates',
			'asterisk info', 'external ip', 'extension states',
			'registrations', 'sip channels', 'validate',
			'reload', 'help',
		];

		$bestMatch = null;
		$bestScore = PHP_INT_MAX;
		foreach ($knownCommands as $cmd) {
			$dist = levenshtein($lower, $cmd);
			if ($dist < $bestScore) {
				$bestScore = $dist;
				$bestMatch = $cmd;
			}
		}

		// Suggest if close enough (within ~40% of command length)
		if ($bestMatch && $bestScore <= max(4, strlen($bestMatch) * 0.4)) {
			return ['response' => "Did you mean **{$bestMatch}**? {{cmd:{$bestMatch}|Yes, run it}}"];
		}

		return null;
	}

	/**
	 * Return the canonical list of chat-input suggestions for the typeahead.
	 * Parses helpText() so suggestions stay in sync as commands are added —
	 * any new tool whose phrases are documented in help() automatically
	 * appears in the input dropdown.
	 */
	public static function getSuggestions() {
		$help = self::helpText();
		// Pull every backtick-quoted phrase out of the help body.
		preg_match_all('/`([^`\n]+)`/', $help, $m);
		$out = [];
		foreach ($m[1] as $phrase) {
			$p = trim($phrase);
			// Skip template-ish strings (PHP variable interpolation, placeholders).
			if (strpos($p, '$') !== false) continue;
			if (strpos($p, '{') !== false) continue;
			if ($p === '') continue;
			// Skip bare-number placeholders ("1001"). Not a useful command on its own,
			// AND json_encode would turn them into JS numbers (breaking phrase.toLowerCase()).
			if (preg_match('/^\d+$/', $p)) continue;
			$out[$p] = true;
		}
		// Also harvest every chat command exposed via the sidebar so adding/removing
		// a button in views/main.php updates the typeahead automatically. data-paste
		// values often end in a space (paste-and-type prefixes); we keep them so
		// typing the prefix surfaces the same starter the sidebar offers.
		$sidebarPath = __DIR__ . '/../views/main.php';
		$sidebar = @file_get_contents($sidebarPath);
		if ($sidebar !== false && preg_match_all('/\bdata-(?:cmd|paste)="([^"]+)"/', $sidebar, $sm)) {
			foreach ($sm[1] as $phrase) {
				$p = trim($phrase);
				if ($p === '' || preg_match('/^\d+$/', $p)) continue;
				$out[$phrase] = true;
			}
		}
		$out = array_keys($out);
		sort($out, SORT_NATURAL | SORT_FLAG_CASE);
		return $out;
	}

	private static function helpText() {
		return <<<HELP
**Frogman Commands:**

**Extensions:**
  `list extensions` — show all
  `health <ext>` — health check
  `onboard new employee` — guided macro wizard (extension + voicemail + Follow Me + ring group + DID + outbound CID, all in one flow)
  `create extension <ext> for <name>`
  `rename extension <ext> to <name>`
  `delete extension <ext>`

**Call Routing:**
  `list inbound routes` — all DIDs
  `list outbound routes` — all outbound routes
  `show outbound route <id>`

**Calls & CDR:**
  `active calls` — calls in progress
  `call history <n>` — recent CDR
  `calls from <ext>`
  `cdr stats` — call totals (today, week, month, top callers, top destinations)
  `peak hours` — busiest hours of the day from CDR
  `busiest extensions` / `top extensions` — extensions with most calls

**Search & Export:**
  `search <query>` / `find <query>` / `where is <query>` / `who is <query>`
  `export <type>` — type is extensions, ringgroups, dids, trunks, cdr, or queues

**Knowledge Base:**
  `kb <query>` / `docs <query>` / `how do i <query>` / `how to <query>` — search Frogman docs

**DID Map:**
  `did map` / `inbound map` / `where do my dids go` — Mermaid flowchart of every DID's first-hop destination

**Trunks:**
  `list trunks`
  `trunk status <id>`
  `enable trunk` / `disable trunk` — pick from the trunk list

**Ring Groups:**
  `list ringgroups` / `show ringgroup <grp>`
  `add <ext> to ringgroup <grp>`
  `remove <ext> from ringgroup <grp>`

**Queues:**
  `list queues` / `show queue <id>`

**Follow Me:**
  `set followme on <ext> to <numbers>`
  `set follow me` — guided wizard (asks ext, numbers, ringtime)
  `set follow me <ext>` — wizard skipping the ext prompt
  `clear followme on <ext>`

**Call Forward:**
  `forward <ext> to <number>`
  `show forward on <ext>`
  `clear forward on <ext>`

**Do Not Disturb:**
  `enable dnd on <ext>` / `disable dnd on <ext>`
  `show dnd on <ext>`

**Blacklist & Allowlist:**
  `list blacklist` / `list allowlist`
  `block <number>` / `unblock <number>`
  `allow <number>` / `unallow <number>` — manage allowlist

**Time Conditions & Day/Night:**
  `list time conditions`
  `toggle time condition <id>`
  `list call flows` / `toggle daynight <id>`
  `set daynight <id> to <day|night>`

**Voicemail:**
  `list voicemails` / `show voicemail for <ext>`

**IVRs & Announcements:**
  `list ivrs` / `show ivr <id>`
  `list announcements`

**Conferences & Paging:**
  `list conferences` / `show conference <id>`
  `list paging groups`

**Parking:**
  `list parking`

**Recordings & MOH:**
  `list recordings` / `list moh`

**Feature Codes:**
  `list feature codes`

**System:**
  `reload` / `need reload` / `check reload`
  `list modules` (summary; click a license bucket to drill in)
  `list all modules` / `list modules <commercial|gpl|gpl2|gpl3|agpl|other>`
  `check for upgrades` — query online repos (~10s)
  `module status <name>`
  `asterisk info` / `uptime` / `sys info` / `system info`
  `show sip settings` / `show firewall`
  `audit <n>`
  `repair userman` / `fix ucp logins` — restore default-group + assigned wiring for UCP login
  `repair userman <ext>` — repair just one extension
  `reset password for <user>` — reset a User Manager password
  `delete userman user <username>` / `delete userman user <uid>` — delete a Userman row (extension is NOT removed)
  `revoke token <id>` — revoke an API token

**Misc Destinations:**
  `list destinations`
  `add destination "<label>" to voicemail <ext>`
  `remove destination <id>`

**Dialplan Builder:**
  `show dialplan` / `show context <name>`
  `show templates`
  `create menu on <ext> press <key> for <dest> press <key> for <dest>`
  `create time route for <ext> business hours to <dest> after hours to <dest>`
  `send webhook to <url> after every call`
  `route calls from <area> to <ext>`
  `create failover <ext1> <ext2> <ext3> then voicemail`
  `create feature code <code> that reads back my extension`
  `remove context <name>`
  `when someone calls <number>` — natural-language inbound route wizard

**Inbound Routes:**
  `list inbound routes` / `show inbound route <DID>`
  `add inbound route <DID> to <dest>`
  `add inbound route` — guided wizard (asks DID, destination, optional CID)
  `add inbound route <DID>` — wizard with DID pre-filled
  `remove inbound route <DID>`

**Ring Group Management:**
  `create ringgroup <grp> with <ext>,<ext>,<ext>`
  `delete ringgroup <grp>`

**Module Management:**
  `install module <name>` / `uninstall module <name>`
  `enable module <name>` / `disable module <name>`
  `upgrade module <name>` / `upgrade all modules`
  `check reload`

**Voicemail:**
  `enable voicemail on <ext>` / `disable voicemail on <ext>`

**Advanced Settings:**
  `list settings` / `show setting <key>`
  `set setting <key> to <value>`

**Firewall:**
  `show firewall` / `add <network> to zone <zone>`

**Backups & Storage:**
  `list backup jobs` / `show backup <id>`
  `backup status` / `backup status for <name>`
  `list backup runs` / `list backup runs for <name>`
  `list failed backups` / `list failed backup jobs`
  `list filestores` / `list certificates`

**Services & License:**
  `show pm2` / `show license`
  `set external ip to <ip>`
  `fwconsole ma list`
  `update activation` / `refresh license` — refresh from Sangoma portal (Apache restarts ~10s)

**Connect / MCP setup:**
  `connect` / `how to connect` — show MCP/API connection info for AI tools
  `mcp config` / `setup mcp` / `api config`

**Live Call Control:**
  `call <ext> to <number>` — click-to-call
  `hangup <channel>` — hang up a channel
  `transfer <channel> to <ext>`
  `park <channel>`
  `record <channel>` / `stop recording <channel>`
  `mute <channel>` / `unmute <channel>`

**Queue Agents:**
  `add <ext> to queue <id>` / `remove <ext> from queue <id>`
  `pause <ext> in queue <id>` / `unpause <ext> in queue <id>`
  `queue status` / `queue status <id>`

**Conference Control:**
  `who's in conference <id>`
  `kick <channel> from conference <id>`
  `lock conference <id>` / `unlock conference <id>`

**PJSIP & Diagnostics:**
  `ping <ext>` — qualify endpoint
  `registrations` — show all SIP registrations
  `extension states` — BLF/presence for all extensions
  `rotate logs`

**SIP Troubleshooting:**
  `diagnose extension <ext>` — full diagnostic (registration, qualify, calls, CDR)
  `troubleshoot <ext>` — same as above
  `why can't <ext> make calls` — same as above
  `diagnose trunk <id>` — trunk diagnostic (registration, qualify, routes, CDR)
  `endpoint details <ext>` — deep PJSIP endpoint info (codecs, transport, auth)

**Call Flow Trace (Mermaid):**
  `trace flow <did>` — full destination chain rendered as a Mermaid diagram
  `trace call flow <did>` — same
  `show flow for <did>` — same
  `where does <did> go` — same
  `how does <did> ring` — same
  `did map` / `inbound map` — every DID at once, first-hop only

**SIP Channels & Trace:**
  `sip channels` — show active SIP channels
  `sip channels for <ext>` — filtered by endpoint
  `start sip trace` / `start trace <duration>` — capture SIP traffic (admin, max 30s)
  `stop trace` — stop and show results
  `trace status` — check if trace is running

**Services & Infrastructure:**
  `start freepbx` / `stop freepbx` / `restart freepbx`
  `validate` — security scan
  `fix permissions` — run chown
  `external ip` — get public IP
  `list notifications`
  `list sound packs`
  `show asterisk context <name>`
  `sync userman` / `system update`
  `restart service <name>` / `stop service <name>`
  `update certificates`

**Permissions:**
  `list permissions` — show user permission levels
  `set permission <user> to <read|write|admin>`

  Permission levels: **read** (view only), **write** (create/modify/delete PBX objects), **admin** (system management, modules, firewall)

Write commands show a preview first. Reply **yes** to confirm.
HELP;
	}
}
