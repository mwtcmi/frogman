<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class GetBackupDetails extends AbstractTool {
	public function name() { return 'fm_get_backup_details'; }
	public function description() { return 'Get backup job details. Params: id (required).'; }
	public function validate($params) { if (empty($params['id'])) return 'Parameter "id" is required';
		return true; }
	public function execute($params, $context) {
		return $this->freepbx->Backup->getBackup($params['id']);
	}
}
