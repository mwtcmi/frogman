<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class RemoveAllowlist extends AbstractTool {
	public function name() { return 'fm_remove_allowlist'; }
	public function description() { return 'Remove a number from the allowlist. Params: number (required). Requires confirm:true.'; }
	public function validate($params) {
		if (empty($params['number'])) return 'Parameter "number" is required';
		return true;
	}
	public function permissionLevel() { return self::PERM_WRITE; }
	public function execute($params, $context) {
		$number = $params['number'];
		$confirm = !empty($params['confirm']) && $params['confirm'] === true;
		if (!$confirm) return ['dry_run' => true, 'message' => "Would remove {$number} from the allowlist."];
		$this->freepbx->Allowlist->numberDel($number);
		return ['dry_run' => false, 'message' => "Number {$number} removed from allowlist.", 'needs_reload' => true];
	}
}
