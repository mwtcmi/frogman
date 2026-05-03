<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class ListDaynight extends AbstractTool {
	public function name() { return 'fm_list_daynight'; }
	public function description() { return 'List all day/night call flow controls with current state.'; }
	public function validate($params) { return true; }
	public function requiredPermission() { return null; }
	public function execute($params, $context) {
		$flows = $this->freepbx->Daynight->listCallFlows();
		$result = [];
		if (!empty($flows)) {
			foreach ($flows as $f) {
				$state = $this->freepbx->Daynight->getState($f['ext']);
				$result[] = ['id' => $f['ext'], 'description' => $f['dest'] ?? '', 'state' => $state];
			}
		}
		return ['count' => count($result), 'call_flows' => $result];
	}
}
