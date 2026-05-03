<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class ListFilestores extends AbstractTool {
	public function name() { return 'fm_list_filestores'; }
	public function description() { return 'List all filestore locations (local, FTP, S3).'; }
	public function validate($params) { return true; }
	public function requiredPermission() { return null; }
	public function execute($params, $context) {
		$locations = $this->freepbx->Filestore->listLocations();
		return ['locations' => $locations ?: []];
	}
}
