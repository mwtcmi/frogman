<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

// addMailbox writes voicemail.conf. That's only half of "enable VM on an
// extension" — the dialplan also needs users.voicemail set to the vm context
// (default 'novm' on a fresh extension means the macro-vm Gosub never engages).
// users.voicemail lives in Core's territory, so we go through the canonical
// delUser+addUser(editmode=true) path from feedback_freepbx_core_edit_pattern.
// Issue #36.
//
// addMailbox also REWRITES the mailbox's whole voicemail.conf line
// (pwd,name,email,pager,options) and re-derives the novmpw/novmstar AstDB flags
// from whatever it is handed, so this tool reads the current box first and feeds
// every field back in. Without that, enabling voicemail on a mailbox that
// already exists silently wipes its email address and every option flag.
class EnableVoicemail extends AbstractTool {
	public function name() { return 'fm_enable_voicemail'; }
	public function description() { return 'Enable voicemail for an extension. Params: ext (required), password (optional — keeps the current PIN on an existing mailbox, 1234 on a new one), context (optional vmcontext, default "default"), email, attach (yes|no), envelope (yes|no), saycid (yes|no), vmdelete (yes|no). On an EXISTING mailbox every setting you do not name is carried forward untouched — email, pager, the option flags, and the "require password"/"disable *" AstDB flags. Requires confirm:true.'; }

	public function validate($params) {
		if (empty($params['ext'])) return 'Parameter "ext" is required';
		if (!empty($params['context']) && !preg_match('/^[a-zA-Z0-9_-]+$/', (string)$params['context'])) {
			return 'Parameter "context" must be alphanumeric.';
		}
		foreach (['attach', 'envelope', 'saycid', 'vmdelete'] as $k) {
			if (isset($params[$k]) && !in_array($params[$k], ['yes', 'no'], true)) {
				return "Parameter \"{$k}\" must be \"yes\" or \"no\"";
			}
		}
		return true;
	}

	public function requiredPermission() { return null; }
	public function permissionLevel() { return self::PERM_WRITE; }

	// addMailbox() key => the key the same flag comes back under from getMailbox()['options'].
	// Note the asymmetry: you write "vmdelete", you read it back as "delete".
	private static $optMap = [
		'attach'   => 'attach',
		'saycid'   => 'saycid',
		'envelope' => 'envelope',
		'vmdelete' => 'delete',
	];

	public function execute($params, $context) {
		$confirm = !empty($params['confirm']) && $params['confirm'] === true;
		$ext = $params['ext'];

		$user = $this->freepbx->Core->getUser($ext);
		if (empty($user)) throw new \Exception("Extension {$ext} not found");

		$existing = $this->freepbx->Voicemail->getMailbox($ext);
		$existing = is_array($existing) ? $existing : [];
		$isNew = empty($existing);
		$curOpts = (isset($existing['options']) && is_array($existing['options'])) ? $existing['options'] : [];

		$vmctx = $params['context'] ?? ($existing['vmcontext'] ?? 'default');
		$pwd   = isset($params['password']) ? (string)$params['password'] : (string)($existing['pwd'] ?? '1234');
		$email = array_key_exists('email', $params) ? (string)$params['email'] : (string)($existing['email'] ?? '');
		$pager = (string)($existing['pager'] ?? '');

		// Resolve each flag: explicit param > current value > "no".
		$flags = [];
		foreach (self::$optMap as $writeKey => $readKey) {
			if (isset($params[$writeKey])) {
				$flags[$writeKey] = $params[$writeKey];
			} elseif (isset($curOpts[$readKey])) {
				$flags[$writeKey] = $curOpts[$readKey];
			} else {
				$flags[$writeKey] = 'no';
			}
		}

		if (!$confirm) {
			return [
				'dry_run' => true,
				'message' => $isNew
					? "Would create voicemail box {$ext} (context `{$vmctx}`, password `{$pwd}`). Reply yes to confirm."
					: "Would update the EXISTING voicemail box {$ext} (context `{$vmctx}`, password `{$pwd}`). Email, pager and any option you did not name are preserved. Reply yes to confirm.",
				'ext' => $ext,
				'context' => $vmctx,
				'new_mailbox' => $isNew,
				'email' => $email,
				'options' => $flags,
				'current_voicemail_field' => $user['voicemail'] ?? 'novm',
			];
		}

		// Step 1: write the voicemail.conf entry, carrying the existing box forward.
		$settings = [
			'vm'        => 'enabled',
			'vmcontext' => $vmctx,
			'name'      => $user['name'],
			'vmpwd'     => $pwd,
			'email'     => $email,
			'pager'     => $pager,
		];

		// Seed from the current option string so anything not modelled by name
		// (imapuser/imappassword, tz, locale, …) survives the rewrite. addMailbox()
		// parses 'options' first and lets the individual keys below override it.
		if (!empty($curOpts)) {
			$carry = [];
			foreach ($curOpts as $k => $v) { $carry[] = $k . '=' . $v; }
			$settings['options'] = implode('|', $carry);
		}
		foreach ($flags as $writeKey => $val) {
			$settings[$writeKey] = $writeKey . '=' . $val;
		}

		// novmpw / novmstar live in AstDB, not the conf line, and addMailbox()
		// DELETES them whenever it isn't explicitly told to keep them. Read them
		// back and re-assert. The encodings are asymmetric on purpose:
		//   passlogin: bare 'no'      => keep novmpw   | 'passlogin=yes' => clear it
		//   novmstar:  'novmstar=yes' => keep novmstar | bare 'no'       => clear it
		$astFlags = $this->readAstdbVmFlags($ext);
		if ($astFlags !== null) {
			$settings['passlogin'] = $astFlags['novmpw']   ? 'no' : 'passlogin=yes';
			$settings['novmstar']  = $astFlags['novmstar'] ? 'novmstar=yes' : 'no';
		}

		$this->freepbx->Voicemail->addMailbox($ext, $settings);

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
			'message' => $isNew
				? "Voicemail box {$ext} created (context `{$vmctx}`)."
				: "Voicemail enabled on extension {$ext} (context `{$vmctx}`); existing settings preserved.",
			'ext' => $ext,
			'context' => $vmctx,
			'new_mailbox' => $isNew,
			'email' => $email,
			'options' => $flags,
			'preserved' => $isNew ? [] : ['email', 'pager', 'option flags', 'novmpw/novmstar'],
			'previous_voicemail_field' => $prevVm,
			'applied_voicemail_field' => $vmctx,
			'needs_reload' => true,
		];
	}

	/**
	 * Current novmpw/novmstar AstDB flags, or null if AstDB can't be read (in
	 * which case we leave them out of $settings and keep the old behaviour
	 * rather than guessing).
	 */
	private function readAstdbVmFlags($ext) {
		try {
			$astman = $this->freepbx->astman;
			if (empty($astman) || !method_exists($astman, 'database_get')) return null;
			return [
				'novmpw'   => (trim((string)$astman->database_get('AMPUSER', $ext . '/novmpw')) !== ''),
				'novmstar' => (trim((string)$astman->database_get('AMPUSER', $ext . '/novmstar')) !== ''),
			];
		} catch (\Throwable $e) {
			return null;
		}
	}
}
