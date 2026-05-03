<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class GetExtensionCodecs extends AbstractTool {
	public function name() { return 'fm_get_extension_codecs'; }
	public function description() { return 'Get configured codecs for an extension. Params: ext (required).'; }
	public function validate($params) { if (empty($params['ext'])) return 'Parameter "ext" is required';
		return true; }
	public function execute($params, $context) {
		$device = $this->freepbx->Core->getDevice($params['ext']); if(empty($device)) throw new \Exception('Extension ' . $params['ext'] . ' not found'); return ['extension' => $params['ext'], 'allow' => $device['allow'] ?? '', 'disallow' => $device['disallow'] ?? ''];
	}
}
