<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class ListLogFiles extends AbstractTool {
	public function name() { return 'fm_list_log_files'; }
	public function description() { return 'List Asterisk log files.'; }
	public function validate($params) { return true; }
	public function execute($params, $context) {
		$logs = $this->freepbx->Logfiles->getLogfilesAll(); return ['count' => count($logs ?: []), 'logs' => $logs ?: []];
	}
}
