<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class GetOutboundRoute extends AbstractTool {
	public function name() { return 'fm_get_outbound_route'; }
	public function description() { return 'Get details for an outbound route. Params: id (required).'; }
	public function validate($params) {
		if (empty($params['id'])) return 'Parameter "id" is required';
		return true;
	}
	public function requiredPermission() { return null; }
	public function execute($params, $context) {
		$route = $this->freepbx->Core->getRoute($params['id']);
		if (empty($route)) throw new \Exception("Outbound route {$params['id']} not found");
		$trunks = $this->freepbx->Core->getRouteTrunksByID($params['id']);
		$patterns = $this->freepbx->Core->getRoutePatternsByID($params['id']);
		$route['trunks'] = $trunks;
		$route['patterns'] = $patterns;
		return $route;
	}
}
