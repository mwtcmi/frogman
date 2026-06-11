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
					if (empty($line) || strpos($line, 'Privilege:') === 0) continue;
					// Skip Asterisk internal worker channels — Message/* (SMS queue) and
					// AsyncGoto/* (dialplan jumps) are not real phone calls. Otherwise the
					// reload-confirm prompt would fire whenever one of these ghosts was up.
					if ($this->isAsteriskInternalChannel($line)) continue;
					$activeCalls++;
				}
			}
		}
		// Only require confirmation if there are active calls
		if ($activeCalls > 0 && !$confirm) {
			return ['dry_run' => true, 'message' => "There are {$activeCalls} active call(s). Would reload config. This may briefly disrupt calls.", 'active_calls' => $activeCalls];
		}
		$res = do_reload();
		// fwconsole reload (called by do_reload) clears need_reload itself on success — no manual UPDATE needed.
		$lines = [
			'🐸 Hopped to it. New configuration is live.',
			'✅ Reloaded. Asterisk has the new config; calls flowing on the new rules.',
			'✅ Configuration applied. We\'re live.',
			'🐸 Tango handled the reload. New rules are live.',
			'✅ Reloaded. The PBX is running the new config now.',
		];
		$msg = $lines[array_rand($lines)];
		return ['dry_run' => false, 'message' => $msg, 'active_calls_at_reload' => $activeCalls, 'result' => $res];
	}
}
