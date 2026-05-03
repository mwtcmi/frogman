<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class ListTrunkTypes extends AbstractTool {
	public function name() { return 'fm_list_trunk_types'; }
	public function description() { return 'List available trunk technology types.'; }
	public function validate($params) { return true; }
	public function execute($params, $context) {
		$types = $this->freepbx->Core->listTrunkTypes(); return ['types' => $types ?: []];
	}
}
