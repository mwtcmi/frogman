<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class ListTimegroups extends AbstractTool {
	public function name() { return 'fm_list_timegroups'; }
	public function description() { return 'List all time groups (schedules).'; }
	public function validate($params) { return true; }
	public function execute($params, $context) {
		$groups = $this->freepbx->Timeconditions->listTimegroups(true);
		$result = [];
		if (!empty($groups)) {
			foreach ($groups as $g) {
				$result[] = ['id' => $g['id'], 'description' => $g['description']];
			}
		}
		return ['count' => count($result), 'timegroups' => $result];
	}
}
