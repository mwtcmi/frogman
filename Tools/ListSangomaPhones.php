<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class ListSangomaPhones extends AbstractTool {
	public function name() { return 'fm_list_sangoma_phones'; }
	public function description() { return 'List every Sangoma phone managed by DPMA — extension, model, firmware, IP, registration state. Sangoma/DPMA phones only (use fm_list_extensions for the full multi-vendor list).'; }

	public function validate($params) { return true; }

	public function execute($params, $context) {
		$astman = $this->freepbx->astman;
		if (!$astman || !$astman->connected()) throw new \Exception('Cannot connect to Asterisk Manager');

		$res = $astman->Command('digium_phones show phones');
		$raw = trim($res['data'] ?? '');

		// DPMA output format: bare <ext>-<line> identifiers, one per line, sandwiched
		// between "---- Digium Phones ----" and "---- N Phones Found ----" footer.
		$phones = [];
		foreach (explode("\n", $raw) as $line) {
			$line = trim($line);
			if ($line === '' || stripos($line, 'Privilege:') === 0) continue;
			if (strpos($line, '----') === 0) continue;
			if (preg_match('/^(\S+?)-(\d+)$/', $line, $m)) {
				$phones[] = ['identifier' => $line, 'ext' => $m[1], 'line' => (int)$m[2]];
			} else {
				$phones[] = ['identifier' => $line];
			}
		}

		// Enrich with one bulk EPM call (signature: endpointGetMapping($ext, $orderby, $detail))
		// then look each phone up by its full identifier. EPM stores ext as "101-1".
		$endpoint = \FreePBX::Endpoint();
		$mapByIdent = [];
		try {
			$all = $endpoint->endpointGetMapping('ALL', '', true) ?: [];
			foreach ($all as $row) {
				if (!empty($row['ext'])) $mapByIdent[$row['ext']] = $row;
			}
		} catch (\Throwable $e) {}

		// Cross-reference Core BMO for the user-facing extension display name (works even
		// when EPM has no mapping). Build once, look up by base ext.
		$nameByExt = [];
		try {
			$users = \FreePBX::Core()->listUsers() ?: [];
			foreach ($users as $u) $nameByExt[$u[0]] = $u[1];
		} catch (\Throwable $e) {}

		foreach ($phones as &$p) {
			if (empty($p['ext'])) continue;
			$row = $mapByIdent[$p['identifier']] ?? null;
			if ($row) {
				$p['model'] = $row['model'] ?? null;
				$p['mac']   = $row['mac'] ?? null;
				$p['brand'] = $row['brand'] ?? null;
				$p['template'] = $row['template'] ?? null;
			}
			if (!empty($nameByExt[$p['ext']])) $p['name'] = $nameByExt[$p['ext']];
			try {
				$contacts = $endpoint->getpjsipAORContactIpsByExten($p['ext']) ?: [];
				$p['registered'] = !empty($contacts);
				$p['contact_count'] = count($contacts);
			} catch (\Throwable $e) {}
		}
		unset($p);

		return [
			'count' => count($phones),
			'phones' => $phones,
		];
	}
}
