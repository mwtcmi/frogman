<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class ListConferences extends AbstractTool {
	public function name() { return 'fm_list_conferences'; }
	public function description() { return 'List all conference rooms.'; }
	public function validate($params) { return true; }
	public function requiredPermission() { return null; }
	public function execute($params, $context) {
		$confs = $this->freepbx->Conferences->listConferences();
		$result = [];
		if (!empty($confs)) {
			foreach ($confs as $c) {
				$result[] = ['extension' => $c[0], 'name' => $c[1] ?? ''];
			}
		}
		return ['count' => count($result), 'conferences' => $result];
	}
}
