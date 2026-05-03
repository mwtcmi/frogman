<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class ListCustomExtens extends AbstractTool {
	public function name() { return 'fm_list_custom_extensions'; }
	public function description() { return 'List all custom extensions.'; }
	public function validate($params) { return true; }
	public function execute($params, $context) {
		$extens = $this->freepbx->Customappsreg->getAllCustomExtens(); return ['count' => count($extens ?: []), 'extensions' => $extens ?: []];
	}
}
