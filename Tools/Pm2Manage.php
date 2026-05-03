<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class Pm2Manage extends AbstractTool {
	public function name() { return 'fm_pm2_manage'; }
	public function description() { return 'Manage a PM2 process. Params: action (restart/stop, required), name (process name, required). Requires confirm:true.'; }
	public function validate($params) {
		if (empty($params['action'])) return 'Parameter "action" is required (restart or stop)';
		if (!in_array($params['action'], ['restart', 'stop'])) return 'Action must be "restart" or "stop"';
		if (empty($params['name'])) return 'Parameter "name" is required';
		return true;
	}
	public function permissionLevel() { return self::PERM_ADMIN; }
	public function execute($params, $context) {
		$confirm = !empty($params['confirm']) && $params['confirm'] === true;
		$action = $params['action'];
		$name = $params['name'];
		if (!$confirm) return ['dry_run' => true, 'message' => "Would {$action} PM2 process '{$name}'. Reply yes to confirm."];
		$this->freepbx->Pm2->$action($name);
		return ['dry_run' => false, 'message' => "PM2 {$name} {$action}ed"];
	}
}
