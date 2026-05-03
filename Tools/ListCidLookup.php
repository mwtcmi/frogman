<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class ListCidLookup extends AbstractTool {
	public function name() { return 'fm_list_cid_lookup'; }
	public function description() { return 'List all Caller ID lookup sources.'; }
	public function validate($params) { return true; }
	public function execute($params, $context) {
		$list = $this->freepbx->Cidlookup->getList();
		$result = [];
		if (!empty($list)) {
			foreach ($list as $entry) {
				$result[] = [
					'id' => $entry['cidlookup_id'] ?? '',
					'description' => $entry['description'] ?? '',
					'sourcetype' => $entry['sourcetype'] ?? '',
				];
			}
		}
		return ['count' => count($result), 'sources' => $result];
	}
}
