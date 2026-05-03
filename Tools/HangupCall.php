<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class HangupCall extends AbstractTool {
	public function name() { return 'fm_hangup_call'; }
	public function description() { return 'Hang up a specific channel. Params: channel (required, from active calls list). Requires confirm:true.'; }
	public function validate($params) {
		if (empty($params['channel'])) return 'Parameter "channel" is required';
		return true;
	}
	public function permissionLevel() { return self::PERM_WRITE; }
	public function execute($params, $context) {
		$confirm = !empty($params['confirm']) && $params['confirm'] === true;
		if (!$confirm) return ['dry_run' => true, 'message' => "Would hang up channel {$params['channel']}. Reply yes to confirm."];
		$astman = $this->freepbx->astman;
		if (!$astman || !$astman->connected()) throw new \Exception('Cannot connect to Asterisk Manager');
		$res = $astman->Hangup($params['channel']);
		return ['dry_run' => false, 'message' => "Channel {$params['channel']} hung up", 'result' => $res];
	}
}
