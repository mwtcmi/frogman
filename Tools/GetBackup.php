<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class GetBackup extends AbstractTool {
	public function name() { return 'fm_get_backup'; }
	public function description() { return 'Get details for a backup configuration. Params: id (required).'; }
	public function validate($params) {
		if (empty($params['id'])) return 'Parameter "id" is required';
		return true;
	}
	public function requiredPermission() { return null; }
	public function execute($params, $context) {
		$backup = $this->freepbx->Backup->getBackup($params['id']);
		if (empty($backup)) throw new \Exception("Backup {$params['id']} not found");
		return $backup;
	}
}
