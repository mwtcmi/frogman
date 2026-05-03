<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class GetSysInfo extends AbstractTool {
	public function name() { return 'fm_get_sys_info'; }
	public function description() { return 'Get system information — OS, CPU, memory, uptime.'; }
	public function validate($params) { return true; }
	public function execute($params, $context) {
		return $this->freepbx->Dashboard->getSysInfo();
	}
}
