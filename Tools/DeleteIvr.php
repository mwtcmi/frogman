<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class DeleteIvr extends AbstractTool {
	public function name() { return 'fm_delete_ivr'; }
	public function description() { return 'Delete an IVR. Params: id (required). Requires confirm:true.'; }
	public function validate($params) {
		if (empty($params['id'])) return 'Parameter "id" is required';
		return true;
	}
	public function permissionLevel() { return self::PERM_WRITE; }
	public function execute($params, $context) {
		$confirm = !empty($params['confirm']) && $params['confirm'] === true;
		$id = $params['id'];
		$ivr = $this->freepbx->Ivr->getDetails($id);
		if (empty($ivr)) throw new \Exception("IVR {$id} not found");
		$name = $ivr['name'] ?? $id;
		if (!$confirm) {
			return ['dry_run' => true, 'message' => "Would delete IVR \"{$name}\" (ID: {$id}). Reply yes to confirm."];
		}
		$this->freepbx->Ivr->deleteDetailsById($id);
		$this->freepbx->Ivr->deleteEntriesById($id);
		return ['dry_run' => false, 'message' => "IVR \"{$name}\" (ID: {$id}) deleted", 'needs_reload' => true];
	}
}
