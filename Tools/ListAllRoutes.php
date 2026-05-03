<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class ListAllRoutes extends AbstractTool {
	public function name() { return 'fm_list_all_routes'; }
	public function description() { return 'List all outbound routes with trunk assignments.'; }
	public function validate($params) { return true; }
	public function execute($params, $context) {
		$routes = $this->freepbx->Core->getAllRoutes(); $result = []; if(!empty($routes)) { foreach($routes as $r) { $trunks = $this->freepbx->Core->getRouteTrunksByID($r['route_id']); $result[] = ['id' => $r['route_id'], 'name' => $r['name'] ?? '', 'trunks' => count($trunks ?: [])]; } } return ['count' => count($result), 'routes' => $result];
	}
}
