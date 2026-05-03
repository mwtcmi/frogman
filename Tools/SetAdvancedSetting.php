<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class SetAdvancedSetting extends AbstractTool {
	public function name() { return 'fm_set_advanced_setting'; }
	public function description() { return 'Set a FreePBX advanced setting. Params: key (required), value (required). Requires confirm:true.'; }
	public function validate($params) {
		if (empty($params['key'])) return 'Parameter "key" is required';
		if (!isset($params['value'])) return 'Parameter "value" is required';
		return true;
	}
	public function requiredPermission() { return null; }
	public function permissionLevel() { return self::PERM_ADMIN; }
	public function execute($params, $context) {
		$confirm = !empty($params['confirm']) && $params['confirm'] === true;
		$key = strtoupper($params['key']);
		$value = $params['value'];
		$current = $this->freepbx->Config->get($key);
		if (!$confirm) {
			return ['dry_run' => true, 'message' => "Would set {$key} from \"{$current}\" to \"{$value}\". Reply yes to confirm."];
		}
		$this->freepbx->Config->update($key, $value);
		return ['dry_run' => false, 'message' => "Setting {$key} updated to \"{$value}\"", 'needs_reload' => true];
	}
}
