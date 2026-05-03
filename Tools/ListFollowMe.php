<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class ListFollowMe extends AbstractTool {
	public function name() { return 'fm_list_followme'; }
	public function description() { return 'List all Follow Me configurations.'; }
	public function validate($params) { return true; }
	public function execute($params, $context) {
		try { $all = $this->freepbx->Findmefollow->getAllFollowmes(); $result = []; if(!empty($all)) { foreach($all as $fm) { $result[] = ['ext' => $fm['grpnum'] ?? '', 'list' => $fm['grplist'] ?? '', 'strategy' => $fm['strategy'] ?? '']; } } return ['count' => count($result), 'followmes' => $result]; } catch(\Exception $e) { return ['count' => 0, 'followmes' => []]; }
	}
}
