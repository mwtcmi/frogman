<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class ListBackups extends AbstractTool {
	public function name() { return 'fm_list_backups'; }
	public function description() { return 'List all backup configurations.'; }
	public function validate($params) { return true; }
	public function requiredPermission() { return null; }
	public function execute($params, $context) {
		$backups = $this->freepbx->Backup->listBackups();
		return ['count' => count($backups ?: []), 'backups' => $backups ?: []];
	}
}
