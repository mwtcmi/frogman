<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class AddBlacklist extends AbstractTool {
	public function name() { return 'fm_add_blacklist'; }
	public function description() { return 'Add a number to the blacklist. Params: number (required), description (optional). Requires confirm:true.'; }
	public function validate($params) {
		if (empty($params['number'])) return 'Parameter "number" is required';
		return true;
	}
	public function permissionLevel() { return self::PERM_WRITE; }
	public function execute($params, $context) {
		$confirm = !empty($params['confirm']) && $params['confirm'] === true;
		$number = $params['number'];
		$desc = $params['description'] ?? '';
		if (!$confirm) return ['dry_run' => true, 'message' => "Would blacklist {$number}. Reply yes to confirm."];
		$this->freepbx->Blacklist->numberAdd(['number' => $number, 'description' => $desc]);
		return ['dry_run' => false, 'message' => "Number {$number} added to blacklist.", 'needs_reload' => true];
	}
}
