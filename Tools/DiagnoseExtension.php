<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class DiagnoseExtension extends AbstractTool {
	public function name() { return 'fm_diagnose_extension'; }
	public function description() { return 'Composite SIP diagnostic for an extension — runs endpoint health, qualify, active calls, and recent CDR in one shot. Params: ext (required).'; }

	public function validate($params) {
		if (empty($params['ext'])) return 'Parameter "ext" is required';
		if (!preg_match('/^\d+$/', $params['ext'])) return 'Parameter "ext" must be numeric';
		return true;
	}

	public function execute($params, $context) {
		$ext = $params['ext'];
		$astman = $this->freepbx->astman;
		if (!$astman || !$astman->connected()) throw new \Exception('Cannot connect to Asterisk Manager');

		$result = ['extension' => $ext, 'checks' => []];

		// 1. Extension exists in FreePBX?
		$db = $this->freepbx->Database;
		$sth = $db->prepare("SELECT id, name, voicemail, tech FROM users JOIN devices ON users.extension = devices.id WHERE users.extension = ?");
		$sth->execute([$ext]);
		$extInfo = $sth->fetch(\PDO::FETCH_ASSOC);
		$result['checks']['extension_config'] = $extInfo ?: ['status' => 'NOT FOUND'];

		if (empty($extInfo)) {
			$result['summary'] = "Extension {$ext} does not exist in FreePBX.";
			return $result;
		}

		// 2. PJSIP endpoint status
		$epRes = $astman->Command("pjsip show endpoint {$ext}");
		$epData = trim($epRes['data'] ?? '');
		$deviceState = 'unknown';
		if (preg_match('/DeviceState\s*:\s*(\S+)/i', $epData, $m)) {
			$deviceState = $m[1];
		}
		$contacts = [];
		if (preg_match_all('/Contact:\s+(.+)/i', $epData, $m)) {
			$contacts = array_filter(array_map('trim', $m[1]), function($c) {
				return strpos($c, '<Aor/ContactUri') === false;
			});
			$contacts = array_values($contacts);
		}
		$result['checks']['endpoint'] = [
			'device_state' => $deviceState,
			'contacts' => $contacts,
			'registered' => !empty($contacts),
		];

		// 3. Qualify / latency
		$qualRes = $astman->send_request('PJSIPQualify', ['Endpoint' => $ext]);
		$result['checks']['qualify'] = $qualRes;

		// 4. Active calls involving this extension
		$chanRes = $astman->Command("core show channels concise");
		$chanData = trim($chanRes['data'] ?? '');
		$activeCalls = [];
		if (!empty($chanData)) {
			foreach (explode("\n", $chanData) as $line) {
				if (stripos($line, "PJSIP/{$ext}") !== false) {
					$parts = explode('!', trim($line));
					$activeCalls[] = [
						'channel' => $parts[0] ?? '',
						'state' => $parts[4] ?? '',
						'application' => $parts[5] ?? '',
						'duration' => $parts[10] ?? '',
						'bridged_to' => $parts[11] ?? '',
					];
				}
			}
		}
		$result['checks']['active_calls'] = [
			'count' => count($activeCalls),
			'calls' => $activeCalls,
		];

		// 5. Recent CDR (last 10 calls)
		$cdrSth = $db->prepare(
			"SELECT calldate, src, dst, disposition, duration, billsec, channel, dstchannel
			 FROM asteriskcdrdb.cdr
			 WHERE src = ? OR dst = ?
			 ORDER BY calldate DESC LIMIT 10"
		);
		$cdrSth->execute([$ext, $ext]);
		$cdr = $cdrSth->fetchAll(\PDO::FETCH_ASSOC);
		$result['checks']['recent_cdr'] = [
			'count' => count($cdr),
			'records' => $cdr,
		];

		// 6. Build summary
		$issues = [];
		if (!$result['checks']['endpoint']['registered']) {
			$issues[] = "Phone is NOT registered (no contacts)";
		}
		if ($deviceState === 'UNAVAILABLE' || $deviceState === 'UNKNOWN') {
			$issues[] = "Device state: {$deviceState}";
		}
		if (!empty($cdr)) {
			$failed = array_filter($cdr, function($r) { return $r['disposition'] !== 'ANSWERED'; });
			$failRate = count($failed) / count($cdr) * 100;
			if ($failRate > 50) {
				$issues[] = sprintf("High failure rate: %.0f%% of last %d calls unanswered", $failRate, count($cdr));
			}
		}

		$result['summary'] = empty($issues)
			? "Extension {$ext} ({$extInfo['name']}) appears healthy — registered, reachable."
			: "Extension {$ext} ({$extInfo['name']}) has issues: " . implode('; ', $issues);

		return $result;
	}
}
