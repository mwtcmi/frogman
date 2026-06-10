<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

// Mirrors EnableVoicemail: delMailbox drops the voicemail.conf entry, and we
// flip users.voicemail back to 'novm' through Core's delUser+addUser editmode
// path so the dialplan stops routing to a now-gone mailbox. Issue #36.
class DisableVoicemail extends AbstractTool {
	public function name() { return 'fm_disable_voicemail'; }
	public function description() { return 'Disable voicemail for an extension. Drops the mailbox and clears the user record\'s voicemail field. Params: ext (required). Requires confirm:true.'; }
	public function validate($params) {
		if (empty($params['ext'])) return 'Parameter "ext" is required';
		return true;
	}
	public function requiredPermission() { return null; }
	public function permissionLevel() { return self::PERM_WRITE; }
	public function execute($params, $context) {
		$confirm = !empty($params['confirm']) && $params['confirm'] === true;
		$ext = $params['ext'];

		$user = $this->freepbx->Core->getUser($ext);
		if (empty($user)) throw new \Exception("Extension {$ext} not found");

		if (!$confirm) {
			return [
				'dry_run' => true,
				'message' => "Would disable voicemail on extension {$ext}. Reply yes to confirm.",
				'ext' => $ext,
				'current_voicemail_field' => $user['voicemail'] ?? 'novm',
			];
		}

		// Step 1: drop the voicemail.conf entry.
		$this->freepbx->Voicemail->delMailbox($ext);

		// Step 2: clear users.voicemail via Core BMO. delUser+addUser(editmode=true)
		// is Core's canonical edit path; editmode preserves device→user link state.
		$prevVm = $user['voicemail'] ?? 'novm';
		$user['voicemail'] = 'novm';
		$user['extension'] = $ext;
		$this->freepbx->Core->delUser($ext, true);
		$this->freepbx->Core->addUser($ext, $user, true);

		return [
			'dry_run' => false,
			'message' => "Voicemail disabled on extension {$ext}.",
			'ext' => $ext,
			'previous_voicemail_field' => $prevVm,
			'applied_voicemail_field' => 'novm',
			'needs_reload' => true,
		];
	}
}
