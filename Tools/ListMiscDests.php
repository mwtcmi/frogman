<?php
namespace FreePBX\modules\Frogman\Tools;

require_once __DIR__ . '/AbstractTool.php';

class ListMiscDests extends AbstractTool {
	public function name() { return 'fm_list_misc_dests'; }
	public function description() { return 'List all misc destinations.'; }
	public function validate($params) { return true; }
	public function requiredPermission() { return null; }

	public function execute($params, $context) {
		$dests = $this->freepbx->Miscdests->mdlist(true);
		$result = [];
		if (!empty($dests)) {
			foreach ($dests as $d) {
				$result[] = [
					'id' => $d['id'],
					'description' => $d['description'],
					'destdial' => $d['destdial'],
				];
			}
		}
		return ['count' => count($result), 'destinations' => $result];
	}
}
