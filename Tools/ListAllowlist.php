<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class ListAllowlist extends AbstractTool {
	public function name() { return 'fm_list_allowlist'; }
	public function description() { return 'List all allowed (whitelisted) numbers.'; }
	public function validate($params) { return true; }
	public function execute($params, $context) {
		$list = $this->freepbx->Allowlist->getAllowlist();
		$result = [];
		if (!empty($list)) {
			foreach ($list as $entry) {
				$result[] = [
					'number' => $entry['number'] ?? '',
					'description' => $entry['description'] ?? '',
				];
			}
		}
		return ['count' => count($result), 'allowlist' => $result];
	}
}
