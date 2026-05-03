<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class UpdateCertificates extends AbstractTool {
	public function name() { return 'fm_update_certificates'; }
	public function description() { return 'Update/renew all SSL certificates. Requires confirm:true.'; }
	public function validate($params) { return true; }
	public function permissionLevel() { return self::PERM_ADMIN; }
	public function execute($params, $context) {
		$confirm = !empty($params['confirm']) && $params['confirm'] === true;
		if (!$confirm) return ['dry_run' => true, 'message' => 'Would update/renew all SSL certificates.'];
		$result = $this->runAsRoot('certificates --updateall');
		if (!empty($result['needs_root'])) return $result;
		return ['dry_run' => false, 'message' => 'Certificates updated.', 'output' => $result['output']];
	}
}
