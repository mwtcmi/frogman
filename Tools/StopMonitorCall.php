<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class StopMonitorCall extends AbstractTool {
	public function name() { return 'fm_stop_monitor_call'; }
	public function description() { return 'Stop recording a live call. Params: channel (required). Requires confirm:true.'; }
	public function validate($params) {
		if (empty($params['channel'])) return 'Parameter "channel" is required';
		return true;
	}
	public function permissionLevel() { return self::PERM_WRITE; }
	public function execute($params, $context) {
		$confirm = !empty($params['confirm']) && $params['confirm'] === true;
		if (!$confirm) return ['dry_run' => true, 'message' => "Would stop recording on {$params['channel']}. Reply yes to confirm."];
		$astman = $this->freepbx->astman;
		if (!$astman || !$astman->connected()) throw new \Exception('Cannot connect to Asterisk Manager');
		$res = $astman->send_request('StopMixMonitor', ['Channel' => $params['channel']]);
		return ['dry_run' => false, 'message' => "Recording stopped on {$params['channel']}", 'result' => $res];
	}
}
