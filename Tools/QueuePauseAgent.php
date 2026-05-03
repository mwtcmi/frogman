<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class QueuePauseAgent extends AbstractTool {
	public function name() { return 'fm_queue_pause_agent'; }
	public function description() { return 'Pause or unpause a queue agent. Params: queue (required), ext (required), paused (true/false, default true). Requires confirm:true.'; }
	public function validate($params) {
		if (empty($params['queue'])) return 'Parameter "queue" is required';
		if (empty($params['ext'])) return 'Parameter "ext" is required';
		return true;
	}
	public function permissionLevel() { return self::PERM_WRITE; }
	public function execute($params, $context) {
		$confirm = !empty($params['confirm']) && $params['confirm'] === true;
		$paused = ($params['paused'] ?? true) ? 'true' : 'false';
		$label = $paused === 'true' ? 'Pause' : 'Unpause';
		if (!$confirm) return ['dry_run' => true, 'message' => "Would {$label} {$params['ext']} in queue {$params['queue']}. Reply yes to confirm."];
		$astman = $this->freepbx->astman;
		if (!$astman || !$astman->connected()) throw new \Exception('Cannot connect to Asterisk Manager');
		$res = $astman->send_request('QueuePause', ['Queue' => $params['queue'], 'Interface' => "PJSIP/{$params['ext']}", 'Paused' => $paused]);
		return ['dry_run' => false, 'message' => "{$label}d {$params['ext']} in queue {$params['queue']}", 'result' => $res];
	}
}
