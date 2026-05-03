<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class ModuleUpgrade extends AbstractTool {
	public function name() { return 'fm_module_upgrade'; }
	public function description() { return 'Upgrade a FreePBX module. Params: name (required, or "all" for all modules). Requires confirm:true.'; }
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
			$label = ($name === 'all') ? 'all modules' : "module `{$name}`";
			return ['dry_run' => true, 'message' => "Would upgrade {$label}. This may take a few minutes."];
		}
		$cmd = ($name === 'all') ? '/usr/sbin/fwconsole ma upgradeall 2>&1' : '/usr/sbin/fwconsole ma upgrade ' . escapeshellarg($name) . ' 2>&1';
		$output = []; $exitCode = 0;
		exec($cmd, $output, $exitCode);
		$out = implode("\n", $output);
		return ['dry_run' => false, 'message' => "Module upgrade completed", 'output' => $out, 'needs_reload' => true];
	}
}
