<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class LoggerRotate extends AbstractTool {
	public function name() { return 'fm_rotate_logs'; }
	public function description() { return 'Rotate Asterisk log files. Requires confirm:true.'; }
	public function validate($params) { return true; }
	public function permissionLevel() { return self::PERM_ADMIN; }
	public function execute($params, $context) {
		$confirm = !empty($params['confirm']) && $params['confirm'] === true;
		if (!$confirm) return ['dry_run' => true, 'message' => 'Would rotate Asterisk log files. Reply yes to confirm.'];
		$astman = $this->freepbx->astman;
		if (!$astman || !$astman->connected()) throw new \Exception('Cannot connect to Asterisk Manager');
		$res = $astman->send_request('LoggerRotate');
		return ['dry_run' => false, 'message' => 'Logs rotated', 'result' => $res];
	}
}
