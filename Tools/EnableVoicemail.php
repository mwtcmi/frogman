<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class EnableVoicemail extends AbstractTool {
	public function name() { return 'fm_enable_voicemail'; }
	public function description() { return 'Enable voicemail for an extension. Params: ext (required), password (optional, default 1234). Requires confirm:true.'; }
	public function validate($params) {
		if (empty($params['ext'])) return 'Parameter "ext" is required';
		return true;
	}
	public function requiredPermission() { return null; }
	public function permissionLevel() { return self::PERM_WRITE; }
	public function execute($params, $context) {
		$confirm = !empty($params['confirm']) && $params['confirm'] === true;
		$ext = $params['ext'];
		$pwd = $params['password'] ?? '1234';
		$user = $this->freepbx->Core->getUser($ext);
		if (empty($user)) throw new \Exception("Extension {$ext} not found");
		if (!$confirm) {
			return ['dry_run' => true, 'message' => "Would enable voicemail on extension {$ext} with password {$pwd}. Reply yes to confirm."];
		}
		$this->freepbx->Voicemail->addMailbox($ext, ['vm' => 'enabled', 'name' => $user['name'], 'vmpwd' => $pwd, 'email' => '', 'attach' => 'attach=no', 'envelope' => 'envelope=no', 'vmdelete' => 'vmdelete=no', 'saycid' => 'saycid=no']);
		return ['dry_run' => false, 'message' => "Voicemail enabled on extension {$ext}", 'needs_reload' => true];
	}
}
