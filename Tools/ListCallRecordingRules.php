<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class ListCallRecordingRules extends AbstractTool {
	public function name() { return 'fm_list_recording_rules'; }
	public function description() { return 'List all call recording rules.'; }
	public function validate($params) { return true; }
	public function execute($params, $context) {
		$rules = $this->freepbx->Callrecording->getallRules();
		$result = [];
		if (!empty($rules)) {
			foreach ($rules as $r) {
				$result[] = [
					'id' => $r['callrecording_id'] ?? '',
					'description' => $r['description'] ?? '',
					'mode' => $r['callrecording_mode'] ?? '',
				];
			}
		}
		return ['count' => count($result), 'rules' => $result];
	}
}
