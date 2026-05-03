<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class SetCallerId extends AbstractTool {
	public function name() { return 'fm_set_caller_id'; }
	public function description() { return 'Set outbound caller ID on an extension. Params: ext (required), cid (required, phone number or empty to clear). Requires confirm:true.'; }
	public function validate($params) {
		if (empty($params['ext'])) return 'Parameter "ext" is required';
		if (!isset($params['cid'])) return 'Parameter "cid" is required (number or empty to clear)';
		return true;
	}
	public function permissionLevel() { return self::PERM_WRITE; }
	public function execute($params, $context) {
		$ext = $params['ext'];
		$cid = trim($params['cid']);
		$confirm = !empty($params['confirm']) && $params['confirm'] === true;

		$user = $this->freepbx->Core->getUser($ext);
		if (empty($user)) throw new \Exception("Extension {$ext} not found");

		$current = $user['outboundcid'] ?? '';
		if (!$confirm) {
			$action = empty($cid) ? "clear outbound CID" : "set outbound CID to `{$cid}`";
			$from = empty($current) ? 'none' : "`{$current}`";
			return ['dry_run' => true, 'message' => "Would {$action} on extension {$ext} ({$user['name']}). Current: {$from}."];
		}

		$db = $this->freepbx->Database;
		$sth = $db->prepare("UPDATE users SET outboundcid = ? WHERE extension = ?");
		$sth->execute([$cid, $ext]);

		$label = empty($cid) ? 'cleared' : "set to {$cid}";
		return ['dry_run' => false, 'message' => "Outbound CID {$label} on extension {$ext} ({$user['name']}).", 'needs_reload' => true];
	}
}
