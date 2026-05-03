<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class GetDiskSpace extends AbstractTool {
	public function name() { return 'fm_get_disk_space'; }
	public function description() { return 'Get disk space usage.'; }
	public function validate($params) { return true; }
	public function execute($params, $context) {
		return $this->freepbx->Dashboard->getdiskspace();
	}
}
