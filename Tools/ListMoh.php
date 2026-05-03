<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class ListMoh extends AbstractTool {
	public function name() { return 'fm_list_moh'; }
	public function description() { return 'List all music on hold categories.'; }
	public function validate($params) { return true; }
	public function requiredPermission() { return null; }
	public function execute($params, $context) {
		$cats = $this->freepbx->Music->getCategories();
		$result = [];
		if (!empty($cats)) {
			foreach ($cats as $cat) {
				$result[] = ['name' => $cat['category'] ?? $cat['name'] ?? '', 'type' => $cat['type'] ?? ''];
			}
		}
		return ['count' => count($result), 'categories' => $result];
	}
}
