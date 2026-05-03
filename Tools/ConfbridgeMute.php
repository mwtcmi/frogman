<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class ConfbridgeMute extends AbstractTool {
	public function name() { return 'fm_conference_mute'; }
	public function description() { return 'Mute or unmute a conference participant. Params: room (required), channel (required), action (mute/unmute, default mute). Requires confirm:true.'; }
	public function validate($params) {
		if (empty($params['room'])) return 'Parameter "room" is required';
		if (empty($params['channel'])) return 'Parameter "channel" is required';
		return true;
	}
	public function permissionLevel() { return self::PERM_WRITE; }
	public function execute($params, $context) {
		$confirm = !empty($params['confirm']) && $params['confirm'] === true;
		$action = ($params['action'] ?? 'mute') === 'unmute' ? 'ConfbridgeUnmute' : 'ConfbridgeMute';
		$label = $action === 'ConfbridgeUnmute' ? 'Unmute' : 'Mute';
		if (!$confirm) return ['dry_run' => true, 'message' => "Would {$label} {$params['channel']} in conference {$params['room']}. Reply yes to confirm."];
		$astman = $this->freepbx->astman;
		if (!$astman || !$astman->connected()) throw new \Exception('Cannot connect to Asterisk Manager');
		$res = $astman->send_request($action, ['Conference' => $params['room'], 'Channel' => $params['channel']]);
		return ['dry_run' => false, 'message' => "{$label}d in conference {$params['room']}", 'result' => $res];
	}
}
