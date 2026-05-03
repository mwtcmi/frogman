<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class SetRecording extends AbstractTool {
	public function name() { return 'fm_set_recording'; }
	public function description() { return 'Set call recording mode on an extension. Params: ext (required), mode (required: always, never, dontcare, force). Requires confirm:true.'; }
	public function validate($params) {
		if (empty($params['ext'])) return 'Parameter "ext" is required';
		if (empty($params['mode'])) return 'Parameter "mode" is required (always, never, dontcare, force)';
		$valid = ['always', 'never', 'dontcare', 'force'];
		if (!in_array(strtolower($params['mode']), $valid)) return 'Parameter "mode" must be: always, never, dontcare, or force';
		return true;
	}
	public function permissionLevel() { return self::PERM_WRITE; }
	public function execute($params, $context) {
		$ext = $params['ext'];
		$mode = strtolower($params['mode']);
		$confirm = !empty($params['confirm']) && $params['confirm'] === true;

		$user = $this->freepbx->Core->getUser($ext);
		if (empty($user)) throw new \Exception("Extension {$ext} not found");

		$current = $user['recording'] ?? 'dontcare';
		if (!$confirm) {
			return ['dry_run' => true, 'message' => "Would set call recording to `{$mode}` on extension {$ext} ({$user['name']}). Current: `{$current}`."];
		}

		$db = $this->freepbx->Database;
		$sth = $db->prepare("UPDATE users SET recording = ? WHERE extension = ?");
		$sth->execute([$mode, $ext]);

		return ['dry_run' => false, 'message' => "Call recording set to `{$mode}` on extension {$ext} ({$user['name']}).", 'needs_reload' => true];
	}
}
