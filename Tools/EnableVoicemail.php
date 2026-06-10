<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

// addMailbox writes voicemail.conf. That's only half of "enable VM on an
// extension" — the dialplan also needs users.voicemail set to the vm context
// (default 'novm' on a fresh extension means the macro-vm Gosub never engages).
// users.voicemail lives in Core's territory, so we go through the canonical
// delUser+addUser(editmode=true) path from feedback_freepbx_core_edit_pattern.
// Issue #36.
class EnableVoicemail extends AbstractTool {
	public function name() { return 'fm_enable_voicemail'; }
	public function description() { return 'Enable voicemail for an extension. Params: ext (required), password (optional, default 1234), context (optional vmcontext, default "default"). Requires confirm:true.'; }
	public function validate($params) {
		if (empty($params['ext'])) return 'Parameter "ext" is required';
		if (!empty($params['context']) && !preg_match('/^[a-zA-Z0-9_-]+$/', (string)$params['context'])) {
			return 'Parameter "context" must be alphanumeric.';
		}
		return true;
	}
	public function requiredPermission() { return null; }
	public function permissionLevel() { return self::PERM_WRITE; }
	public function execute($params, $context) {
		$confirm = !empty($params['confirm']) && $params['confirm'] === true;
		$ext = $params['ext'];
		$pwd = $params['password'] ?? '1234';
		$vmctx = $params['context'] ?? 'default';

		$user = $this->freepbx->Core->getUser($ext);
		if (empty($user)) throw new \Exception("Extension {$ext} not found");

		if (!$confirm) {
			return [
				'dry_run' => true,
				'message' => "Would enable voicemail on extension {$ext} (context `{$vmctx}`, password `{$pwd}`). Reply yes to confirm.",
				'ext' => $ext,
				'context' => $vmctx,
				'current_voicemail_field' => $user['voicemail'] ?? 'novm',
			];
		}

		// Step 1: write the voicemail.conf entry.
		$this->freepbx->Voicemail->addMailbox($ext, [
			'vm' => 'enabled',
			'vmcontext' => $vmctx,
			'name' => $user['name'],
			'vmpwd' => $pwd,
			'email' => '',
			'attach' => 'attach=no',
			'envelope' => 'envelope=no',
			'vmdelete' => 'vmdelete=no',
			'saycid' => 'saycid=no',
		]);

		// Step 2: wire users.voicemail to the vm context via Core BMO. Without
		// this the dialplan keeps macro-exten-vm in the "novm" branch and the
		// mailbox is never reached. delUser+addUser(editmode=true) is Core's
		// canonical edit path; editmode preserves device→user link state.
		$prevVm = $user['voicemail'] ?? 'novm';
		$user['voicemail'] = $vmctx;
		$user['extension'] = $ext;
		$this->freepbx->Core->delUser($ext, true);
		$this->freepbx->Core->addUser($ext, $user, true);

		return [
			'dry_run' => false,
			'message' => "Voicemail enabled on extension {$ext} (context `{$vmctx}`).",
			'ext' => $ext,
			'context' => $vmctx,
			'previous_voicemail_field' => $prevVm,
			'applied_voicemail_field' => $vmctx,
			'needs_reload' => true,
		];
	}
}
