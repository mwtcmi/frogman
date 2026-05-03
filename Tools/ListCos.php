<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class ListCos extends AbstractTool {
	public function name() { return 'fm_list_cos'; }
	public function description() { return 'List all Class of Service policies.'; }
	public function validate($params) { return true; }
	public function execute($params, $context) {
		$cos = $this->freepbx->Cos->getAllCOS(); return ['count' => count($cos ?: []), 'policies' => $cos ?: []];
	}
}
