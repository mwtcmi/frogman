<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class PjsipQualify extends AbstractTool {
	public function name() { return 'fm_pjsip_qualify'; }
	public function description() { return 'Ping/qualify a PJSIP endpoint to check if it is reachable. Params: ext (required).'; }
	public function validate($params) {
		if (empty($params['ext'])) return 'Parameter "ext" is required';
		return true;
	}
	public function execute($params, $context) {
		$astman = $this->freepbx->astman;
		if (!$astman || !$astman->connected()) throw new \Exception('Cannot connect to Asterisk Manager');
		$res = $astman->send_request('PJSIPQualify', ['Endpoint' => $params['ext']]);
		return ['endpoint' => $params['ext'], 'result' => $res];
	}
}
