<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class StopFreepbx extends AbstractTool {
	public function name() { return 'fm_stop'; }
	public function description() { return 'Stop FreePBX and Asterisk services. Requires confirm:true.'; }
	public function validate($params) { return true; }
	public function permissionLevel() { return self::PERM_ADMIN; }
	public function execute($params, $context) {
		$confirm = !empty($params['confirm']) && $params['confirm'] === true;
		if (!$confirm) return ['dry_run' => true, 'message' => 'Would STOP FreePBX and Asterisk. All calls will be dropped.'];
		$result = $this->runAsRoot('stop');
		if (!empty($result['needs_root'])) return $result;
		return ['dry_run' => false, 'message' => 'FreePBX stopped.', 'output' => $result['output']];
	}
}
