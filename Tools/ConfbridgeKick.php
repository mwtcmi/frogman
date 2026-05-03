<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class ConfbridgeKick extends AbstractTool {
	public function name() { return 'fm_conference_kick'; }
	public function description() { return 'Kick a participant from a conference. Params: room (required), channel (required). Requires confirm:true.'; }
	public function validate($params) {
		if (empty($params['room'])) return 'Parameter "room" is required';
		if (empty($params['channel'])) return 'Parameter "channel" is required';
		return true;
	}
	public function permissionLevel() { return self::PERM_WRITE; }
	public function execute($params, $context) {
		$confirm = !empty($params['confirm']) && $params['confirm'] === true;
		if (!$confirm) return ['dry_run' => true, 'message' => "Would kick {$params['channel']} from conference {$params['room']}. Reply yes to confirm."];
		$astman = $this->freepbx->astman;
		if (!$astman || !$astman->connected()) throw new \Exception('Cannot connect to Asterisk Manager');
		$res = $astman->send_request('ConfbridgeKick', ['Conference' => $params['room'], 'Channel' => $params['channel']]);
		return ['dry_run' => false, 'message' => "Kicked from conference {$params['room']}", 'result' => $res];
	}
}
