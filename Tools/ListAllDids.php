<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class ListAllDids extends AbstractTool {
	public function name() { return 'fm_list_all_dids'; }
	public function description() { return 'List all DIDs including unassigned ones.'; }
	public function validate($params) { return true; }
	public function execute($params, $context) {
		$dids = $this->freepbx->Core->getAllDIDs();
		$result = [];
		if (!empty($dids)) {
			foreach ($dids as $did) {
				$result[] = [
					'extension' => $did['extension'] ?? '',
					'description' => $did['description'] ?? '',
					'destination' => $did['destination'] ?? '',
				];
			}
		}
		return ['count' => count($result), 'dids' => $result];
	}
}
