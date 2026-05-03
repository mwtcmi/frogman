<?php
namespace FreePBX\modules\Frogman\Tools;

require_once __DIR__ . '/AbstractTool.php';

class ModuleStatus extends AbstractTool {

	public function name() {
		return 'fm_module_status';
	}

	public function description() {
		return 'Get detailed status of a specific module. Params: name (module rawname, required).';
	}

	public function validate($params) {
		if (empty($params['name'])) {
			return 'Parameter "name" is required (module rawname)';
		}
		if (!preg_match('/^[a-z0-9_]+$/i', $params['name'])) {
			return 'Parameter "name" must be alphanumeric/underscore';
		}
		return true;
	}

	public function requiredPermission() {
		return null;
	}

	public function execute($params, $context) {
		$name = strtolower($params['name']);

		// Get module info via module_functions
		$mf = \module_functions::create();
		$modInfo = $mf->getinfo($name);

		if (empty($modInfo) || empty($modInfo[$name])) {
			throw new \Exception("Module '{$name}' not found");
		}

		$info = $modInfo[$name];

		// Check if module directory exists and read module.xml version
		$modDir = "/var/www/html/admin/modules/{$name}";
		$dirExists = is_dir($modDir);

		// Check for available upgrade from notifications (no network call)
		$upgradeVersion = null;
		$notif = $this->freepbx->Notifications;
		$updates = $notif->list_update(true);
		foreach ($updates as $item) {
			if (($item['id'] ?? '') === 'NEWUPDATES' && !empty($item['extended_text'])) {
				if (preg_match('/^\s*' . preg_quote($name, '/') . '\s+(\S+)/m', $item['extended_text'], $um)) {
					$upgradeVersion = $um[1];
				}
			}
		}

		return [
			'name' => $name,
			'display_name' => $info['name'] ?? $name,
			'version' => $info['version'] ?? 'unknown',
			'status' => isset($info['status']) ? $this->statusToString($info['status']) : 'unknown',
			'license' => $info['license'] ?? 'unknown',
			'description' => $info['description'] ?? '',
			'category' => $info['category'] ?? '',
			'publisher' => $info['publisher'] ?? '',
			'directory_exists' => $dirExists,
			'upgrade_available' => $upgradeVersion,
		];
	}

	private function statusToString($status) {
		$map = [
			0 => 'not_installed',
			1 => 'disabled',
			2 => 'enabled',
			3 => 'broken',
		];
		// Handle both numeric and already-string statuses
		if (is_numeric($status)) {
			return $map[(int) $status] ?? "unknown({$status})";
		}
		return (string) $status;
	}
}
