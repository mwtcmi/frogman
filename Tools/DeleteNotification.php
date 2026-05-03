<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class DeleteNotification extends AbstractTool {
	public function name() { return 'fm_delete_notification'; }
	public function description() { return 'Delete a system notification. Params: module (required), id (required). Requires confirm:true.'; }
	public function validate($params) {
		if (empty($params['module'])) return 'Parameter "module" is required';
		if (empty($params['id'])) return 'Parameter "id" is required';
		return true;
	}
	public function permissionLevel() { return self::PERM_ADMIN; }
	public function execute($params, $context) {
		$confirm = !empty($params['confirm']) && $params['confirm'] === true;
		if (!$confirm) return ['dry_run' => true, 'message' => "Would delete notification {$params['module']}/{$params['id']}. Reply yes to confirm."];
		$this->freepbx->Notifications->safe_delete($params['module'], $params['id']);
		return ['dry_run' => false, 'message' => 'Notification deleted'];
	}
}
