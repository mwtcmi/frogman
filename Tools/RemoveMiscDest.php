<?php
namespace FreePBX\modules\Frogman\Tools;

require_once __DIR__ . '/AbstractTool.php';

class RemoveMiscDest extends AbstractTool {
	public function name() { return 'fm_remove_misc_dest'; }
	public function description() { return 'Remove a misc destination by ID. Params: id (required). Requires confirm:true.'; }
	public function validate($params) {
		if (empty($params['id'])) return 'Parameter "id" is required';
		return true;
	}
	public function requiredPermission() { return null; }

	public function permissionLevel() { return self::PERM_WRITE; }
	public function execute($params, $context) {
		$id = $params['id'];
		$confirm = !empty($params['confirm']) && $params['confirm'] === true;

		$existing = $this->freepbx->Miscdests->get($id);
		if (empty($existing)) {
			throw new \Exception("Misc destination ID {$id} not found");
		}

		$desc = $existing[0]['description'];
		$dial = $existing[0]['destdial'];

		if (!$confirm) {
			return [
				'dry_run' => true,
				'message' => "Would remove misc destination \"{$desc}\" (ID: {$id}, dial: {$dial}). Reply yes to confirm.",
			];
		}

		$this->freepbx->Miscdests->del($id);

		return [
			'dry_run' => false,
			'message' => "Misc destination \"{$desc}\" (ID: {$id}) removed.",
			'needs_reload' => true,
		];
	}
}
