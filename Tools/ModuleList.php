<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class ModuleList extends AbstractTool {
	public function name() { return 'fm_module_list'; }
	public function description() { return 'List all installed FreePBX modules with version and status. Optional filter: status (enabled/disabled).'; }
	public function validate($params) {
		if (isset($params['status']) && !in_array(strtolower($params['status']), ['enabled', 'disabled', ''])) {
			return 'Parameter "status" must be "enabled" or "disabled"';
		}
		return true;
	}
	public function execute($params, $context) {
		$statusFilter = isset($params['status']) ? strtolower($params['status']) : '';
		$mf = \module_functions::create();
		$allMods = $mf->getinfo(false, false, true);
		$modules = [];
		foreach ($allMods as $name => $info) {
			$status = '';
			if (isset($info['status'])) {
				$statusMap = [0 => 'not_installed', 1 => 'disabled', 2 => 'enabled', 3 => 'broken'];
				$status = $statusMap[$info['status']] ?? 'unknown';
			}
			if ($statusFilter && $status !== $statusFilter) continue;
			$modules[] = [
				'name' => $name,
				'version' => $info['version'] ?? '',
				'status' => $status,
				'license' => $info['license'] ?? '',
			];
		}
		// Check for available upgrades from notifications (no network call)
		$upgrades = [];
		$notif = $this->freepbx->Notifications;
		$updates = $notif->list_update(true);
		foreach ($updates as $item) {
			if (($item['id'] ?? '') === 'NEWUPDATES' && !empty($item['extended_text'])) {
				foreach (explode("\n", $item['extended_text']) as $line) {
					if (preg_match('/^\s*(\S+)\s+(\S+)\s+\(current:\s+(\S+)\)/', trim($line), $m)) {
						$upgrades[$m[1]] = $m[2];
					}
				}
			}
		}

		// Tag modules with available upgrades
		foreach ($modules as &$mod) {
			$mod['upgrade_available'] = $upgrades[$mod['name']] ?? null;
		}
		unset($mod);

		return ['count' => count($modules), 'modules' => $modules, 'upgrades_available' => count($upgrades)];
	}
}
