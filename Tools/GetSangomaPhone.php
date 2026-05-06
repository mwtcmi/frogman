<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class GetSangomaPhone extends AbstractTool {
	public function name() { return 'fm_get_sangoma_phone'; }
	public function description() { return 'Get DPMA detail for one Sangoma phone — model, firmware, IP, state, last seen, lines. Sangoma/DPMA phones only. Params: ext (required).'; }

	public function validate($params) {
		if (empty($params['ext'])) return 'Parameter "ext" is required';
		if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $params['ext'])) return 'Parameter "ext" must be alphanumeric';
		return true;
	}

	public function execute($params, $context) {
		$ext = $params['ext'];
		$astman = $this->freepbx->astman;
		if (!$astman || !$astman->connected()) throw new \Exception('Cannot connect to Asterisk Manager');

		// DPMA identifies phones as <ext>-<line>. If caller passed bare ext, try -1
		// first (the typical primary line); fall back to the bare identifier.
		$identifier = strpos($ext, '-') !== false ? $ext : "{$ext}-1";
		$res = $astman->Command("digium_phones show phone {$identifier}");
		$raw = trim($res['data'] ?? '');
		$payload = trim(preg_replace('/^Privilege:\s*Command\s*/im', '', $raw));

		$known = $payload !== '' && stripos($payload, 'No phone') === false && stripos($payload, 'not found') === false;
		if (!$known) {
			return ['extension' => $ext, 'identifier' => $identifier, 'known_to_dpma' => false, 'message' => "DPMA does not know about phone {$identifier}"];
		}

		// DPMA's "show phone" returns the configured settings for this phone (60+ fields).
		// Parse every "Key: Value" line we find — let the caller decide what's interesting.
		$parsed = [];
		foreach (explode("\n", $payload) as $line) {
			$line = rtrim($line);
			if ($line === '') continue;
			if (preg_match('/^\s*([A-Za-z][A-Za-z0-9 _\-\/]+?)\s*:\s*(.*)$/', $line, $m)) {
				$key = strtolower(preg_replace('/\s+/', '_', trim($m[1])));
				$parsed[$key] = trim($m[2]);
			}
		}

		// Cross-reference EPM mapping (brand, model, template, MAC) and live PJSIP registration —
		// DPMA's show phone returns config, not runtime state, so layer registration on top.
		$mapping = null;
		$contacts = [];
		$endpoint = \FreePBX::Endpoint();
		try {
			// EPM signature: endpointGetMapping($ext, $orderby, $detail). EPM keys by full
			// identifier ("101-1") and wraps single results in [0 => row].
			$rows = $endpoint->endpointGetMapping($identifier, '', true) ?: [];
			$mapping = $rows[0] ?? null;
		} catch (\Throwable $e) {}
		try {
			$contacts = $endpoint->getpjsipAORContactIpsByExten($ext) ?: [];
		} catch (\Throwable $e) {}

		return [
			'extension' => $ext,
			'identifier' => $identifier,
			'known_to_dpma' => true,
			'parsed' => $parsed,
			'epm_mapping' => $mapping,
			'sip_registration' => [
				'registered' => !empty($contacts),
				'contacts' => $contacts,
			],
			'raw' => $raw,
		];
	}
}
