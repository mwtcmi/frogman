<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class AddTimegroup extends AbstractTool {
	public function name() { return 'fm_add_timegroup'; }
	public function description() { return 'Create a time group (schedule). Params: name (required), times (optional array of time strings like "09:00-17:00|mon-fri|*|*"). Requires confirm:true.'; }
	public function validate($params) {
		if (empty($params['name'])) return 'Parameter "name" is required';
		return true;
	}
	public function permissionLevel() { return self::PERM_WRITE; }
	public function execute($params, $context) {
		$confirm = !empty($params['confirm']) && $params['confirm'] === true;
		$name = $params['name'];
		$times = $params['times'] ?? null;
		if (!$confirm) {
			$msg = "Would create time group \"{$name}\"";
			if ($times) $msg .= " with " . count($times) . " time entries";
			return ['dry_run' => true, 'message' => $msg . ". Reply yes to confirm."];
		}
		$id = $this->freepbx->Timeconditions->addTimeGroup($name, $times);
		if ($id === false) throw new \Exception("Failed to create time group — name may already exist");
		return ['dry_run' => false, 'message' => "Time group \"{$name}\" created (ID: {$id})", 'id' => $id, 'needs_reload' => true];
	}
}
