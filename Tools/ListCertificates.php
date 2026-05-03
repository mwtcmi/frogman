<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class ListCertificates extends AbstractTool {
	public function name() { return 'fm_list_certificates'; }
	public function description() { return 'List all SSL/TLS certificates managed by Certificate Manager.'; }
	public function validate($params) { return true; }
	public function requiredPermission() { return null; }
	public function execute($params, $context) {
		$cas = $this->freepbx->Certman->getAllManagedCAs() ?: [];
		$certs = $this->freepbx->Certman->getAllManagedCertificates() ?: [];

		$typeLabels = ['ss' => 'Self-Signed', 'le' => "Let's Encrypt", 'up' => 'Uploaded', 'csr' => 'CSR'];
		$result = [];
		foreach ($certs as $cert) {
			$type = $typeLabels[$cert['type'] ?? ''] ?? ($cert['type'] ?? 'Unknown');
			$isDefault = !empty($cert['default']) ? ' [default]' : '';
			// Find the CA that signed this cert
			$caName = '';
			foreach ($cas as $ca) {
				if (($ca['uid'] ?? '') == ($cert['caid'] ?? '')) {
					$caName = $ca['cn'] ?? '';
					break;
				}
			}
			$result[] = [
				'name' => $cert['basename'] ?? '',
				'description' => $cert['description'] ?? '',
				'type' => $type,
				'default' => $isDefault,
				'ca' => $caName,
			];
		}

		return [
			'count' => count($result),
			'certificates' => $result,
			'ca_count' => count($cas),
		];
	}
}
