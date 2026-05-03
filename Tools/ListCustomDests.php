<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class ListCustomDests extends AbstractTool {
	public function name() { return 'fm_list_custom_dests'; }
	public function description() { return 'List all custom destinations.'; }
	public function validate($params) { return true; }
	public function execute($params, $context) {
		$dests = $this->freepbx->Customappsreg->getAllCustomDests(); return ['count' => count($dests ?: []), 'destinations' => $dests ?: []];
	}
}
