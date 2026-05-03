<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class GetCallWaitingAll extends AbstractTool {
	public function name() { return 'fm_list_call_waiting'; }
	public function description() { return 'List call waiting status for all extensions.'; }
	public function validate($params) { return true; }
	public function execute($params, $context) {
		$statuses = $this->freepbx->Callwaiting->getAllStatuses();
		$result = [];
		foreach ($statuses as $ext => $status) {
			$result[] = ['ext' => $ext, 'status' => $status];
		}
		return ['count' => count($result), 'extensions' => $result];
	}
}
