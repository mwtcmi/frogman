<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class ListOutboundRoutes extends AbstractTool {
	public function name() { return 'fm_list_outbound_routes'; }
	public function description() { return 'List all outbound routes.'; }
	public function validate($params) { return true; }
	public function requiredPermission() { return null; }
	public function execute($params, $context) {
		$routes = $this->freepbx->Core->getAllRoutes();
		return ['count' => count($routes), 'routes' => $routes];
	}
}
