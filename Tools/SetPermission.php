<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class SetPermission extends AbstractTool {
	public function name() { return 'fm_set_permission'; }
	public function description() { return 'Set Frogman permission level for a user. Params: username (required), level (required: read/write/admin). Requires confirm:true.'; }
	public function validate($params) {
		if (empty($params['username'])) return 'Parameter "username" is required';
		if (empty($params['level'])) return 'Parameter "level" is required';
		if (!in_array($params['level'], ['read', 'write', 'admin'])) {
			return 'Parameter "level" must be: read, write, or admin';
		}
		return true;
	}
	public function requiredPermission() { return null; }
	public function permissionLevel() { return self::PERM_ADMIN; }
	public function execute($params, $context) {
		$confirm = !empty($params['confirm']) && $params['confirm'] === true;
		$username = $params['username'];
		$level = $params['level'];
		if (!$confirm) {
			return ['dry_run' => true, 'message' => "Would set {$username} to '{$level}' permission level. Reply yes to confirm."];
		}
		$db = $this->freepbx->Database;
		$sth = $db->prepare("REPLACE INTO oc_permissions (username, level) VALUES (?, ?)");
		$sth->execute([$username, $level]);
		return ['dry_run' => false, 'message' => "User {$username} set to '{$level}' permission level."];
	}
}
