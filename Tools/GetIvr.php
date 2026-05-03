<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class GetIvr extends AbstractTool {
	public function name() { return 'fm_get_ivr'; }
	public function description() { return 'Get IVR details. Params: id (required).'; }
	public function validate($params) {
		if (empty($params['id'])) return 'Parameter "id" is required';
		return true;
	}
	public function requiredPermission() { return null; }
	public function execute($params, $context) {
		$ivr = $this->freepbx->Ivr->getDetails($params['id']);
		if (empty($ivr)) throw new \Exception("IVR {$params['id']} not found");
		return $ivr;
	}
}
