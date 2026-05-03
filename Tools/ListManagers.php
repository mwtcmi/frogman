<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class ListManagers extends AbstractTool {
	public function name() { return 'fm_list_managers'; }
	public function description() { return 'List AMI manager users.'; }
	public function validate($params) { return true; }
	public function execute($params, $context) {
		$mgrs = $this->freepbx->Manager->list_managers(); return ['count' => count($mgrs ?: []), 'managers' => $mgrs ?: []];
	}
}
