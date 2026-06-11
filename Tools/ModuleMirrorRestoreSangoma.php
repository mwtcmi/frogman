<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class ModuleMirrorRestoreSangoma extends AbstractTool {
	public function name() { return 'fm_module_mirror_restore_sangoma'; }
	public function description() { return 'Switch the FreePBX module repository (MODULE_REPO) back to the Sangoma-official default. Params: mode (ensure_primary|replace_all, default ensure_primary), enable_categories (array of repo categories to also turn on, e.g. ["commercial"]). Requires confirm:true.'; }
	public function validate($params) {
		if (isset($params['mode']) && !in_array($params['mode'], ['ensure_primary', 'replace_all'], true)) {
			return 'Parameter "mode" must be "ensure_primary" or "replace_all"';
		}
		if (isset($params['enable_categories']) && !is_array($params['enable_categories'])) {
			return 'Parameter "enable_categories" must be an array';
		}
		return true;
	}
	public function requiredPermission() { return null; }
	public function permissionLevel() { return self::PERM_ADMIN; }

	private function isSangomaHost($url) {
		$host = parse_url(trim($url), PHP_URL_HOST);
		if (!$host) return false;
		$host = strtolower($host);
		return (substr($host, -strlen('freepbx.org')) === 'freepbx.org')
			|| (substr($host, -strlen('sangoma.com')) === 'sangoma.com');
	}

	// Compose the new MODULE_REPO value based on mode.
	//   ensure_primary: Sangoma default(s) first, then keep any non-Sangoma URLs the user had.
	//   replace_all:    just the Sangoma default — third-party URLs are dropped.
	private function composeNewRepo($currentUrls, $defaultUrls, $mode) {
		$defaults = array_values(array_filter(array_map('trim', $defaultUrls), function($u) { return $u !== ''; }));
		if ($mode === 'replace_all') {
			return $defaults;
		}
		$out = $defaults;
		foreach ($currentUrls as $url) {
			$url = trim($url);
			if ($url === '') continue;
			if (in_array($url, $out, true)) continue;
			if ($this->isSangomaHost($url)) continue;
			$out[] = $url;
		}
		return $out;
	}

	public function execute($params, $context) {
		$confirm = !empty($params['confirm']) && $params['confirm'] === true;
		$mode = $params['mode'] ?? 'ensure_primary';
		$enableCategories = isset($params['enable_categories']) ? array_values($params['enable_categories']) : [];

		$current = (string) $this->freepbx->Config->get('MODULE_REPO');
		$default = (string) $this->freepbx->Config->get_conf_default_setting('MODULE_REPO');

		$currentUrls = array_values(array_filter(array_map('trim', explode(',', $current)), function($u) { return $u !== ''; }));
		$defaultUrls = array_values(array_filter(array_map('trim', explode(',', $default)), function($u) { return $u !== ''; }));
		$newUrls = $this->composeNewRepo($currentUrls, $defaultUrls, $mode);
		$newRepo = implode(',', $newUrls);

		$mf = \module_functions::create();
		$active = (array) $mf->get_active_repos();
		$activeNames = [];
		foreach ($active as $k => $v) { if (!empty($v)) $activeNames[] = (string) $k; }

		$remote = [];
		try { $remote = (array) $mf->get_remote_repos(false); } catch (\Throwable $e) {}
		$remote = array_values(array_filter($remote, function($r) { return $r !== 'orphan'; }));

		// Only the categories the user asked for that are (a) advertised by the
		// remote and (b) not already on. Anything else is a no-op or unsafe.
		$categoriesToEnable = [];
		$categoryWarnings = [];
		foreach ($enableCategories as $cat) {
			$cat = (string) $cat;
			if (in_array($cat, $activeNames, true)) continue;
			if ($remote && !in_array($cat, $remote, true)) {
				$categoryWarnings[] = "Category \"{$cat}\" is not advertised by the remote — skipped.";
				continue;
			}
			$categoriesToEnable[] = $cat;
		}

		$urlChange = ($current !== $newRepo);
		$categoryChange = !empty($categoriesToEnable);

		if (!$urlChange && !$categoryChange) {
			return [
				'dry_run' => false,
				'no_op' => true,
				'message' => 'Already on the Sangoma default — nothing to change.',
				'current' => $current,
				'active_categories' => $activeNames,
			];
		}

		if (!$confirm) {
			return [
				'dry_run' => true,
				'mode' => $mode,
				'url_change' => $urlChange,
				'category_change' => $categoryChange,
				'current_repo' => $current,
				'proposed_repo' => $newRepo,
				'current_urls' => $currentUrls,
				'proposed_urls' => $newUrls,
				'enable_categories' => $categoriesToEnable,
				'active_categories' => $activeNames,
				'category_warnings' => $categoryWarnings,
				'message' => 'Re-run with confirm:true to apply.',
			];
		}

		if ($urlChange) {
			$this->freepbx->Config->update('MODULE_REPO', $newRepo);
		}
		foreach ($categoriesToEnable as $cat) {
			$mf->set_active_repo($cat, 1);
		}

		return [
			'dry_run' => false,
			'mode' => $mode,
			'url_change' => $urlChange,
			'category_change' => $categoryChange,
			'previous_repo' => $current,
			'new_repo' => $newRepo,
			'enabled_categories' => $categoriesToEnable,
			'category_warnings' => $categoryWarnings,
			'message' => 'Module repository restored.',
		];
	}
}
