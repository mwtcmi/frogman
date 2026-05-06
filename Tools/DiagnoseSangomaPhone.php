<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class DiagnoseSangomaPhone extends AbstractTool {
	public function name() { return 'fm_diagnose_sangoma_phone'; }
	public function description() { return 'Composite diagnostic for a Sangoma phone managed by DPMA — checks EPM mapping, license, SIP registration, DPMA-side state, firmware vs latest, alerts, and qualify in one shot. Sangoma/DPMA phones only. Params: ext (required).'; }

	public function validate($params) {
		if (empty($params['ext'])) return 'Parameter "ext" is required';
		if (!preg_match('/^\d+$/', $params['ext'])) return 'Parameter "ext" must be numeric';
		return true;
	}

	public function execute($params, $context) {
		$ext = $params['ext'];
		$astman = $this->freepbx->astman;
		if (!$astman || !$astman->connected()) throw new \Exception('Cannot connect to Asterisk Manager');

		$endpoint = \FreePBX::Endpoint();
		$result = ['extension' => $ext, 'checks' => []];
		$issues = [];

		// 1. EPM mapping — does this ext have a Sangoma phone provisioned?
		// EPM keys by full identifier ("101-1"), signature ($ext, $orderby, $detail),
		// wraps single results in [0 => row].
		$mapping = null;
		try {
			$rows = $endpoint->endpointGetMapping("{$ext}-1", '', true) ?: [];
			$mapping = $rows[0] ?? null;
		} catch (\Throwable $e) {
			$mapping = null;
		}
		$result['checks']['epm_mapping'] = $mapping ?: ['status' => 'NOT FOUND'];

		if (empty($mapping)) {
			$result['summary'] = "Extension {$ext} has no EPM mapping — not provisioned via Endpoint Manager.";
			return $result;
		}

		$brand = strtolower($mapping['brand'] ?? $mapping['vendor'] ?? '');
		$mac   = strtolower($mapping['mac'] ?? '');
		$model = $mapping['model'] ?? '';
		$isSangoma = (strpos($brand, 'sangoma') !== false) || (strpos($brand, 'digium') !== false);
		if (!$isSangoma) {
			$result['summary'] = "Extension {$ext} is mapped to a {$brand} phone, not a Sangoma/Digium phone — use fm_diagnose_extension instead.";
			return $result;
		}

		// 2. License coverage — endpointCheckLicense() returns system-wide brand counts:
		//    ['sangoma' => N, 'digium' => M] — phones-of-that-brand currently licensed.
		//    A phone is "covered" if its brand has at least one license slot in use.
		$licCounts = null;
		try {
			$licCounts = $endpoint->endpointCheckLicense();
		} catch (\Throwable $e) {
			$licCounts = ['error' => $e->getMessage()];
		}
		$brandKey = (strpos($brand, 'digium') !== false) ? 'digium' : 'sangoma';
		$brandCount = is_array($licCounts) ? (int)($licCounts[$brandKey] ?? 0) : 0;
		$result['checks']['license'] = [
			'counts' => $licCounts,
			'brand' => $brandKey,
			'brand_count' => $brandCount,
			'covered' => $brandCount > 0,
		];
		if ($brandCount === 0) {
			$issues[] = "No {$brandKey} license slots in use system-wide";
		}

		// 3. SIP registration
		$contactIps = [];
		try {
			$contactIps = $endpoint->getpjsipAORContactIpsByExten($ext) ?: [];
		} catch (\Throwable $e) {
			$contactIps = [];
		}
		$result['checks']['sip_registration'] = [
			'registered' => !empty($contactIps),
			'contacts' => $contactIps,
		];
		if (empty($contactIps)) {
			$issues[] = 'Phone is NOT registered to PJSIP (no contacts)';
		}

		// 4. DPMA-side state — show phone <ext> via AMI Command
		$dpmaPhone = $astman->Command("digium_phones show phone {$ext}");
		$dpmaRaw = trim($dpmaPhone['data'] ?? '');
		$phoneFw = null;
		$phoneIp = null;
		$phoneState = null;
		$phoneLastSeen = null;
		if (preg_match('/Firmware\s*:\s*(\S+)/i', $dpmaRaw, $m))     $phoneFw = $m[1];
		if (preg_match('/IP\s*Address\s*:\s*(\S+)/i', $dpmaRaw, $m)) $phoneIp = $m[1];
		if (preg_match('/State\s*:\s*(\S+)/i', $dpmaRaw, $m))        $phoneState = $m[1];
		if (preg_match('/Last\s*Seen\s*:\s*(.+)/i', $dpmaRaw, $m))   $phoneLastSeen = trim($m[1]);
		$dpmaKnows = !(stripos($dpmaRaw, 'No phone') !== false || stripos($dpmaRaw, 'not found') !== false || $dpmaRaw === '');
		$result['checks']['dpma_state'] = [
			'known_to_dpma' => $dpmaKnows,
			'state' => $phoneState,
			'ip' => $phoneIp,
			'firmware' => $phoneFw,
			'last_seen' => $phoneLastSeen,
		];
		if (!$dpmaKnows) {
			$issues[] = 'DPMA does not know about this phone (provisioning never completed)';
		}

		// 5. Firmware audit — compare phone's firmware to latest available for its model
		$latestFw = null;
		if ($model && $phoneFw) {
			$fwOut = $astman->Command('digium_phones show firmwares');
			$fwRaw = trim($fwOut['data'] ?? '');
			// Match lines containing the model and a version string
			foreach (explode("\n", $fwRaw) as $line) {
				if (stripos($line, $model) !== false && preg_match('/(\d+\.\d+\.\d+(?:\.\d+)?)/', $line, $m)) {
					$latestFw = $m[1];
					break;
				}
			}
		}
		$result['checks']['firmware_audit'] = [
			'current' => $phoneFw,
			'latest_for_model' => $latestFw,
			'up_to_date' => ($latestFw && $phoneFw) ? version_compare($phoneFw, $latestFw, '>=') : null,
		];
		if ($latestFw && $phoneFw && version_compare($phoneFw, $latestFw, '<')) {
			$issues[] = "Firmware out of date: phone has {$phoneFw}, latest is {$latestFw}";
		}

		// 6. DPMA alerts for this phone
		$alertsOut = $astman->Command('digium_phones show alerts');
		$alertsRaw = trim($alertsOut['data'] ?? '');
		$phoneAlerts = [];
		foreach (explode("\n", $alertsRaw) as $line) {
			$line = trim($line);
			if ($line === '' || stripos($line, 'Privilege:') === 0) continue;
			if ($mac && stripos($line, $mac) !== false) {
				$phoneAlerts[] = $line;
			} elseif (preg_match('/\b' . preg_quote($ext, '/') . '\b/', $line)) {
				$phoneAlerts[] = $line;
			}
		}
		$result['checks']['dpma_alerts'] = [
			'count' => count($phoneAlerts),
			'alerts' => $phoneAlerts,
		];
		if (!empty($phoneAlerts)) {
			$issues[] = count($phoneAlerts) . ' DPMA alert(s) present';
		}

		// 7. Qualify (reachability ping via PJSIP)
		$qual = $astman->send_request('PJSIPQualify', ['Endpoint' => $ext]);
		$result['checks']['qualify'] = $qual;

		// 8. Summary
		$model = $mapping['model'] ?? 'Sangoma phone';
		$result['summary'] = empty($issues)
			? "Extension {$ext} ({$model}) appears healthy — provisioned, licensed, registered, firmware current."
			: "Extension {$ext} ({$model}) has issues: " . implode('; ', $issues);

		return $result;
	}
}
