<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class DeleteTimecondition extends AbstractTool {
	public function name() { return 'fm_delete_time_condition'; }
	public function description() { return 'Delete a time condition. Params: id (required). Requires confirm:true.'; }
	public function validate($params) {
		if (empty($params['id'])) return 'Parameter "id" is required';
		return true;
	}
	public function permissionLevel() { return self::PERM_WRITE; }
	public function execute($params, $context) {
		$confirm = !empty($params['confirm']) && $params['confirm'] === true;
		$id = $params['id'];
		$tc = $this->freepbx->Timeconditions->getTimeCondition($id);
		if (empty($tc)) throw new \Exception("Time condition {$id} not found");
		$name = $tc['displayname'] ?? $id;
		if (!$confirm) {
			return ['dry_run' => true, 'message' => "Would delete time condition \"{$name}\" (ID: {$id}). Reply yes to confirm."];
		}
		$db = $this->freepbx->Database;
		$db->prepare("DELETE FROM timeconditions WHERE timeconditions_id = ?")->execute([$id]);
		// Clean up feature code
		$fcc = new \featurecode('timeconditions', 'toggle-mode-' . $id);
		$fcc->delete();
		return ['dry_run' => false, 'message' => "Time condition \"{$name}\" (ID: {$id}) deleted", 'needs_reload' => true];
	}
}
