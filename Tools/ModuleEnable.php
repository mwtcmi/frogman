<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class ModuleEnable extends AbstractTool {
	public function name() { return 'fm_module_enable'; }
	public function description() { return 'Enable a FreePBX module. Params: name (required). Requires confirm:true.'; }
	public function validate($params) {
		if (empty($params['name'])) return 'Parameter "name" is required';
		return true;
	}
	public function requiredPermission() { return null; }
	public function permissionLevel() { return self::PERM_ADMIN; }
	public function execute($params, $context) {
		$confirm = !empty($params['confirm']) && $params['confirm'] === true;
		$name = $params['name'];
		if (!$confirm) {
			return ['dry_run' => true, 'message' => "Would enable module {$name}. Reply yes to confirm."];
		}
		$output = []; $exitCode = 0;
		exec("/usr/sbin/fwconsole ma enable " . escapeshellarg($name) . " 2>&1", $output, $exitCode);
		$out = implode("\n", $output);
		if ($exitCode !== 0) throw new \Exception("Enable failed: {$out}");
		return ['dry_run' => false, 'message' => "Module {$name} enabled", 'output' => $out, 'needs_reload' => true];
	}
}
