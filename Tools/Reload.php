<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class Reload extends AbstractTool {
	public function name() { return 'fm_reload'; }
	public function description() { return 'Apply configuration changes. Confirms only if there are active calls.'; }
	public function validate($params) { return true; }
	public function permissionLevel() { return self::PERM_WRITE; }
	public function execute($params, $context) {
		$confirm = !empty($params['confirm']) && $params['confirm'] === true;
		$activeCalls = 0;
		$astman = $this->freepbx->astman;
		if ($astman && $astman->connected()) {
			$res = $astman->Command("core show channels concise");
			$data = isset($res['data']) ? trim($res['data']) : '';
			if (!empty($data)) {
				foreach (explode("\n", $data) as $line) {
					$line = trim($line);
					if (!empty($line) && strpos($line, 'Privilege:') !== 0) $activeCalls++;
				}
			}
		}
		// Only require confirmation if there are active calls
		if ($activeCalls > 0 && !$confirm) {
			return ['dry_run' => true, 'message' => "There are {$activeCalls} active call(s). Would reload config — this may briefly disrupt calls.", 'active_calls' => $activeCalls];
		}
		$res = do_reload();
		// Clear the "Apply Config" bar — same as FreePBX's own reload handler
		$this->freepbx->Database->query("UPDATE admin SET value = 'false' WHERE variable = 'need_reload'");
		return ['dry_run' => false, 'message' => 'Configuration reload completed.', 'active_calls_at_reload' => $activeCalls, 'result' => $res];
	}
}
