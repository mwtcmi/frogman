<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class ListInboundRoutes extends AbstractTool {
	public function name() { return 'fm_list_inbound_routes'; }
	public function description() { return 'List all inbound routes (DIDs).'; }
	public function validate($params) { return true; }
	public function requiredPermission() { return null; }
	public function execute($params, $context) {
		$dids = $this->freepbx->Core->getAllDIDs();
		$rows = [];
		if (!empty($dids)) {
			foreach ($dids as $did) {
				$rows[] = [
					'extension' => $did['extension'] ?? '',
					'cidnum' => $did['cidnum'] ?? '',
					'destination' => $did['destination'] ?? '',
					'description' => $did['description'] ?? '',
				];
			}
		}
		return ['count' => count($rows), 'routes' => $rows];
	}
}
