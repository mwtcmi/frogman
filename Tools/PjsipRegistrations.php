<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class PjsipRegistrations extends AbstractTool {
	public function name() { return 'fm_pjsip_registrations'; }
	public function description() { return 'List all PJSIP registrations — inbound (phones) and outbound (trunks).'; }
	public function validate($params) { return true; }
	public function execute($params, $context) {
		$astman = $this->freepbx->astman;
		if (!$astman || !$astman->connected()) throw new \Exception('Cannot connect to Asterisk Manager');
		$inbound = $astman->Command('pjsip show contacts');
		$outbound = $astman->Command('pjsip show registrations');
		return [
			'inbound' => trim($inbound['data'] ?? ''),
			'outbound' => trim($outbound['data'] ?? ''),
		];
	}
}
