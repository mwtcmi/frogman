<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class SystemUpdate extends AbstractTool {
	public function name() { return 'fm_system_update'; }
	public function description() { return 'Check for and apply system updates. Requires confirm:true.'; }
	public function validate($params) { return true; }
	public function permissionLevel() { return self::PERM_ADMIN; }
	public function execute($params, $context) {
		$confirm = !empty($params['confirm']) && $params['confirm'] === true;
		if (!$confirm) return ['dry_run' => true, 'message' => 'Would check for and apply system updates.'];
		$result = $this->runAsRoot('systemupdate');
		if (!empty($result['needs_root'])) return $result;
		return ['dry_run' => false, 'message' => 'System update complete.', 'output' => $result['output']];
	}
}
