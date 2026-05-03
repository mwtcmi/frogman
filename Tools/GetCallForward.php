<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class GetCallForward extends AbstractTool {
	public function name() { return 'fm_get_call_forward'; }
	public function description() { return 'Get call forwarding status for an extension. Params: ext (required).'; }
	public function validate($params) {
		if (empty($params['ext'])) return 'Parameter "ext" is required';
		return true;
	}
	public function requiredPermission() { return null; }
	public function execute($params, $context) {
		$ext = $params['ext'];
		$cf = $this->freepbx->Callforward->getNumberByExtension($ext, 'CF');
		$cfb = $this->freepbx->Callforward->getNumberByExtension($ext, 'CFB');
		$cfu = $this->freepbx->Callforward->getNumberByExtension($ext, 'CFU');
		return [
			'extension' => $ext,
			'call_forward' => $cf ?: null,
			'call_forward_busy' => $cfb ?: null,
			'call_forward_unavailable' => $cfu ?: null,
		];
	}
}
