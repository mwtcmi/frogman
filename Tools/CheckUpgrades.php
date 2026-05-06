<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class CheckUpgrades extends AbstractTool {
	public function name() { return 'fm_check_upgrades'; }
	public function description() { return 'Query online repos for module upgrades. Network call (~10s). Returns the list of modules with upgrades available — each clickable to upgrade.'; }
	public function validate($params) { return true; }
	public function permissionLevel() { return self::PERM_READ; }

	public function execute($params, $context) {
		$output = []; $exitCode = 0;
		// --format=json emits one JSON object per line. The data array we want is the
		// last line whose data field is itself an array of module rows.
		exec('/usr/sbin/fwconsole ma listonline --format=json 2>&1', $output, $exitCode);
		if ($exitCode !== 0) {
			throw new \Exception("Online check failed: " . implode("\n", $output));
		}

		$rows = [];
		foreach ($output as $line) {
			$obj = json_decode($line, true);
			if (!is_array($obj)) continue;
			$d = $obj['data'] ?? null;
			if (is_array($d) && isset($d[0]) && is_array($d[0])) {
				$rows = $d;
				break;
			}
		}

		if (empty($rows)) {
			throw new \Exception("Online check returned no module data — repo may be unreachable. Output: " . implode("\n", array_slice($output, 0, 5)));
		}

		$upgrades = [];
		foreach ($rows as $row) {
			$row = array_pad($row, 5, '');
			[$name, $version, $status, $license, $signature] = $row;
			$lc = strtolower($status);
			// Skip everything that ISN'T a real pending upgrade. Patterns observed:
			//   "Enabled and up to date" / "Disabled and up to date"  → no upgrade
			//   "Enabled; Not available online"                       → local-only module (frogman, builtin, etc.)
			//   "Not installed"                                        → not on this box
			if (strpos($lc, 'up to date') !== false) continue;
			if (strpos($lc, 'not available online') !== false) continue;
			if (strpos($lc, 'not installed') !== false) continue;
			$upgrades[] = [
				'name' => $name,
				'current_version' => $version,
				'status' => $status,
				'license' => $license,
			];
		}

		return [
			'count' => count($upgrades),
			'upgrades' => $upgrades,
		];
	}
}
