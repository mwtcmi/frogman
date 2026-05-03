<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class QueueRemoveAgent extends AbstractTool {
	public function name() { return 'fm_queue_remove_agent'; }
	public function description() { return 'Remove an agent from a queue (live, via AMI). Params: queue (required), ext (required). Requires confirm:true.'; }
	public function validate($params) {
		if (empty($params['queue'])) return 'Parameter "queue" is required';
		if (empty($params['ext'])) return 'Parameter "ext" is required';
		return true;
	}
	public function permissionLevel() { return self::PERM_WRITE; }
	public function execute($params, $context) {
		$confirm = !empty($params['confirm']) && $params['confirm'] === true;
		if (!$confirm) return ['dry_run' => true, 'message' => "Would remove {$params['ext']} from queue {$params['queue']}. Reply yes to confirm."];
		$astman = $this->freepbx->astman;
		if (!$astman || !$astman->connected()) throw new \Exception('Cannot connect to Asterisk Manager');
		$res = $astman->send_request('QueueRemove', ['Queue' => $params['queue'], 'Interface' => "PJSIP/{$params['ext']}"]);
		return ['dry_run' => false, 'message' => "Removed {$params['ext']} from queue {$params['queue']}", 'result' => $res];
	}
}
