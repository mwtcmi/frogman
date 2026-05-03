<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class ToggleDnd extends AbstractTool {
	public function name() { return 'fm_toggle_dnd'; }
	public function description() { return 'Toggle Do Not Disturb for an extension. Params: ext (required), state (optional: on/off). Requires confirm:true.'; }
	public function validate($params) {
		if (empty($params['ext'])) return 'Parameter "ext" is required';
		return true;
	}
	public function requiredPermission() { return null; }
	public function permissionLevel() { return self::PERM_WRITE; }
	public function execute($params, $context) {
		$ext = $params['ext'];
		$confirm = !empty($params['confirm']) && $params['confirm'] === true;
		$current = $this->freepbx->Donotdisturb->getStatusByExtension($ext);
		$currentOn = !empty($current);
		$newState = isset($params['state']) ? ($params['state'] === 'on' ? 'YES' : '') : ($currentOn ? '' : 'YES');
		$newLabel = ($newState === 'YES') ? 'enabled' : 'disabled';
		if (!$confirm) {
			return ['dry_run' => true, 'message' => "Would set DND on {$ext} to {$newLabel}. Reply yes to confirm."];
		}
		$this->freepbx->Donotdisturb->setStatusByExtension($ext, $newState);
		return ['dry_run' => false, 'message' => "DND on {$ext} is now {$newLabel}.", 'needs_reload' => true];
	}
}
