<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class ListQueuePriority extends AbstractTool {
	public function name() { return 'fm_list_queue_priority'; }
	public function description() { return 'List queue priority rules.'; }
	public function validate($params) { return true; }
	public function execute($params, $context) {
		$rules = $this->freepbx->Queueprio->getallqprio(); return ['count' => count($rules ?: []), 'rules' => $rules ?: []];
	}
}
