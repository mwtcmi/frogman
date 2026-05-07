<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class OriginateCall extends AbstractTool {
	public function name() { return 'fm_originate_call'; }
	public function description() { return 'Click-to-call: make the PBX call an extension, then connect to a destination. Params: ext (extension to ring first), dest (number to connect to). Requires confirm:true.'; }
	public function validate($params) {
		if (empty($params['ext'])) return 'Parameter "ext" is required';
		if (empty($params['dest'])) return 'Parameter "dest" is required';
		return true;
	}
	public function permissionLevel() { return self::PERM_WRITE; }
	public function execute($params, $context) {
		$confirm = !empty($params['confirm']) && $params['confirm'] === true;
		$ext = $params['ext'];
		$dest = $params['dest'];
		if (!$confirm) return ['dry_run' => true, 'message' => "Would ring {$ext} first, then connect to {$dest}. Reply yes to confirm."];
		$astman = $this->freepbx->astman;
		if (!$astman || !$astman->connected()) throw new \Exception('Cannot connect to Asterisk Manager');
		// astman->Originate() accepts either 10 positional args OR a single array.
		// Use the array form — fewer ways to be wrong.
		$res = $astman->Originate([
			'Channel'  => "PJSIP/{$ext}",
			'Context'  => 'from-internal',
			'Exten'    => $dest,
			'Priority' => '1',
			'Timeout'  => 30000,
			'CallerID' => $ext,
			'Async'    => 'true',
		]);
		$ok = is_array($res) && (($res['Response'] ?? '') === 'Success');
		return [
			'dry_run' => false,
			'message' => $ok ? "Ringing {$ext} — will dial {$dest} when answered." : "Originate failed: " . ($res['Message'] ?? 'unknown error'),
			'result' => $res,
		];
	}
}
