<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class ToggleDaynight extends AbstractTool {
	public function name() { return 'fm_toggle_daynight'; }
	public function description() { return 'Toggle a day/night call flow. Params: id (required), state (optional: day/night). Requires confirm:true.'; }
	public function validate($params) {
		if (empty($params['id'])) return 'Parameter "id" is required';
		return true;
	}
	public function requiredPermission() { return null; }
	public function permissionLevel() { return self::PERM_WRITE; }
	public function execute($params, $context) {
		$id = $params['id'];
		$confirm = !empty($params['confirm']) && $params['confirm'] === true;
		$current = $this->freepbx->Daynight->getState($id);
		$newState = isset($params['state']) ? ($params['state'] === 'night' ? 'NIGHT' : 'DAY') : ($current === 'DAY' ? 'NIGHT' : 'DAY');
		if (!$confirm) {
			return ['dry_run' => true, 'message' => "Would set call flow {$id} to {$newState} (currently {$current}). Reply yes to confirm."];
		}
		$this->freepbx->Daynight->setState($id, $newState);
		return ['dry_run' => false, 'message' => "Call flow {$id} set to {$newState}.", 'needs_reload' => true];
	}
}
