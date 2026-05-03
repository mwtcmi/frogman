<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class ListDndStatus extends AbstractTool {
	public function name() { return 'fm_list_dnd_status'; }
	public function description() { return 'List DND status for all extensions.'; }
	public function validate($params) { return true; }
	public function execute($params, $context) {
		$statuses = $this->freepbx->Donotdisturb->getAllStatuses(); $result = []; foreach($statuses as $ext => $s) { $result[] = ['ext' => $ext, 'status' => $s]; } return ['count' => count($result), 'extensions' => $result];
	}
}
