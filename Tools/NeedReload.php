<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class NeedReload extends AbstractTool {
	public function name() { return 'fm_need_reload'; }
	public function description() { return 'Check if FreePBX needs a reload to apply pending changes.'; }
	public function validate($params) { return true; }
	public function requiredPermission() { return null; }
	public function execute($params, $context) {
		$needs = check_reload_needed();
		return ['needs_reload' => !empty($needs), 'details' => $needs ?: 'No pending changes'];
	}
}
