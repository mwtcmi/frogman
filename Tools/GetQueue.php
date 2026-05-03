<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class GetQueue extends AbstractTool {
	public function name() { return 'fm_get_queue'; }
	public function description() { return 'Get details for a queue. Params: id (required).'; }
	public function validate($params) {
		if (empty($params['id'])) return 'Parameter "id" is required';
		return true;
	}
	public function requiredPermission() { return null; }
	public function execute($params, $context) {
		$details = $this->freepbx->Queues->getQueuesDetails($params['id']);
		if (empty($details)) throw new \Exception("Queue {$params['id']} not found");
		$dynmembers = $this->freepbx->Queues->getDynMembersOfQueue($params['id']);
		$details['dynamic_members'] = $dynmembers;
		return $details;
	}
}
