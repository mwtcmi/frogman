<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class GetAllRingGroups extends AbstractTool {
	public function name() { return 'fm_get_all_ringgroups'; }
	public function description() { return 'List all ring groups with full details including members.'; }
	public function validate($params) { return true; }
	public function execute($params, $context) {
		$groups = $this->freepbx->Ringgroups->getAllGroups(); $result = []; if(!empty($groups)) { foreach($groups as $g) { $result[] = ['grpnum' => $g['grpnum'] ?? '', 'description' => $g['description'] ?? '', 'strategy' => $g['strategy'] ?? '', 'grptime' => $g['grptime'] ?? '', 'members' => $g['grplist'] ?? '']; } } return ['count' => count($result), 'groups' => $result];
	}
}
