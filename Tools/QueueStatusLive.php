<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class QueueStatusLive extends AbstractTool {
	public function name() { return 'fm_queue_status'; }
	public function description() { return 'Get real-time queue status via AMI — callers waiting, agents logged in, stats. Params: queue (optional, all queues if omitted).'; }
	public function validate($params) { return true; }
	public function execute($params, $context) {
		$astman = $this->freepbx->astman;
		if (!$astman || !$astman->connected()) throw new \Exception('Cannot connect to Asterisk Manager');
		$queue = $params['queue'] ?? '';
		$req = ['ActionID' => 'frogman-qs-' . time()];
		if ($queue) $req['Queue'] = $queue;
		$res = $astman->send_request('QueueSummary', $req);
		return ['queue' => $queue ?: 'all', 'result' => $res];
	}
}
