<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class GetAsteriskInfo extends AbstractTool {
	public function name() { return 'fm_get_asterisk_info'; }
	public function description() { return 'Get Asterisk system info — uptime, version, active channels, registrations.'; }
	public function validate($params) { return true; }
	public function requiredPermission() { return null; }
	public function execute($params, $context) {
		$astman = $this->freepbx->astman;
		$result = [];
		if ($astman && $astman->connected()) {
			$uptime = $astman->Command('core show uptime');
			$result['uptime'] = trim($uptime['data'] ?? '');
			$channels = $astman->Command('core show channels');
			$result['channels'] = trim($channels['data'] ?? '');
			$endpoints = $astman->Command('pjsip show endpoints');
			$data = $endpoints['data'] ?? '';
			$registered = substr_count($data, 'Avail') + substr_count($data, 'Not in use');
			$result['registered_endpoints'] = $registered;
			$result['version'] = \FreePBX::Config()->get('ASTVERSION');
		}
		return $result;
	}
}
