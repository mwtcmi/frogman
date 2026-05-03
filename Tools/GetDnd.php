<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class GetDnd extends AbstractTool {
	public function name() { return 'fm_get_dnd'; }
	public function description() { return 'Get Do Not Disturb status for an extension. Params: ext (required).'; }
	public function validate($params) {
		if (empty($params['ext'])) return 'Parameter "ext" is required';
		return true;
	}
	public function requiredPermission() { return null; }
	public function execute($params, $context) {
		$status = $this->freepbx->Donotdisturb->getStatusByExtension($params['ext']);
		return ['extension' => $params['ext'], 'dnd' => !empty($status) ? 'enabled' : 'disabled'];
	}
}
