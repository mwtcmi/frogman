<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class SetCallWaiting extends AbstractTool {
	public function name() { return 'fm_set_call_waiting'; }
	public function description() { return 'Enable or disable call waiting on an extension. Params: ext (required), state (required: on/off). Requires confirm:true.'; }
	public function validate($params) {
		if (empty($params['ext'])) return 'Parameter "ext" is required';
		if (empty($params['state']) || !in_array(strtolower($params['state']), ['on', 'off', 'enabled', 'disabled'])) {
			return 'Parameter "state" must be on or off';
		}
		return true;
	}
	public function permissionLevel() { return self::PERM_WRITE; }
	public function execute($params, $context) {
		$ext = $params['ext'];
		$state = in_array(strtolower($params['state']), ['on', 'enabled']) ? 'enabled' : 'disabled';
		$confirm = !empty($params['confirm']) && $params['confirm'] === true;
		if (!$confirm) return ['dry_run' => true, 'message' => "Would set call waiting to `{$state}` on extension {$ext}."];
		$this->freepbx->Callwaiting->setStatusByExtension($ext, $state);
		return ['dry_run' => false, 'message' => "Call waiting set to `{$state}` on extension {$ext}.", 'needs_reload' => true];
	}
}
