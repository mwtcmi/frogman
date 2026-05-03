<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class RemoveInboundRoute extends AbstractTool {
	public function name() { return 'fm_remove_inbound_route'; }
	public function description() { return 'Remove an inbound route. Params: extension (DID number, required), cidnum (optional). Requires confirm:true.'; }
	public function validate($params) {
		if (empty($params['extension'])) return 'Parameter "extension" is required';
		return true;
	}
	public function requiredPermission() { return null; }
	public function permissionLevel() { return self::PERM_WRITE; }
	public function execute($params, $context) {
		$confirm = !empty($params['confirm']) && $params['confirm'] === true;
		$ext = $params['extension'];
		$cid = $params['cidnum'] ?? '';
		$db = $this->freepbx->Database;
		$sth = $db->prepare("SELECT * FROM incoming WHERE extension = ? AND cidnum = ?");
		$sth->execute([$ext, $cid]);
		$route = $sth->fetch(\PDO::FETCH_ASSOC);
		if (empty($route)) throw new \Exception("Inbound route for {$ext} not found");
		if (!$confirm) {
			return ['dry_run' => true, 'message' => "Would remove inbound route: DID {$ext}. Reply yes to confirm."];
		}
		$this->freepbx->Core->delDID($ext, $cid);
		return ['dry_run' => false, 'message' => "Inbound route removed: DID {$ext}", 'needs_reload' => true];
	}
}
