<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class SystemDashboard extends AbstractTool {
	public function name() { return 'fm_system_dashboard'; }
	public function description() { return 'Quick system status dashboard — calls, extensions, trunks, notifications, uptime.'; }
	public function validate($params) { return true; }
	public function execute($params, $context) {
		$astman = $this->freepbx->astman;
		$db = $this->freepbx->Database;

		// Uptime + version
		$version = '';
		$uptime = '';
		if ($astman && $astman->connected()) {
			$res = $astman->Command('core show version');
			$raw = trim($res['data'] ?? '');
			$raw = preg_replace('/^Privilege:\s+\w+\s*/i', '', $raw);
			if (preg_match('/Asterisk\s+([\d.]+)/', $raw, $m)) $version = $m[1];
			$res2 = $astman->Command('core show uptime');
			$up = trim($res2['data'] ?? '');
			$up = preg_replace('/Privilege:\s+\w+,?\s*/i', '', $up);
			$uptime = trim($up);
		}

		// Active calls
		$activeCalls = 0;
		if ($astman && $astman->connected()) {
			$res = $astman->Command('core show channels concise');
			$data = trim($res['data'] ?? '');
			if (!empty($data)) {
				foreach (explode("\n", $data) as $line) {
					$line = trim($line);
					if (!empty($line) && strpos($line, 'Privilege:') !== 0) $activeCalls++;
				}
			}
		}

		// Extension count + registered
		$extCount = 0;
		$regCount = 0;
		if ($astman && $astman->connected()) {
			$res = $astman->Command('core show hints');
			$raw = trim($res['data'] ?? '');
			foreach (explode("\n", $raw) as $line) {
				if (preg_match('/^(\d+)@ext-local\s+.*State:(\S+)/', $line, $m)) {
					$extCount++;
					if ($m[2] !== 'Unavailable') $regCount++;
				}
			}
		}

		// Trunk count
		$trunkSth = $db->query("SELECT COUNT(*) FROM trunks");
		$trunkCount = (int)$trunkSth->fetchColumn();

		// Notifications
		$notif = $this->freepbx->Notifications;
		$errors = count($notif->list_error(true) ?: []);
		$warnings = count($notif->list_warning(true) ?: []);
		$updates = count($notif->list_update(true) ?: []);

		// Reload needed
		$reloadSth = $db->prepare("SELECT value FROM admin WHERE variable = 'need_reload'");
		$reloadSth->execute();
		$needReload = ($reloadSth->fetchColumn() === 'true');

		return [
			'version' => $version,
			'uptime' => $uptime,
			'active_calls' => $activeCalls,
			'extensions' => $extCount,
			'registered' => $regCount,
			'trunks' => $trunkCount,
			'errors' => $errors,
			'warnings' => $warnings,
			'updates' => $updates,
			'need_reload' => $needReload,
		];
	}
}
