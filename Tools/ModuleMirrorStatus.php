<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class ModuleMirrorStatus extends AbstractTool {
	public function name() { return 'fm_module_mirror_status'; }
	public function description() { return 'Show the FreePBX module repository (mirror) URL and which repo categories (standard/extended/unsupported/commercial) are enabled. Read-only.'; }
	public function validate($params) { return true; }
	public function requiredPermission() { return null; }
	public function permissionLevel() { return self::PERM_READ; }

	// A URL counts as Sangoma-official when its host suffix matches one of these.
	// freepbx.org is the long-standing repo host; sangoma.com is included for any
	// future migration to Sangoma-branded mirrors.
	private function isSangomaHost($url) {
		$host = parse_url(trim($url), PHP_URL_HOST);
		if (!$host) return false;
		$host = strtolower($host);
		return (substr($host, -strlen('freepbx.org')) === 'freepbx.org')
			|| (substr($host, -strlen('sangoma.com')) === 'sangoma.com');
	}

	public function execute($params, $context) {
		$current = (string) $this->freepbx->Config->get('MODULE_REPO');
		$default = (string) $this->freepbx->Config->get_conf_default_setting('MODULE_REPO');

		$urls = array_values(array_filter(array_map('trim', explode(',', $current)), function($u) { return $u !== ''; }));
		$urlRows = [];
		$sangomaCount = 0;
		foreach ($urls as $url) {
			$isSangoma = $this->isSangomaHost($url);
			if ($isSangoma) $sangomaCount++;
			$urlRows[] = ['url' => $url, 'is_sangoma' => $isSangoma];
		}
		$primary = $urlRows[0] ?? null;

		$mf = \module_functions::create();
		$active = $mf->get_active_repos();
		$activeNames = [];
		foreach ((array) $active as $k => $v) {
			if (!empty($v)) $activeNames[] = (string) $k;
		}
		sort($activeNames);

		$remote = [];
		try {
			$remote = (array) $mf->get_remote_repos(false);
		} catch (\Throwable $e) {
			$remote = [];
		}
		$remote = array_values(array_filter($remote, function($r) { return $r !== 'orphan'; }));
		sort($remote);

		$missingCategories = array_values(array_diff($remote, $activeNames));

		return [
			'current'             => $current,
			'default'             => $default,
			'matches_default'     => ($current === $default),
			'urls'                => $urlRows,
			'primary'             => $primary,
			'primary_is_sangoma'  => $primary ? !empty($primary['is_sangoma']) : false,
			'sangoma_url_count'   => $sangomaCount,
			'third_party_url_count' => count($urls) - $sangomaCount,
			'active_categories'   => $activeNames,
			'remote_categories'   => $remote,
			'missing_categories'  => $missingCategories,
			'commercial_enabled'  => in_array('commercial', $activeNames, true),
		];
	}
}
