<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class ListQueues extends AbstractTool {
	public function name() { return 'fm_list_queues'; }
	public function description() { return 'List all call queues.'; }
	public function validate($params) { return true; }
	public function requiredPermission() { return null; }
	public function execute($params, $context) {
		$queues = $this->freepbx->Queues->listQueues(true);
		$result = [];
		if (!empty($queues)) {
			foreach ($queues as $q) {
				$result[] = ['extension' => $q[0], 'name' => $q[1] ?? ''];
			}
		}
		return ['count' => count($result), 'queues' => $result];
	}
}
