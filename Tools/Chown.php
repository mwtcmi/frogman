<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class Chown extends AbstractTool {
	public function name() { return 'fm_chown'; }
	public function description() { return 'Fix file ownership/permissions. Requires confirm:true.'; }
	public function validate($params) { return true; }
	public function permissionLevel() { return self::PERM_ADMIN; }
	public function execute($params, $context) {
		$confirm = !empty($params['confirm']) && $params['confirm'] === true;
		if (!$confirm) return ['dry_run' => true, 'message' => 'Would run fwconsole chown to fix file permissions.'];
		$result = $this->runAsRoot('chown');
		if (!empty($result['needs_root'])) return $result;
		return ['dry_run' => false, 'message' => 'File permissions fixed.', 'output' => $result['output']];
	}
}
