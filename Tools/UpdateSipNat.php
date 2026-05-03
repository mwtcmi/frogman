<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class UpdateSipNat extends AbstractTool {
	public function name() { return 'fm_update_sip_nat'; }
	public function description() { return 'Update SIP NAT external IP or local network. Params: external_ip (optional), local_network (optional). Requires confirm:true.'; }
	public function validate($params) {
		if (empty($params['external_ip']) && empty($params['local_network'])) {
			return 'At least one of external_ip or local_network is required';
		}
		return true;
	}
	public function requiredPermission() { return null; }
	public function permissionLevel() { return self::PERM_ADMIN; }
	public function execute($params, $context) {
		$confirm = !empty($params['confirm']) && $params['confirm'] === true;
		$changes = [];
		if (!empty($params['external_ip'])) $changes[] = "External IP → {$params['external_ip']}";
		if (!empty($params['local_network'])) $changes[] = "Local network → {$params['local_network']}";
		if (!$confirm) {
			return ['dry_run' => true, 'message' => "Would update SIP NAT: " . implode(', ', $changes) . ". Reply yes to confirm."];
		}
		if (!empty($params['external_ip'])) {
			$this->freepbx->Sipsettings->setConfig('externip', $params['external_ip']);
		}
		if (!empty($params['local_network'])) {
			$this->freepbx->Sipsettings->setConfig('localnetworks', $params['local_network']);
		}
		return ['dry_run' => false, 'message' => "SIP NAT settings updated: " . implode(', ', $changes), 'needs_reload' => true];
	}
}
