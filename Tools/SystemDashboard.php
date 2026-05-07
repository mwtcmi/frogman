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

		// Active calls — exclude Asterisk internal channels so the count reflects
		// real phone conversations. Filter in AbstractTool::isAsteriskInternalChannel.
		$activeCalls = 0;
		if ($astman && $astman->connected()) {
			$res = $astman->Command('core show channels concise');
			$data = trim($res['data'] ?? '');
			if (!empty($data)) {
				foreach (explode("\n", $data) as $line) {
					$line = trim($line);
					if (empty($line) || strpos($line, 'Privilege:') === 0) continue;
					if ($this->isAsteriskInternalChannel($line)) continue;
					$activeCalls++;
				}
			}
		}

		// Extension count from hints; registered count from actual PJSIP contacts.
		// Avoid counting hint state because DND/CustomPresence can mark an unregistered
		// extension as Busy and falsely inflate the registered count.
		$extCount = 0;
		$regCount = 0;
		if ($astman && $astman->connected()) {
			$res = $astman->Command('core show hints');
			$raw = trim($res['data'] ?? '');
			foreach (explode("\n", $raw) as $line) {
				if (preg_match('/^(\d+)@ext-local\s/', $line)) $extCount++;
			}
			$res2 = $astman->Command('pjsip show contacts');
			$raw2 = trim($res2['data'] ?? '');
			foreach (explode("\n", $raw2) as $line) {
				// Lines look like:  Contact:  101/sip:101@1.2.3.4:1378;ob   <hash>  Avail   38.153
				if (preg_match('/^\s*Contact:\s+\d+\/sip:\d+@.+\s+(Avail|Reachable)\s/', $line)) {
					$regCount++;
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
