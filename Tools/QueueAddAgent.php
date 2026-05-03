<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class QueueAddAgent extends AbstractTool {
	public function name() { return 'fm_queue_add_agent'; }
	public function description() { return 'Add an agent to a queue dynamically (live, via AMI). Params: queue (required), ext (required). Requires confirm:true.'; }
	public function validate($params) {
		if (empty($params['queue'])) return 'Parameter "queue" is required';
		if (empty($params['ext'])) return 'Parameter "ext" is required';
		return true;
	}
	public function permissionLevel() { return self::PERM_WRITE; }
	public function execute($params, $context) {
		$confirm = !empty($params['confirm']) && $params['confirm'] === true;
		if (!$confirm) return ['dry_run' => true, 'message' => "Would add {$params['ext']} to queue {$params['queue']}. Reply yes to confirm."];
		$astman = $this->freepbx->astman;
		if (!$astman || !$astman->connected()) throw new \Exception('Cannot connect to Asterisk Manager');
		$res = $astman->send_request('QueueAdd', ['Queue' => $params['queue'], 'Interface' => "PJSIP/{$params['ext']}", 'MemberName' => $params['ext']]);
		return ['dry_run' => false, 'message' => "Added {$params['ext']} to queue {$params['queue']}", 'result' => $res];
	}
}
