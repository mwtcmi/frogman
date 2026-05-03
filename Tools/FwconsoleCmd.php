<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class FwconsoleCmd extends AbstractTool {
	public function name() { return 'oc_fwconsole'; }
	public function description() { return 'Run an fwconsole command. Params: args (required, e.g. "ma list" or "sa info"). Requires confirm:true for non-read commands.'; }
	public function validate($params) {
		if (empty($params['args'])) return 'Parameter "args" is required';
		$args = $params['args'];
		// Block shell injection characters
		if (preg_match('/[;&|`$(){}]/', $args)) {
			return 'Shell metacharacters are not allowed';
		}
		// Whitelist: only allow known safe fwconsole subcommands
		$allowed = '/^(ma\s+(list|install|uninstall|enable|disable|upgrade|upgradeall|download)|sa\s+(info|update)|pm2|reload|restart|start|stop|chown|status|--version|-V|context|certificates)/i';
		if (!preg_match($allowed, $args)) {
			return 'Command not in allowed list. Use specific Frogman tools instead.';
		}
		return true;
	}
	public function requiredPermission() { return null; }
	public function permissionLevel() { return self::PERM_ADMIN; }
	public function execute($params, $context) {
		$args = $params['args'];
		$confirm = !empty($params['confirm']) && $params['confirm'] === true;
		$readOnly = preg_match('/^(ma\s+list|sa\s+info|pm2|status|--version|-V|context)/i', $args);
		if (!$readOnly && !$confirm) {
			return ['dry_run' => true, 'message' => "Would run: fwconsole {$args}."];
		}
		$output = []; $exitCode = 0;
		// Escape each argument individually
		$parts = preg_split('/\s+/', $args);
		$escaped = implode(' ', array_map('escapeshellarg', $parts));
		exec('/usr/sbin/fwconsole ' . $escaped . ' 2>&1', $output, $exitCode);
		return ['command' => "fwconsole {$args}", 'exit_code' => $exitCode, 'output' => implode("\n", $output)];
	}
}
