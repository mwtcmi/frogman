<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class ListSpeedDials extends AbstractTool {
	public function name() { return 'fm_list_speed_dials'; }
	public function description() { return 'List all speed dial entries.'; }
	public function validate($params) { return true; }
	public function execute($params, $context) {
		$dials = $this->freepbx->Contactmanager->getAllSpeedDials();
		$result = [];
		if (!empty($dials)) {
			foreach ($dials as $d) {
				$result[] = [
					'id' => $d['id'] ?? '',
					'name' => $d['name'] ?? '',
					'number' => $d['number'] ?? '',
					'code' => $d['code'] ?? '',
				];
			}
		}
		return ['count' => count($result), 'speed_dials' => $result];
	}
}
