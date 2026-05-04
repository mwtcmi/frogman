<?php
namespace FreePBX\modules\Frogman;

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

	public static function setFollowUp($sessionId, $tool, $params) {
		$db = self::getDb();
		$data = json_encode(['tool' => $tool, 'params' => $params, 'type' => 'followup']);
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

	public static function parse($message, $sessionId = 'default') {
		$msg = trim($message);
		$lower = strtolower($msg);

		// ── Confirm / Cancel ──
		$pending = self::getPending($sessionId);
		if ($pending && preg_match('/^(yes|y|confirm|do it|go|go ahead|ok|sure|yep|yeah)$/i', $msg)) {
			self::clearPending($sessionId);
			$isFollowUp = !empty($pending['type']) && $pending['type'] === 'followup';
			if ($isFollowUp) {
				// Follow-ups execute directly — user already said yes
				$pending['params']['confirm'] = true;
				return ['tool' => $pending['tool'], 'params' => $pending['params']];
			}
			$pending['params']['confirm'] = true;
			return ['tool' => $pending['tool'], 'params' => $pending['params']];
		}
		if ($pending && preg_match('/^(no|n|cancel|nevermind|nope|nah|abort)$/i', $msg)) {
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

		// ── Extensions ──
		if (preg_match('/^(list|show|get)\s+(all\s+)?(ext|extensions?)$/i', $lower)) {
			return ['tool' => 'fm_list_extensions', 'params' => []];
		}
		if (preg_match('/^(list|show|search|find)\s+(ext|extensions?)\s+(.+)$/i', $msg, $m)) {
			return ['tool' => 'fm_list_extensions', 'params' => ['search' => trim($m[3])]];
		}
		if (preg_match('/^(get|show|info|details?)\s+(ext|extension)\s+(\d+)$/i', $msg, $m)) {
			return ['tool' => 'fm_get_extension', 'params' => ['ext' => $m[3]]];
		}
		if (preg_match('/^(health|status|check)\s+(ext|extension)?\s*(\d+)$/i', $msg, $m)) {
			return ['tool' => 'fm_get_extension_health', 'params' => ['ext' => $m[3]]];
		}
		if (preg_match('/^(health|status)\s+check\s+(on\s+)?(\d+)$/i', $msg, $m)) {
			return ['tool' => 'fm_get_extension_health', 'params' => ['ext' => $m[3]]];
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
		if (preg_match('/^(enable|set)\s+(recording|record)\s+(on\s+|for\s+)?(\d+)(\s+(?:to\s+)?(always|force))?$/i', $msg, $m)) {
			$mode = !empty($m[6]) ? strtolower($m[6]) : 'always';
			$params = ['ext' => $m[4], 'mode' => $mode];
			self::setPending($sessionId, 'fm_set_recording', $params);
			return ['tool' => 'fm_set_recording', 'params' => $params];
		}
		if (preg_match('/^(disable|stop)\s+(recording|record)\s+(on\s+|for\s+)?(\d+)$/i', $msg, $m)) {
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
		if (preg_match('/^(list|show)\s+(all\s+)?modules?$/i', $lower)) {
			return ['tool' => 'fm_module_list', 'params' => []];
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
		if (preg_match('/^(add|create)\s+(inbound\s+)?route\s+(\S+)\s+(?:to|→)\s+(.+)$/i', $msg, $m)) {
			$dest = trim($m[4]);
			if (is_numeric($dest)) $dest = "from-internal,{$dest},1";
			$params = ['extension' => $m[3], 'destination' => $dest];
			self::setPending($sessionId, 'fm_add_inbound_route', $params);
			return ['tool' => 'fm_add_inbound_route', 'params' => $params];
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
			$tool = "oc_module_{$action}";
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
		if (preg_match('/^list\s+backups?$/i', $lower)) {
			return ['tool' => 'fm_list_backups', 'params' => []];
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

		// ── SIP Diagnostics ──
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
			$params = ['from' => $m[1], 'to' => $m[2]];
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
		if (preg_match('/^who.s\s+in\s+conference\s+(\d+)$/i', $msg, $m)) {
			return ['tool' => 'fm_conference_participants', 'params' => ['id' => $m[1]]];
		}
		if (preg_match('/^kick\s+(\S+)\s+from\s+conference\s+(\d+)$/i', $msg, $m)) {
			$params = ['id' => $m[2], 'channel' => $m[1]];
			self::setPending($sessionId, 'fm_conference_kick', $params);
			return ['tool' => 'fm_conference_kick', 'params' => $params];
		}
		if (preg_match('/^(mute|unmute)\s+(\S+)\s+in\s+conference\s+(\d+)$/i', $msg, $m)) {
			$params = ['id' => $m[3], 'channel' => $m[2], 'state' => strtolower($m[1]) === 'mute' ? 'on' : 'off'];
			self::setPending($sessionId, 'fm_conference_mute', $params);
			return ['tool' => 'fm_conference_mute', 'params' => $params];
		}
		if (preg_match('/^lock\s+conference\s+(\d+)$/i', $msg, $m)) {
			$params = ['id' => $m[1], 'state' => 'lock'];
			self::setPending($sessionId, 'fm_conference_lock', $params);
			return ['tool' => 'fm_conference_lock', 'params' => $params];
		}
		if (preg_match('/^unlock\s+conference\s+(\d+)$/i', $msg, $m)) {
			$params = ['id' => $m[1], 'state' => 'unlock'];
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

		// ── Validate ──
		if (preg_match('/^validate|^security\s+scan/i', $lower)) {
			return ['tool' => 'fm_validate', 'params' => []];
		}

		// ── Chown ──
		if (preg_match('/^(fix\s+permissions|chown)$/i', $lower)) {
			self::setPending($sessionId, 'fm_chown', []);
			return ['tool' => 'fm_chown', 'params' => []];
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

		// ── Fuzzy Intent Matching ──
		// Normalize synonyms and try keyword extraction before giving up
		$result = self::fuzzyMatch($msg, $lower, $sessionId);
		if ($result) return $result;

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
			'/\b(routes?|did|dids|inbound)\b/i' => 'inbound routes',
			'/\b(outbound)\s+(routes?)/i' => 'outbound routes',
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

		// If normalization changed something, retry parsing
		if ($normalized !== $lower) {
			$retry = self::parse($normalized, $sessionId);
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
			'route' => ['route', 'routes', 'did', 'dids'],
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
			$key = "{$foundAction}:{$foundObject}";
			if (isset($toolMap[$key]) && $toolMap[$key]) {
				$retry = self::parse($toolMap[$key], $sessionId);
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
			'list certificates', 'list filestores', 'list backups',
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

	private static function helpText() {
		return <<<HELP
**Frogman Commands:**

**Extensions:**
  `list extensions` — show all
  `1001` — details for ext 1001
  `health 1001` — health check
  `create extension 1002 for Jane Doe`
  `rename extension 1001 to Mike White`
  `delete extension 1002`

**Call Routing:**
  `list inbound routes` — all DIDs
  `list outbound routes` — all outbound routes
  `show outbound route 1`

**Calls & CDR:**
  `active calls` — calls in progress
  `call history 10` — recent CDR
  `calls from 1001`

**Trunks:**
  `list trunks` / `trunk status 1`

**Ring Groups:**
  `list ringgroups` / `show ringgroup 600`
  `add 1001 to ringgroup 600`
  `remove 1001 from ringgroup 600`

**Queues:**
  `list queues` / `show queue 400`

**Follow Me:**
  `set followme on 1001 to 1001,5551234567`
  `clear followme on 1001`

**Call Forward:**
  `forward 1001 to 5551234567`
  `show forward on 1001`
  `clear forward on 1001`

**Do Not Disturb:**
  `enable dnd on 1001` / `disable dnd on 1001`
  `show dnd on 1001`

**Blacklist:**
  `list blacklist`
  `block 5551234567` / `unblock 5551234567`

**Time Conditions & Day/Night:**
  `list time conditions`
  `toggle time condition 1`
  `list call flows` / `toggle daynight 1`
  `set daynight 1 to night`

**Voicemail:**
  `list voicemails` / `show voicemail for 1001`

**IVRs & Announcements:**
  `list ivrs` / `show ivr 1`
  `list announcements`

**Conferences & Paging:**
  `list conferences` / `show conference 800`
  `list paging groups`

**Parking:**
  `list parking`

**Recordings & MOH:**
  `list recordings` / `list moh`

**Feature Codes:**
  `list feature codes`

**System:**
  `reload` / `list modules` / `module status core`
  `asterisk info` / `uptime`
  `show sip settings` / `show firewall`
  `audit 10`

**Misc Destinations:**
  `list destinations`
  `add destination "After Hours" to voicemail 1001`
  `remove destination 1`

**Dialplan Builder:**
  `show dialplan` / `show context oc-ivr-8000`
  `show templates`
  `create menu on 8000 press 1 for 600 press 2 for 601`
  `create time route for 1001 business hours to 600 after hours to voicemail`
  `send webhook to https://example.com/hook after every call`
  `route calls from 212 to 700`
  `create failover 1001 1002 1003 then voicemail`
  `create feature code *99 that reads back my extension`
  `remove context oc-ivr-8000`

**Inbound Routes:**
  `list inbound routes` / `show inbound route 5551234567`
  `add inbound route 5551234567 to 1001`
  `remove inbound route 5551234567`

**Ring Group Management:**
  `create ringgroup 700 with 1001,1002,1003`
  `delete ringgroup 700`

**Module Management:**
  `install module modulename` / `uninstall module modulename`
  `enable module modulename` / `disable module modulename`
  `upgrade module modulename` / `upgrade all modules`
  `check reload`

**Voicemail:**
  `enable voicemail on 1001` / `disable voicemail on 1001`

**Advanced Settings:**
  `list settings` / `show setting AMPWEBROOT`
  `set setting KEY to VALUE`

**Firewall:**
  `show firewall` / `add 10.0.0.0/8 to zone trusted`

**Backups & Storage:**
  `list backups` / `show backup 1`
  `list filestores` / `list certificates`

**Services & License:**
  `show pm2` / `show license`
  `set external ip to 1.2.3.4`
  `fwconsole ma list`

**Live Call Control:**
  `call 1001 to 5551234567` — click-to-call
  `hangup PJSIP/1001-00000001` — hang up a channel
  `transfer PJSIP/1001-00000001 to 1002`
  `park PJSIP/1001-00000001`
  `record PJSIP/1001-00000001` / `stop recording ...`
  `mute PJSIP/1001-00000001` / `unmute ...`

**Queue Agents:**
  `add 1001 to queue 400` / `remove 1001 from queue 400`
  `pause 1001 in queue 400` / `unpause 1001 in queue 400`
  `queue status` / `queue status 400`

**Conference Control:**
  `who's in conference 800`
  `kick PJSIP/1001 from conference 800`
  `lock conference 800` / `unlock conference 800`

**PJSIP & Diagnostics:**
  `ping 1001` — qualify endpoint
  `registrations` — show all SIP registrations
  `extension states` — BLF/presence for all extensions
  `rotate logs`

**SIP Troubleshooting:**
  `diagnose extension 1005` — full diagnostic (registration, qualify, calls, CDR)
  `troubleshoot 1005` — same as above
  `why can't 1005 make calls` — same as above
  `diagnose trunk 1` — trunk diagnostic (registration, qualify, routes, CDR)
  `endpoint details 1005` — deep PJSIP endpoint info (codecs, transport, auth)
  `sip channels` — show active SIP channels
  `sip channels for 1005` — filtered by endpoint
  `start sip trace` / `start trace 15s` — capture SIP traffic (admin, max 30s)
  `stop trace` — stop and show results
  `trace status` — check if trace is running

**Services & Infrastructure:**
  `start freepbx` / `stop freepbx` / `restart freepbx`
  `enable trunk 1` / `disable trunk 1`
  `validate` — security scan
  `fix permissions` — run chown
  `external ip` — get public IP
  `list notifications`
  `list sound packs`
  `show asterisk context from-internal`
  `sync userman` / `system update`
  `restart service ucp` / `stop service restapps`
  `update certificates`

**Permissions:**
  `list permissions` — show user permission levels
  `set permission username to read/write/admin`

  Permission levels: **read** (view only), **write** (create/modify/delete PBX objects), **admin** (system management, modules, firewall)

Write commands show a preview first. Reply **yes** to confirm.
HELP;
	}
}
