<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class SyncUserman extends AbstractTool {
	public function name() { return 'fm_sync_userman'; }
	public function description() { return 'Sync User Manager with external directory. Requires confirm:true.'; }
	public function validate($params) { return true; }
	public function permissionLevel() { return self::PERM_ADMIN; }
	public function execute($params, $context) {
		$confirm = !empty($params['confirm']) && $params['confirm'] === true;
		if (!$confirm) return ['dry_run' => true, 'message' => 'Would sync User Manager with external directories.'];
		$result = $this->runAsRoot('userman sync --verbose');
		if (!empty($result['needs_root'])) return $result;
		return ['dry_run' => false, 'message' => 'User Manager sync complete.', 'output' => $result['output']];
	}
}
