<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class GetPageGroup extends AbstractTool {
	public function name() { return 'fm_get_page_group'; }
	public function description() { return 'Get details for a paging/intercom group. Params: id (required).'; }
	public function validate($params) {
		if (empty($params['id'])) return 'Parameter "id" is required';
		return true;
	}
	public function execute($params, $context) {
		$group = $this->freepbx->Paging->getPageGroupById($params['id']);
		if (empty($group)) throw new \Exception("Page group {$params['id']} not found");
		return $group;
	}
}
