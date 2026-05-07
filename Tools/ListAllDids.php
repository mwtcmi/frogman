<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class ListAllDids extends AbstractTool {
	public function name() { return 'fm_list_all_dids'; }
	public function description() { return 'List all DIDs including unassigned ones, with friendly destination labels (extension name, ring group, IVR, time condition, etc.) instead of raw dialplan strings.'; }
	public function validate($params) { return true; }
	public function execute($params, $context) {
		$dids = $this->freepbx->Core->getAllDIDs();
		$result = [];
		if (!empty($dids)) {
			foreach ($dids as $did) {
				$dest = $did['destination'] ?? '';
				$result[] = [
					'extension' => $did['extension'] ?? '',
					'description' => $did['description'] ?? '',
					'destination' => $dest,
					'destination_label' => $dest ? $this->describeDestination($dest)['label'] : '',
				];
			}
		}
		return ['count' => count($result), 'dids' => $result];
	}
}
