<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class ListTimeconditions extends AbstractTool {
	public function name() { return 'fm_list_time_conditions'; }
	public function description() { return 'List all time conditions with their current state.'; }
	public function validate($params) { return true; }
	public function requiredPermission() { return null; }
	public function execute($params, $context) {
		$tcs = $this->freepbx->Timeconditions->listTimeconditions(true);
		$result = [];
		if (!empty($tcs)) {
			foreach ($tcs as $tc) {
				$state = $this->freepbx->Timeconditions->getState($tc['timeconditions_id']);
				$result[] = [
					'id' => $tc['timeconditions_id'],
					'name' => $tc['displayname'],
					'state' => $state,
				];
			}
		}
		return ['count' => count($result), 'time_conditions' => $result];
	}
}
