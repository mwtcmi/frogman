<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class FirewallAddNetwork extends AbstractTool {
	public function name() { return 'fm_firewall_add_network'; }
	public function description() { return 'Add a network/IP to a firewall zone. Params: network (CIDR, required), zone (required: trusted/internal/external/other), description (optional). Requires confirm:true.'; }
	public function validate($params) {
		if (empty($params['network'])) return 'Parameter "network" is required (CIDR format)';
		if (empty($params['zone'])) return 'Parameter "zone" is required';
		return true;
	}
	public function requiredPermission() { return null; }
	public function permissionLevel() { return self::PERM_ADMIN; }
	public function execute($params, $context) {
		$confirm = !empty($params['confirm']) && $params['confirm'] === true;
		$net = $params['network'];
		$zone = $params['zone'];
		$desc = $params['description'] ?? '';
		if (!$confirm) {
			return ['dry_run' => true, 'message' => "Would add {$net} to firewall zone \"{$zone}\". Reply yes to confirm."];
		}
		$this->freepbx->Firewall->addNetworkToZone($net, $zone, $desc);
		return ['dry_run' => false, 'message' => "Added {$net} to zone \"{$zone}\"", 'needs_reload' => true];
	}
}
