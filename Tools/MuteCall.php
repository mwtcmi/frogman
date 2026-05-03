<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class MuteCall extends AbstractTool {
	public function name() { return 'fm_mute_call'; }
	public function description() { return 'Mute or unmute a channel. Params: channel (required), direction (in/out/all, default all), state (on/off, default on). Requires confirm:true.'; }
	public function validate($params) {
		if (empty($params['channel'])) return 'Parameter "channel" is required';
		return true;
	}
	public function permissionLevel() { return self::PERM_WRITE; }
	public function execute($params, $context) {
		$confirm = !empty($params['confirm']) && $params['confirm'] === true;
		$state = ($params['state'] ?? 'on') === 'on' ? 'on' : 'off';
		$dir = $params['direction'] ?? 'all';
		$label = $state === 'on' ? 'Mute' : 'Unmute';
		if (!$confirm) return ['dry_run' => true, 'message' => "Would {$label} {$params['channel']} ({$dir}). Reply yes to confirm."];
		$astman = $this->freepbx->astman;
		if (!$astman || !$astman->connected()) throw new \Exception('Cannot connect to Asterisk Manager');
		$res = $astman->send_request('MuteAudio', ['Channel' => $params['channel'], 'Direction' => $dir, 'State' => $state]);
		return ['dry_run' => false, 'message' => "{$label}d {$params['channel']}", 'result' => $res];
	}
}
