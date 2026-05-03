<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class ListIvrs extends AbstractTool {
	public function name() { return 'fm_list_ivrs'; }
	public function description() { return 'List all IVRs.'; }
	public function validate($params) { return true; }
	public function requiredPermission() { return null; }
	public function execute($params, $context) {
		$ivrs = $this->freepbx->Ivr->getAllDetails();
		$result = [];
		if (!empty($ivrs)) {
			foreach ($ivrs as $ivrId => $ivrData) {
				$ivr = is_array($ivrData) && isset($ivrData[0]) ? $ivrData[0] : $ivrData;
				$result[] = ['id' => $ivr['id'] ?? $ivrId, 'name' => $ivr['name'] ?? '', 'description' => $ivr['description'] ?? ''];
			}
		}
		return ['count' => count($result), 'ivrs' => $result];
	}
}
