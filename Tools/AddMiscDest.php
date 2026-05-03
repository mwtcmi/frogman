<?php
namespace FreePBX\modules\Frogman\Tools;

require_once __DIR__ . '/AbstractTool.php';

class AddMiscDest extends AbstractTool {
	public function name() { return 'fm_add_misc_dest'; }
	public function description() { return 'Create a misc destination. Params: description (required), dial (required — extension, number, or dial string). Requires confirm:true.'; }
	public function validate($params) {
		if (empty($params['description'])) return 'Parameter "description" is required';
		if (empty($params['dial'])) return 'Parameter "dial" is required';
		return true;
	}
	public function requiredPermission() { return null; }

	public function permissionLevel() { return self::PERM_WRITE; }
	public function execute($params, $context) {
		$desc = $params['description'];
		$dial = $params['dial'];
		$confirm = !empty($params['confirm']) && $params['confirm'] === true;

		if (!$confirm) {
			return [
				'dry_run' => true,
				'message' => "Would create misc destination \"{$desc}\" → {$dial}. Reply yes to confirm.",
			];
		}

		$id = $this->freepbx->Miscdests->add($desc, $dial);

		return [
			'dry_run' => false,
			'message' => "Misc destination \"{$desc}\" created (ID: {$id})",
			'id' => (int) $id,
			'needs_reload' => true,
		];
	}
}
