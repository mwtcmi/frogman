<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class AddAllowlist extends AbstractTool {
	public function name() { return 'fm_add_allowlist'; }
	public function description() { return 'Add a number to the allowlist. Params: number (required), description (optional). Requires confirm:true.'; }
	public function validate($params) {
		if (empty($params['number'])) return 'Parameter "number" is required';
		return true;
	}
	public function permissionLevel() { return self::PERM_WRITE; }
	public function execute($params, $context) {
		$number = $params['number'];
		$desc = $params['description'] ?? '';
		$confirm = !empty($params['confirm']) && $params['confirm'] === true;
		if (!$confirm) return ['dry_run' => true, 'message' => "Would add {$number} to the allowlist."];
		$this->freepbx->Allowlist->numberAdd($number, $desc);
		return ['dry_run' => false, 'message' => "Number {$number} added to allowlist.", 'needs_reload' => true];
	}
}
