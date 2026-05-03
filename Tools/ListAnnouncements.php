<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class ListAnnouncements extends AbstractTool {
	public function name() { return 'fm_list_announcements'; }
	public function description() { return 'List all announcements.'; }
	public function validate($params) { return true; }
	public function requiredPermission() { return null; }
	public function execute($params, $context) {
		$anns = $this->freepbx->Announcement->getAnnouncements();
		$result = [];
		if (!empty($anns)) {
			foreach ($anns as $a) {
				$result[] = ['id' => $a['announcement_id'], 'description' => $a['description'] ?? ''];
			}
		}
		return ['count' => count($result), 'announcements' => $result];
	}
}
