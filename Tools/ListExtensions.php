<?php
namespace FreePBX\modules\Frogman\Tools;

require_once __DIR__ . '/AbstractTool.php';

class ListExtensions extends AbstractTool {

	public function name() {
		return 'fm_list_extensions';
	}

	public function description() {
		return 'List all extensions. Optional filters: tech (pjsip/sip), search (name or number substring).';
	}

	public function validate($params) {
		if (isset($params['tech']) && !in_array($params['tech'], ['pjsip', 'sip', 'dahdi', 'iax2', ''])) {
			return 'Invalid tech filter. Must be one of: pjsip, sip, dahdi, iax2';
		}
		return true;
	}

	public function requiredPermission() {
		return null;
	}

	public function execute($params, $context) {
		$techFilter = isset($params['tech']) ? $params['tech'] : '';
		$search = isset($params['search']) ? strtolower($params['search']) : '';

		$devices = $this->freepbx->Core->getAllDevicesByType($techFilter);

		$result = [];
		foreach ($devices as $dev) {
			$ext = $dev['id'];
			$name = $dev['description'];
			$tech = $dev['tech'];

			if ($search && stripos($ext, $search) === false && stripos($name, $search) === false) {
				continue;
			}

			$result[] = [
				'extension' => $ext,
				'name' => $name,
				'tech' => $tech,
				'dial' => $dev['dial'],
			];
		}

		return [
			'count' => count($result),
			'extensions' => $result,
		];
	}
}
