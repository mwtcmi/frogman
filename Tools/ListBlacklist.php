<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class ListBlacklist extends AbstractTool {
	public function name() { return 'fm_list_blacklist'; }
	public function description() { return 'List all blacklisted numbers.'; }
	public function validate($params) { return true; }
	public function requiredPermission() { return null; }
	public function execute($params, $context) {
		$list = $this->freepbx->Blacklist->getBlacklist();
		$result = [];
		if (!empty($list)) {
			foreach ($list as $entry) {
				$result[] = ['number' => $entry['number'], 'description' => $entry['description'] ?? ''];
			}
		}
		return ['count' => count($result), 'blacklist' => $result];
	}
}
