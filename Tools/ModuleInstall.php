<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class ModuleInstall extends AbstractTool {
	public function name() { return 'fm_module_install'; }
	public function description() { return 'Install a FreePBX module. Auto-downloads from repo if not locally available. Params: name (required). Requires confirm:true.'; }
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
			return ['dry_run' => true, 'message' => "Would install module {$name} (auto-downloads from repo if needed). Reply yes to confirm."];
		}
		$arg = escapeshellarg($name);
		$transcript = [];
		$downloaded = false;

		$installFailRe = '/Unable to install module|Cannot find module|not locally (?:available|installed)|module not found|no such module/i';
		$installOkRe = '/is already installed|nothing to install/i';

		$output = []; $exitCode = 0;
		exec("/usr/sbin/fwconsole ma install {$arg} 2>&1", $output, $exitCode);
		$out = implode("\n", $output);
		$transcript[] = "$ fwconsole ma install {$name}";
		$transcript[] = $out;

		$failed = ($exitCode !== 0) || (preg_match($installFailRe, $out) && !preg_match($installOkRe, $out));

		if ($failed) {
			$dlOut = []; $dlExit = 0;
			exec("/usr/sbin/fwconsole ma download {$arg} 2>&1", $dlOut, $dlExit);
			$dlStr = implode("\n", $dlOut);
			$transcript[] = "$ fwconsole ma download {$name}";
			$transcript[] = $dlStr;
			$dlFailed = ($dlExit !== 0) || preg_match('/Cannot find|not found|Unknown error|Error\(s\) downloading/i', $dlStr);
			if ($dlFailed) {
				throw new \Exception("Module `{$name}` not found in any configured repo (standard, commercial, unsupported).\n\n" . implode("\n", $transcript));
			}
			$downloaded = true;

			$output = []; $exitCode = 0;
			exec("/usr/sbin/fwconsole ma install {$arg} 2>&1", $output, $exitCode);
			$out = implode("\n", $output);
			$transcript[] = "$ fwconsole ma install {$name}";
			$transcript[] = $out;
			$failed = ($exitCode !== 0) || (preg_match($installFailRe, $out) && !preg_match($installOkRe, $out));
		}

		if ($failed) {
			throw new \Exception("Install failed:\n\n" . implode("\n", $transcript));
		}

		$msg = $downloaded
			? "Module `{$name}` downloaded and installed"
			: "Module `{$name}` installed";

		return ['dry_run' => false, 'message' => $msg, 'output' => implode("\n", $transcript), 'needs_reload' => true];
	}
}
