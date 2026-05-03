<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class GetRoutePatterns extends AbstractTool {
	public function name() { return 'fm_get_route_patterns'; }
	public function description() { return 'Show dial patterns for an outbound route. Params: id (required, route ID).'; }
	public function validate($params) {
		if (empty($params['id'])) return 'Parameter "id" is required (route ID)';
		return true;
	}
	public function execute($params, $context) {
		$id = $params['id'];
		$patterns = $this->freepbx->Core->getRoutePatternsByID($id);
		$route = $this->freepbx->Core->getRouteByID($id);
		$trunks = $this->freepbx->Core->getRouteTrunksByID($id);

		if (empty($route)) throw new \Exception("Outbound route {$id} not found");

		return [
			'route_id' => $id,
			'name' => $route['name'] ?? '',
			'patterns' => $patterns ?: [],
			'trunks' => $trunks ?: [],
		];
	}
}
