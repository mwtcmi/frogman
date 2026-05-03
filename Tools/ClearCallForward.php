<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class ClearCallForward extends AbstractTool {
	public function name() { return 'fm_clear_call_forward'; }
	public function description() { return 'Clear call forwarding for an extension. Params: ext (required). Requires confirm:true.'; }
	public function validate($params) {
		if (empty($params['ext'])) return 'Parameter "ext" is required';
		return true;
	}
	public function requiredPermission() { return null; }
	public function permissionLevel() { return self::PERM_WRITE; }
	public function execute($params, $context) {
		$ext = $params['ext'];
		$confirm = !empty($params['confirm']) && $params['confirm'] === true;
		if (!$confirm) {
			return ['dry_run' => true, 'message' => "Would clear all call forwarding on {$ext}. Reply yes to confirm."];
		}
		$this->freepbx->Callforward->delAllNumbersByExtension($ext);
		return ['dry_run' => false, 'message' => "Call forwarding cleared on extension {$ext}.", 'needs_reload' => true];
	}
}
