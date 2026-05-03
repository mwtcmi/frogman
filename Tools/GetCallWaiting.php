<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class GetCallWaiting extends AbstractTool {
	public function name() { return 'fm_get_call_waiting'; }
	public function description() { return 'Get call waiting status for an extension. Params: ext (required).'; }
	public function validate($params) {
		if (empty($params['ext'])) return 'Parameter "ext" is required';
		return true;
	}
	public function execute($params, $context) {
		$status = $this->freepbx->Callwaiting->getStatusByExtension($params['ext']);
		return ['extension' => $params['ext'], 'call_waiting' => $status];
	}
}
