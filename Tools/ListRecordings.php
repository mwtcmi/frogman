<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class ListRecordings extends AbstractTool {
	public function name() { return 'fm_list_recordings'; }
	public function description() { return 'List system recordings. Shows custom recordings by default. Optional: type ("all" to include built-in sounds, "builtin" for built-in only).'; }
	public function validate($params) { return true; }
	public function requiredPermission() { return null; }
	public function execute($params, $context) {
		$recs = $this->freepbx->Recordings->getSystemRecordings();
		$type = strtolower($params['type'] ?? '');

		$custom = [];
		$builtinCount = 0;
		if (!empty($recs)) {
			foreach ($recs as $name => $r) {
				if (isset($r['id'])) {
					$custom[] = ['name' => $name, 'id' => $r['id']];
				} else {
					$builtinCount++;
				}
			}
		}

		if ($type === 'all') {
			$all = [];
			foreach ($recs as $name => $r) {
				$all[] = ['name' => $name, 'custom' => isset($r['id'])];
			}
			return ['count' => count($all), 'recordings' => $all, 'custom_count' => count($custom), 'builtin_count' => $builtinCount];
		}

		return ['count' => count($custom), 'recordings' => $custom, 'builtin_count' => $builtinCount];
	}
}
