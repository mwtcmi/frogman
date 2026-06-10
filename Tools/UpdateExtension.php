<?php
namespace FreePBX\modules\Frogman\Tools;

require_once __DIR__ . '/AbstractTool.php';

// Widened in-place extension update. Selective field merge across both the
// user record (Core->getUser) and the device record (Core->getDevice). Any
// field not supplied keeps its current value; voicemail, follow-me, Userman
// rows, and everything else not in the merge map carry through untouched.
//
// Routing: FreePBX has no editUser/editDevice; the canonical edit path is
// delUser+addUser(editmode=true) and delDevice+addDevice(editmode=true) per
// feedback_freepbx_core_edit_pattern memory. editmode=true tells Core to
// skip the AstDB teardown a real delete does, so registrations, hint state,
// and device→user links survive the cycle. We seed the add from get*()
// output so every field we did not touch carries through.
//
// addUser takes a flat shape; addDevice expects the wrapped ['key' =>
// ['value' => x]] shape that generateDefaultDeviceSettings produces, while
// getDevice returns flat. We wrap before the addDevice call.
//
// Single-attribute chat anchors (set caller id, set recording, set call
// waiting, set ring time, set extension email, etc.) stay routed to the
// existing fm_set_* tools. fm_update_extension is the JSON/MCP path for
// multi-field atomic edits, same shape as fm_update_outbound_route and
// fm_update_ivr.
class UpdateExtension extends AbstractTool {

	public function name() {
		return 'fm_update_extension';
	}

	public function description() {
		return 'Update an existing extension in place. Params: ext (required), confirm:true to execute. Any subset of these fields gets updated; unspecified fields keep their current value. User: name, outboundcid, ringtimer (0-300 sec, 0=system default), mohclass, callwaiting (enabled|disabled), concurrency_limit (0-100, 0=unlimited), accountcode, recording_in_external / recording_out_external / recording_in_internal / recording_out_internal (dontcare|always|never|onlydestination|onlysource). Device: secret, callerid (device-level), dtmfmode (rfc4733|inband|info|auto), transport, allow (comma-separated codec allowlist), disallow, max_contacts (1-10), direct_media (yes|no; flip to no when calls need to flow through the PBX, e.g. for MixMonitor recording or NAT). Voicemail, follow-me, Userman and every other extension setting are preserved.';
	}

	// Map of user-friendly param key → ['side' (user|device), 'col' (DB col),
	// 'validator' (callable or null), 'sensitive' (hide value in diff)].
	// Order is the order fields appear in the diff output.
	private function fieldMap() {
		$enum = function($allowed) {
			return function($v) use ($allowed) {
				return in_array((string)$v, $allowed, true);
			};
		};
		$intRange = function($lo, $hi) {
			return function($v) use ($lo, $hi) {
				return is_numeric($v) && (int)$v >= $lo && (int)$v <= $hi;
			};
		};
		$recordingEnum = $enum(['dontcare', 'always', 'never', 'onlydestination', 'onlysource', '']);
		return [
			// User-side
			'name'                     => ['side' => 'user',   'col' => 'name'],
			'outboundcid'              => ['side' => 'user',   'col' => 'outboundcid'],
			'ringtimer'                => ['side' => 'user',   'col' => 'ringtimer',                'validator' => $intRange(0, 300)],
			'mohclass'                 => ['side' => 'user',   'col' => 'mohclass'],
			'callwaiting'              => ['side' => 'user',   'col' => 'callwaiting',              'validator' => $enum(['enabled', 'disabled'])],
			'concurrency_limit'        => ['side' => 'user',   'col' => 'concurrency_limit',        'validator' => $intRange(0, 100)],
			'accountcode'              => ['side' => 'user',   'col' => 'accountcode'],
			'recording_in_external'    => ['side' => 'user',   'col' => 'recording_in_external',    'validator' => $recordingEnum],
			'recording_out_external'   => ['side' => 'user',   'col' => 'recording_out_external',   'validator' => $recordingEnum],
			'recording_in_internal'    => ['side' => 'user',   'col' => 'recording_in_internal',    'validator' => $recordingEnum],
			'recording_out_internal'   => ['side' => 'user',   'col' => 'recording_out_internal',   'validator' => $recordingEnum],
			// Device-side
			'secret'                   => ['side' => 'device', 'col' => 'secret',                   'sensitive' => true],
			'callerid'                 => ['side' => 'device', 'col' => 'callerid'],
			'dtmfmode'                 => ['side' => 'device', 'col' => 'dtmfmode',                 'validator' => $enum(['rfc4733', 'inband', 'info', 'auto'])],
			'transport'                => ['side' => 'device', 'col' => 'transport'],
			'allow'                    => ['side' => 'device', 'col' => 'allow'],
			'disallow'                 => ['side' => 'device', 'col' => 'disallow'],
			'max_contacts'             => ['side' => 'device', 'col' => 'max_contacts',             'validator' => $intRange(1, 10)],
			'direct_media'             => ['side' => 'device', 'col' => 'direct_media',             'validator' => $enum(['yes', 'no'])],
		];
	}

	public function validate($params) {
		if (empty($params['ext'])) {
			return 'Parameter "ext" is required';
		}
		if (!preg_match('/^\d+$/', $params['ext'])) {
			return 'Parameter "ext" must be numeric';
		}
		// Two-layer validation: reject framing + dialplan comment chars on
		// every free-text field including ext itself (defense-in-depth,
		// matches the v2.5.0 inbound-route convention; ext is digit-validated
		// above but the framing-char loop is the convention).
		$freeText = ['ext', 'name', 'outboundcid', 'mohclass', 'accountcode', 'secret', 'callerid', 'transport', 'allow', 'disallow'];
		foreach ($freeText as $f) {
			if (isset($params[$f]) && preg_match('/[\r\n\0;]/', (string)$params[$f])) {
				return "Parameter \"{$f}\" contains disallowed control or comment characters";
			}
		}
		// Per-field validators from the field map.
		foreach ($this->fieldMap() as $key => $spec) {
			if (!array_key_exists($key, $params)) continue;
			if (!empty($spec['validator']) && !$spec['validator']($params[$key])) {
				return "Parameter \"{$key}\" value is not in the allowed range or set";
			}
		}
		return true;
	}

	public function requiredPermission() {
		return 'write:extension';
	}

	public function permissionLevel() { return self::PERM_WRITE; }

	public function execute($params, $context) {
		$ext = (string)$params['ext'];
		$confirm = !empty($params['confirm']) && $params['confirm'] === true;

		$device = $this->freepbx->Core->getDevice($ext);
		if (empty($device)) {
			$extSan = $this->frogman->sanitizeForChat($ext);
			return ['error' => "Extension `{$extSan}` not found"];
		}
		$user = $this->freepbx->Core->getUser($ext);
		if (empty($user)) {
			$extSan = $this->frogman->sanitizeForChat($ext);
			return ['error' => "Extension `{$extSan}` has a device but no user record (inconsistent state, edit aborted)"];
		}

		$userChanges = [];
		$deviceChanges = [];
		$diffRows = [];

		foreach ($this->fieldMap() as $key => $spec) {
			if (!array_key_exists($key, $params)) continue;
			$side = $spec['side'];
			$col  = $spec['col'];
			$current = $side === 'user' ? ($user[$col] ?? '') : ($device[$col] ?? '');
			$newVal = $params[$key];
			// Cast both sides to string for comparison so "0" vs 0 doesn't trip.
			if ((string)$current === (string)$newVal) continue;
			if ($side === 'user') {
				$userChanges[$col] = $newVal;
			} else {
				$deviceChanges[$col] = $newVal;
			}
			$sensitive = !empty($spec['sensitive']);
			$diffRows[] = [
				'field' => $key,
				'from'  => $sensitive ? '***' : (string)$current,
				'to'    => $sensitive ? '***' : (string)$newVal,
			];
		}

		// Sanitize ext once for every chat-interpolation site below.
		$extSan = $this->frogman->sanitizeForChat($ext);

		if (empty($diffRows)) {
			return ['dry_run' => true, 'message' => "No changes detected for extension `{$extSan}`."];
		}
		$diffLines = [];
		foreach ($diffRows as $d) {
			$fromSan = $this->frogman->sanitizeForChat($d['from']);
			$toSan   = $this->frogman->sanitizeForChat($d['to']);
			$fromDisp = $fromSan === '' ? '_(empty)_' : "`{$fromSan}`";
			$toDisp   = $toSan === '' ? '_(empty)_' : "`{$toSan}`";
			$diffLines[] = "{$d['field']}: {$fromDisp} → {$toDisp}";
		}
		$diffText = implode("\n• ", $diffLines);

		if (!$confirm) {
			return [
				'dry_run' => true,
				'message' => "Would update extension `{$extSan}`:\n• {$diffText}\n\nVoicemail, follow-me, Userman, and every unchanged setting will be preserved.\n\nReply yes to confirm.",
				'changes' => $diffRows,
			];
		}

		// User-side write: del+add(editmode=true) with the merged flat shape.
		if (!empty($userChanges)) {
			$userVars = $user;
			foreach ($userChanges as $col => $val) {
				$userVars[$col] = $val;
			}
			$userVars['extension'] = $ext;
			$this->freepbx->Core->delUser($ext, true);
			$this->freepbx->Core->addUser($ext, $userVars, true);
		}

		// Device-side write: del+add(editmode=true) with the wrapped shape.
		// addDevice expects ['col' => ['value' => x]]; getDevice returns flat.
		if (!empty($deviceChanges)) {
			$deviceVars = [];
			foreach ($device as $k => $v) {
				$deviceVars[$k] = ['value' => $v];
			}
			foreach ($deviceChanges as $col => $val) {
				$deviceVars[$col] = ['value' => $val];
			}
			$this->freepbx->Core->delDevice($ext, true);
			$this->freepbx->Core->addDevice($ext, $device['tech'], $deviceVars, true);
		}

		$n = count($diffRows);
		$noun = $n === 1 ? 'field' : 'fields';
		return [
			'dry_run' => false,
			'message' => "✅ Extension `{$extSan}` updated ({$n} {$noun} changed):\n• {$diffText}",
			'changes' => $diffRows,
			'needs_reload' => true,
		];
	}
}
