<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class ListPaging extends AbstractTool {
	public function name() { return 'fm_list_paging'; }
	public function description() { return 'List all paging/intercom groups.'; }
	public function validate($params) { return true; }
	public function requiredPermission() { return null; }
	public function execute($params, $context) {
		$groups = $this->freepbx->Paging->listGroups(true);
		$result = [];
		if (!empty($groups)) {
			foreach ($groups as $g) {
				$result[] = ['extension' => $g[0] ?? $g['page_group'], 'description' => $g[1] ?? $g['description'] ?? ''];
			}
		}
		return ['count' => count($result), 'paging_groups' => $result];
	}
}
