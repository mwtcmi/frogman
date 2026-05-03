<?php
namespace FreePBX\modules\Frogman\Tools;

require_once __DIR__ . '/AbstractTool.php';

class GetExtensionHealth extends AbstractTool {

	public function name() {
		return 'fm_get_extension_health';
	}

	public function description() {
		return 'Health check for an extension: config status, SIP registration, and recent CDR activity.';
	}

	public function validate($params) {
		if (empty($params['ext'])) {
			return 'Parameter "ext" is required';
		}
		if (!preg_match('/^\d+$/', $params['ext'])) {
			return 'Parameter "ext" must be numeric';
		}
		return true;
	}

	public function requiredPermission() {
		return null;
	}

	public function execute($params, $context) {
		$ext = $params['ext'];

		// Config check
		$user = $this->freepbx->Core->getUser($ext);
		if (empty($user)) {
			throw new \Exception("Extension {$ext} not found");
		}
		$device = $this->freepbx->Core->getDevice($ext);

		// AMI registration check
		$registered = false;
		$contactInfo = null;
		$astman = $this->freepbx->astman;
		if ($astman && $astman->connected()) {
			$res = $astman->Command("pjsip show endpoint {$ext}");
			$data = isset($res['data']) ? $res['data'] : '';
			$registered = (stripos($data, 'Not in use') !== false || stripos($data, 'Avail') !== false);
			// Try to get contact/IP
			$contactRes = $astman->Command("pjsip show contacts");
			$contactData = isset($contactRes['data']) ? $contactRes['data'] : '';
			foreach (explode("\n", $contactData) as $line) {
				if (stripos($line, $ext . '/') !== false || stripos($line, $ext . ';') !== false) {
					$contactInfo = trim($line);
					$registered = true;
					break;
				}
			}
		}

		// Recent CDR (last 5 calls)
		$cdrDb = $this->freepbx->Database;
		$sql = "SELECT calldate, src, dst, disposition, duration, billsec
		        FROM asteriskcdrdb.cdr
		        WHERE src = ? OR dst = ?
		        ORDER BY calldate DESC LIMIT 5";
		$sth = $cdrDb->prepare($sql);
		$sth->execute([$ext, $ext]);
		$recentCalls = $sth->fetchAll(\PDO::FETCH_ASSOC);

		return [
			'extension' => $ext,
			'name' => $user['name'],
			'configured' => true,
			'tech' => isset($device['tech']) ? $device['tech'] : 'unknown',
			'registered' => $registered,
			'contact' => $contactInfo,
			'voicemail' => $user['voicemail'],
			'recent_calls' => $recentCalls,
			'recent_call_count' => count($recentCalls),
		];
	}
}
