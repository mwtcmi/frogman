<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class GetQueueDetails extends AbstractTool {
	public function name() { return 'fm_get_queue_details'; }
	public function description() { return 'Get detailed queue configuration including dynamic members. Params: id (required, queue extension).'; }
	public function validate($params) {
		if (empty($params['id'])) return 'Parameter "id" is required';
		return true;
	}
	public function execute($params, $context) {
		$id = $params['id'];
		$details = $this->freepbx->Queues->getQueuesDetails($id);
		if (empty($details)) throw new \Exception("Queue {$id} not found");
		$dynMembers = $this->freepbx->Queues->getDynMembersOfQueue($id);
		return [
			'queue' => $details,
			'dynamic_members' => $dynMembers ?: [],
		];
	}
}
