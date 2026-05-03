<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class RemoveBlacklist extends AbstractTool {
	public function name() { return 'fm_remove_blacklist'; }
	public function description() { return 'Remove a number from the blacklist. Params: number (required). Requires confirm:true.'; }
	public function validate($params) {
		if (empty($params['number'])) return 'Parameter "number" is required';
		return true;
	}
	public function permissionLevel() { return self::PERM_WRITE; }
	public function execute($params, $context) {
		$confirm = !empty($params['confirm']) && $params['confirm'] === true;
		$number = $params['number'];
		if (!$confirm) return ['dry_run' => true, 'message' => "Would remove {$number} from blacklist. Reply yes to confirm."];
		$this->freepbx->Blacklist->numberDel($number);
		return ['dry_run' => false, 'message' => "Number {$number} removed from blacklist.", 'needs_reload' => true];
	}
}
