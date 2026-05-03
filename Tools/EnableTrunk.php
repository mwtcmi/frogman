<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class EnableTrunk extends AbstractTool {
	public function name() { return 'fm_enable_trunk'; }
	public function description() { return 'Enable a trunk. Params: id (trunk ID, required). Requires confirm:true.'; }
	public function validate($params) {
		if (empty($params['id'])) return 'Parameter "id" is required';
		return true;
	}
	public function permissionLevel() { return self::PERM_WRITE; }
	public function execute($params, $context) {
		$confirm = !empty($params['confirm']) && $params['confirm'] === true;
		if (!$confirm) return ['dry_run' => true, 'message' => "Would enable trunk {$params['id']}. Reply yes to confirm."];
		$this->freepbx->Core->enableTrunk($params['id']);
		return ['dry_run' => false, 'message' => "Trunk {$params['id']} enabled", 'needs_reload' => true];
	}
}
