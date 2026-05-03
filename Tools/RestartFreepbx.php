<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class RestartFreepbx extends AbstractTool {
	public function name() { return 'fm_restart'; }
	public function description() { return 'Restart FreePBX and Asterisk services. Requires confirm:true.'; }
	public function validate($params) { return true; }
	public function permissionLevel() { return self::PERM_ADMIN; }
	public function execute($params, $context) {
		$confirm = !empty($params['confirm']) && $params['confirm'] === true;
		if (!$confirm) return ['dry_run' => true, 'message' => 'Would restart FreePBX and Asterisk. Active calls will be dropped.'];
		$result = $this->runAsRoot('restart');
		if (!empty($result['needs_root'])) return $result;
		return ['dry_run' => false, 'message' => 'FreePBX restarted.', 'output' => $result['output']];
	}
}
