<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class GetFirewallStatus extends AbstractTool {
	public function name() { return 'fm_get_firewall_status'; }
	public function description() { return 'Get firewall status, intrusion detection, and network zones.'; }
	public function validate($params) { return true; }
	public function requiredPermission() { return null; }
	public function execute($params, $context) {
		$fw = $this->freepbx->Firewall;
		$ids = $fw->intrusion_detection_status();
		$networks = $fw->get_networkmaps();
		$zones = [];
		if (!empty($networks)) {
			foreach ($networks as $net => $zone) {
				$zones[] = ['network' => $net, 'zone' => $zone];
			}
		}
		return [
			'intrusion_detection' => $ids,
			'network_zones' => $zones,
			'zone_count' => count($zones),
		];
	}
}
