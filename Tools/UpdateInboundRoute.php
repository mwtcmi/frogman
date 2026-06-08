<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

// In-place inbound route update. Selective field merge: any field not supplied
// keeps its current value. Identity (extension + cidnum) is the compound key
// and is never changed by this tool — a DID-number change is a delete+add, not
// an update.
//
// Routing surface: Core->getDID for the load, Core->editDIDProperties for
// the write. editDIDProperties does an in-place UPDATE on the (extension,
// cidnum) row using its own field allowlist (description, destination, pricid,
// grppre, alertinfo, mohclass, delay_answer, privacyman, pmmaxretries,
// pmminlength, ringing, fanswer, reversal). Critically: addDID is INSERT-only
// and returns false when the row already exists, so it is NOT an upsert path
// despite what the v1.0 build of this tool assumed.
class UpdateInboundRoute extends AbstractTool {
	public function name() { return 'fm_update_inbound_route'; }
	public function description() { return 'Update an existing inbound route in place. Identity params: extension (DID, required), cidnum (optional CID match, default ""). Any subset of these fields gets updated; unspecified fields keep their current value: destination (extension number, "vm <ext>", "rg <id>", "ivr <id>", "tc <id>", or full destination string), description, pricid (override CID name on inbound ring), grppre (prefix prepended to displayed CID), alertinfo (distinctive ring header), mohclass (per-DID hold music), delay_answer (seconds, 0-60), privacyman (0|1), pmmaxretries (1-10), pmminlength (1-20). Requires confirm:true.'; }
	public function validate($params) {
		if (empty($params['extension'])) return 'Parameter "extension" is required (the DID number)';
		// Numeric / range checks
		if (isset($params['delay_answer'])) {
			if (!is_numeric($params['delay_answer']) || (int)$params['delay_answer'] < 0 || (int)$params['delay_answer'] > 60) {
				return 'Parameter "delay_answer" must be a number between 0 and 60 seconds';
			}
		}
		if (isset($params['privacyman'])) {
			$pm = $params['privacyman'];
			if ($pm !== 0 && $pm !== 1 && $pm !== '0' && $pm !== '1' && $pm !== true && $pm !== false) {
				return 'Parameter "privacyman" must be 0 or 1';
			}
		}
		if (isset($params['pmmaxretries']) && (!is_numeric($params['pmmaxretries']) || (int)$params['pmmaxretries'] < 1 || (int)$params['pmmaxretries'] > 10)) {
			return 'Parameter "pmmaxretries" must be a number between 1 and 10';
		}
		if (isset($params['pmminlength']) && (!is_numeric($params['pmminlength']) || (int)$params['pmminlength'] < 1 || (int)$params['pmminlength'] > 20)) {
			return 'Parameter "pmminlength" must be a number between 1 and 20';
		}
		// Two-layer validation on free-text fields: reject framing + dialplan
		// comment chars. Same shape used by UpdateIvr / outbound route tools.
		// extension + cidnum are identity-key fields but they still flow into
		// chat output (error and dry-run messages), so they get the same filter.
		foreach (['extension', 'cidnum', 'description', 'pricid', 'grppre', 'alertinfo', 'mohclass', 'destination'] as $f) {
			if (isset($params[$f]) && preg_match('/[\r\n\0;]/', (string)$params[$f])) {
				return "Parameter \"{$f}\" contains disallowed control or comment characters";
			}
		}
		return true;
	}
	public function requiredPermission() { return null; }
	public function permissionLevel() { return self::PERM_WRITE; }

	// Same shorthand-resolver as AddInboundRoute. Duplicated here intentionally —
	// future cleanup could hoist this to AbstractTool, but keeping the verb-pair
	// (describe / resolve) collocated with the tools that own each direction has
	// kept the diffs small. See AbstractTool::describeDestination for the inverse.
	private function resolveDestination($input) {
		$dest = trim((string)$input);
		if ($dest === '') return $dest;
		if (strpos($dest, ',') !== false) return $dest;
		if (preg_match('/^\d+$/', $dest)) return "from-did-direct,{$dest},1";
		if (preg_match('/^(vm|voicemail)\s+(\d+)$/i', $dest, $m)) return "ext-local,vmu{$m[2]},1";
		if (preg_match('/^(rg|ringgroup)\s+(\d+)$/i', $dest, $m)) return "ext-group,{$m[2]},1";
		if (preg_match('/^ivr\s+(\d+)$/i', $dest, $m)) return "ivr-{$m[1]},s,1";
		if (preg_match('/^(tc|timecondition)\s+(\d+)$/i', $dest, $m)) return "timeconditions,{$m[2]},1";
		return $dest;
	}

	// Mirror AddInboundRoute's classifier so an update that re-points to a
	// device-only extension surfaces the same red-in-GUI warning the add path
	// already shows. The advisory is purely informational; the route still works.
	private function classifyExtensionDestination($ext) {
		$user = $this->freepbx->Core->getUser((string)$ext);
		if (!empty($user)) return 'user';
		$device = $this->freepbx->Core->getDevice((string)$ext);
		if (!empty($device)) return 'device-only';
		return 'none';
	}

	public function execute($params, $context) {
		$confirm = !empty($params['confirm']) && $params['confirm'] === true;
		$ext = (string)$params['extension'];
		$cid = (string)($params['cidnum'] ?? '');

		$current = $this->freepbx->Core->getDID($ext, $cid);
		if (empty($current)) {
			// Sanitize before chat-formatter interpolation. extension and
			// cidnum reach the formatter through formatGenericResult's prose
			// rendering of the `error` key, so a raw backtick or {{cmd:...}}
			// payload would render as a clickable chip on subsequent admin
			// review of the chat history (GHSA-7qvv class).
			$extErr = $this->frogman->sanitizeForChat($ext);
			$cidNote = $cid !== '' ? " with CID match `" . $this->frogman->sanitizeForChat($cid) . "`" : '';
			return ['error' => "Inbound route for DID `{$extErr}`{$cidNote} not found"];
		}

		// editDIDProperties takes only the columns that changed, plus the
		// (extension, cidnum) key. Build a minimal payload so we never write
		// fields the caller didn't ask to change.
		$payload = ['extension' => $ext, 'cidnum' => $cid];

		// Field surface. Caller-friendly keys map 1:1 to incoming columns here,
		// except destination which gets shorthand-resolved first.
		$plainFields = ['description', 'pricid', 'grppre', 'alertinfo', 'mohclass', 'delay_answer', 'privacyman', 'pmmaxretries', 'pmminlength'];

		$changed = [];
		$advisory = '';

		// destination: resolve shorthand and run the device-only check.
		if (array_key_exists('destination', $params)) {
			$newDest = $this->resolveDestination($params['destination']);
			if (preg_match('/^from-did-direct,(\d+),1$/', $newDest, $m)) {
				$kind = $this->classifyExtensionDestination($m[1]);
				if ($kind === 'none') {
					return ['error' => "Extension {$m[1]} does not exist (no user or device record). Route not updated."];
				}
				if ($kind === 'device-only') {
					$advisory = " ⚠️ Extension {$m[1]} is device-only (no user record). The route will work, but the FreePBX GUI Inbound Routes editor will show this destination as unknown/red.";
				}
			}
			$oldDest = (string)($current['destination'] ?? '');
			if ($oldDest !== $newDest) {
				$payload['destination'] = $newDest;
				$changed[] = ['field' => 'destination', 'old' => $oldDest, 'new' => $newDest];
			}
		}

		foreach ($plainFields as $f) {
			if (!array_key_exists($f, $params)) continue;
			$new = (string)$params[$f];
			// Normalize privacyman bools/strings to 0|1 string.
			if ($f === 'privacyman') {
				$new = ($params[$f] === true || $params[$f] === 1 || $params[$f] === '1') ? '1' : '0';
			}
			$old = (string)($current[$f] ?? '');
			if ($old !== $new) {
				$payload[$f] = $new;
				$changed[] = ['field' => $f, 'old' => $old, 'new' => $new];
			}
		}

		// sanitize for chat interpolation
		$extSan = $this->frogman->sanitizeForChat($ext);
		$cidLabel = $cid !== '' ? " / CID `" . $this->frogman->sanitizeForChat($cid) . "`" : '';

		if (empty($changed)) {
			return ['dry_run' => true, 'message' => "No changes detected for inbound route DID `{$extSan}`{$cidLabel}."];
		}

		if (!$confirm) {
			$diff = [];
			foreach ($changed as $c) {
				$oldSan = $this->frogman->sanitizeForChat($c['old']);
				$newSan = $this->frogman->sanitizeForChat($c['new']);
				$oldDisp = $oldSan === '' ? '_(empty)_' : "`{$oldSan}`";
				$newDisp = $newSan === '' ? '_(empty)_' : "`{$newSan}`";
				$diff[] = "{$c['field']}: {$oldDisp} → {$newDisp}";
			}
			return ['dry_run' => true, 'message' => "Would update inbound route DID `{$extSan}`{$cidLabel}:\n• " . implode("\n• ", $diff) . "{$advisory}\n\nReply yes to confirm."];
		}

		// In-place UPDATE keyed on (extension, cidnum). Returns false on no-op
		// or bad input; both cases are already prevented above (we only get here
		// when $changed is non-empty and payload contains valid columns).
		$ok = \FreePBX::Core()->editDIDProperties($payload);
		if ($ok === false) {
			return ['error' => "editDIDProperties refused the write for DID `{$extSan}`{$cidLabel} — no recognized columns in payload."];
		}
		$n = count($changed);
		$noun = $n === 1 ? 'field' : 'fields';
		return ['dry_run' => false, 'message' => "✅ Inbound route DID `{$extSan}`{$cidLabel} updated ({$n} {$noun} changed).{$advisory}", 'needs_reload' => true];
	}
}
