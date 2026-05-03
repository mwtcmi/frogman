<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class DeleteRinggroup extends AbstractTool {
	public function name() { return 'fm_delete_ringgroup'; }
	public function description() { return 'Delete a ring group. Params: id (ring group number, required). Requires confirm:true.'; }
	public function validate($params) {
		if (empty($params['id'])) return 'Parameter "id" is required';
		return true;
	}
	public function requiredPermission() { return null; }
	public function permissionLevel() { return self::PERM_WRITE; }
	public function execute($params, $context) {
		$confirm = !empty($params['confirm']) && $params['confirm'] === true;
		$grpnum = $params['id'];
		$group = $this->freepbx->Ringgroups->get($grpnum);
		if (empty($group)) throw new \Exception("Ring group {$grpnum} not found");
		if (!$confirm) {
			return ['dry_run' => true, 'message' => "Would delete ring group {$grpnum} ({$group['description']}). Reply yes to confirm."];
		}
		$this->freepbx->Ringgroups->delete($grpnum);
		return ['dry_run' => false, 'message' => "Ring group {$grpnum} deleted", 'needs_reload' => true];
	}
}
