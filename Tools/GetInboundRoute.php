<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class GetInboundRoute extends AbstractTool {
	public function name() { return 'fm_get_inbound_route'; }
	public function description() { return 'Get details for a specific inbound route. Params: extension (DID number, required), cidnum (optional, default empty).'; }
	public function validate($params) {
		if (empty($params['extension'])) return 'Parameter "extension" is required (the DID number)';
		return true;
	}
	public function requiredPermission() { return null; }
	public function execute($params, $context) {
		$ext = $params['extension'];
		$cid = $params['cidnum'] ?? '';
		$route = $this->freepbx->Core->getDID($ext, $cid);
		if (empty($route)) throw new \Exception("Inbound route for {$ext} not found");
		return $route;
	}
}
