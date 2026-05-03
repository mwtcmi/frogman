<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class GetConference extends AbstractTool {
	public function name() { return 'fm_get_conference'; }
	public function description() { return 'Get conference room details. Params: id (required).'; }
	public function validate($params) {
		if (empty($params['id'])) return 'Parameter "id" is required';
		return true;
	}
	public function requiredPermission() { return null; }
	public function execute($params, $context) {
		$conf = $this->freepbx->Conferences->getConference($params['id']);
		if (empty($conf)) throw new \Exception("Conference {$params['id']} not found");
		return $conf;
	}
}
