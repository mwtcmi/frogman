<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class StartFreepbx extends AbstractTool {
	public function name() { return 'fm_start'; }
	public function description() { return 'Start FreePBX and Asterisk services. Requires confirm:true.'; }
	public function validate($params) { return true; }
	public function permissionLevel() { return self::PERM_ADMIN; }
	public function execute($params, $context) {
		$confirm = !empty($params['confirm']) && $params['confirm'] === true;
		if (!$confirm) return ['dry_run' => true, 'message' => 'Would start FreePBX and Asterisk.'];
		$result = $this->runAsRoot('start');
		if (!empty($result['needs_root'])) return $result;
		return ['dry_run' => false, 'message' => 'FreePBX started.', 'output' => $result['output']];
	}
}
