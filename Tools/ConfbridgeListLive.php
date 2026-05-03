<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class ConfbridgeListLive extends AbstractTool {
	public function name() { return 'fm_conference_participants'; }
	public function description() { return 'List participants in a live conference. Params: room (required).'; }
	public function validate($params) {
		if (empty($params['room'])) return 'Parameter "room" is required';
		return true;
	}
	public function execute($params, $context) {
		$astman = $this->freepbx->astman;
		if (!$astman || !$astman->connected()) throw new \Exception('Cannot connect to Asterisk Manager');
		$res = $astman->Command("confbridge list {$params['room']}");
		return ['room' => $params['room'], 'output' => trim($res['data'] ?? '')];
	}
}
