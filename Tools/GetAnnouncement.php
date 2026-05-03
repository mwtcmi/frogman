<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class GetAnnouncement extends AbstractTool {
	public function name() { return 'fm_get_announcement'; }
	public function description() { return 'Get announcement details by ID. Params: id (required).'; }
	public function validate($params) {
		if (empty($params['id'])) return 'Parameter "id" is required';
		return true;
	}
	public function execute($params, $context) {
		$ann = $this->freepbx->Announcement->getAnnouncementByID($params['id']);
		if (empty($ann)) throw new \Exception("Announcement {$params['id']} not found");
		return $ann;
	}
}
