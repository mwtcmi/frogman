<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class DisableVoicemail extends AbstractTool {
	public function name() { return 'fm_disable_voicemail'; }
	public function description() { return 'Disable voicemail for an extension. Params: ext (required). Requires confirm:true.'; }
	public function validate($params) {
		if (empty($params['ext'])) return 'Parameter "ext" is required';
		return true;
	}
	public function requiredPermission() { return null; }
	public function permissionLevel() { return self::PERM_WRITE; }
	public function execute($params, $context) {
		$confirm = !empty($params['confirm']) && $params['confirm'] === true;
		$ext = $params['ext'];
		if (!$confirm) {
			return ['dry_run' => true, 'message' => "Would disable voicemail on extension {$ext}. Reply yes to confirm."];
		}
		$this->freepbx->Voicemail->delMailbox($ext);
		return ['dry_run' => false, 'message' => "Voicemail disabled on extension {$ext}", 'needs_reload' => true];
	}
}
