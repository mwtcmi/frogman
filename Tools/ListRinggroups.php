<?php
namespace FreePBX\modules\Frogman\Tools;

require_once __DIR__ . '/AbstractTool.php';

class ListRinggroups extends AbstractTool {

	public function name() {
		return 'fm_list_ringgroups';
	}

	public function description() {
		return 'List all configured ring groups with their number and description.';
	}

	public function validate($params) {
		return true;
	}

	public function requiredPermission() {
		return null;
	}

	public function execute($params, $context) {
		$groups = $this->freepbx->Ringgroups->listRinggroups(true);
		$result = [];
		foreach ($groups as $g) {
			$result[] = [
				'grpnum' => $g['grpnum'],
				'description' => $g['description'],
			];
		}

		return [
			'count' => count($result),
			'ringgroups' => $result,
		];
	}
}
