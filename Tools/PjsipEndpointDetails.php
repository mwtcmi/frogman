<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class PjsipEndpointDetails extends AbstractTool {
	public function name() { return 'fm_pjsip_endpoint_details'; }
	public function description() { return 'Deep PJSIP endpoint health check — auth, transport, codecs, contact status, qualify latency. Params: endpoint (required, extension number or trunk name).'; }

	public function validate($params) {
		if (empty($params['endpoint'])) return 'Parameter "endpoint" is required';
		if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $params['endpoint'])) return 'Parameter "endpoint" must be alphanumeric';
		return true;
	}

	public function execute($params, $context) {
		$astman = $this->freepbx->astman;
		if (!$astman || !$astman->connected()) throw new \Exception('Cannot connect to Asterisk Manager');

		$endpoint = $params['endpoint'];

		// Get full endpoint detail
		$epRes = $astman->Command("pjsip show endpoint {$endpoint}");
		$epData = trim($epRes['data'] ?? '');

		if (empty($epData) || stripos($epData, 'unable to find') !== false) {
			throw new \Exception("Endpoint '{$endpoint}' not found");
		}

		// Get contact/AOR info
		$aorRes = $astman->Command("pjsip show aor {$endpoint}");
		$aorData = trim($aorRes['data'] ?? '');

		// Get auth info — FreePBX names auth objects as {endpoint}-auth
		$authRes = $astman->Command("pjsip show auth {$endpoint}-auth");
		$authData = trim($authRes['data'] ?? '');
		if (stripos($authData, 'unable to find') !== false) {
			// Fallback: try without -auth suffix
			$authRes = $astman->Command("pjsip show auth {$endpoint}");
			$authData = trim($authRes['data'] ?? '');
		}

		// Parse key fields from endpoint data
		$parsed = $this->parseEndpointData($epData);

		// Run qualify
		$qualifyRes = $astman->send_request('PJSIPQualify', ['Endpoint' => $endpoint]);

		return [
			'endpoint' => $endpoint,
			'parsed' => $parsed,
			'qualify_result' => $qualifyRes,
			'raw_endpoint' => $epData,
			'raw_aor' => $aorData,
			'raw_auth' => $authData,
		];
	}

	private function parseEndpointData($data) {
		$parsed = [];
		$lines = explode("\n", $data);
		$keyFields = [
			'DeviceState', 'ActiveChannels', 'Codecs', 'allow',
			'transport', 'context', 'callerid', 'rtp_symmetric',
			'force_rport', 'rewrite_contact', 'direct_media',
			'ice_support', 'dtls_auto_generate_cert',
		];
		foreach ($lines as $line) {
			$line = trim($line);
			foreach ($keyFields as $field) {
				if (preg_match('/^\s*' . preg_quote($field, '/') . '\s*[:=]\s*(.+)/i', $line, $m)) {
					$val = trim($m[1]);
					// Skip header template lines
					if (strpos($val, '<TransportId') !== false || strpos($val, '<Endpoint/') !== false) continue;
					$parsed[strtolower($field)] = $val;
				}
			}
			// Catch contact lines — skip header template rows
			if (preg_match('/Contact:\s+(.+)/i', $line, $m)) {
				$val = trim($m[1]);
				if (strpos($val, '<Aor/ContactUri') === false) {
					$parsed['contacts'][] = $val;
				}
			}
		}
		return $parsed;
	}
}
