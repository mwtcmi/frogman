<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class ListPermissions extends AbstractTool {
	public function name() { return 'fm_list_permissions'; }
	public function description() { return 'List all Frogman user permission levels.'; }
	public function validate($params) { return true; }
	public function requiredPermission() { return null; }
	public function permissionLevel() { return self::PERM_ADMIN; }
	public function execute($params, $context) {
		$db = $this->freepbx->Database;
		$sth = $db->query("SELECT username, level FROM oc_permissions ORDER BY username");
		$rows = $sth->fetchAll(\PDO::FETCH_ASSOC);
		return ['count' => count($rows), 'permissions' => $rows];
	}
}
