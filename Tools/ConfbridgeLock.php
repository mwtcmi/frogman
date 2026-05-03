<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class ConfbridgeLock extends AbstractTool {
	public function name() { return 'fm_conference_lock'; }
	public function description() { return 'Lock or unlock a conference room. Params: room (required), action (lock/unlock, default lock). Requires confirm:true.'; }
	public function validate($params) {
		if (empty($params['room'])) return 'Parameter "room" is required';
		return true;
	}
	public function permissionLevel() { return self::PERM_WRITE; }
	public function execute($params, $context) {
		$confirm = !empty($params['confirm']) && $params['confirm'] === true;
		$action = ($params['action'] ?? 'lock') === 'unlock' ? 'ConfbridgeUnlock' : 'ConfbridgeLock';
		$label = $action === 'ConfbridgeUnlock' ? 'Unlock' : 'Lock';
		if (!$confirm) return ['dry_run' => true, 'message' => "Would {$label} conference {$params['room']}. Reply yes to confirm."];
		$astman = $this->freepbx->astman;
		if (!$astman || !$astman->connected()) throw new \Exception('Cannot connect to Asterisk Manager');
		$res = $astman->send_request($action, ['Conference' => $params['room']]);
		return ['dry_run' => false, 'message' => "Conference {$params['room']} {$label}ed", 'result' => $res];
	}
}
