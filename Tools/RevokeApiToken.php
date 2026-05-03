<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class RevokeApiToken extends AbstractTool {
	public function name() { return 'fm_revoke_api_token'; }
	public function description() { return 'Revoke an API token. Params: id (required). Requires confirm:true.'; }
	public function validate($params) {
		if (empty($params['id'])) return 'Parameter "id" is required';
		return true;
	}
	public function permissionLevel() { return self::PERM_ADMIN; }
	public function execute($params, $context) {
		$ip = $_SERVER['REMOTE_ADDR'] ?? '';
		$hasSession = !empty($_SESSION['AMP_user']);
		$isLocal = in_array($ip, ['127.0.0.1', '::1', '']);
		if (!$hasSession && !$isLocal) {
			throw new \Exception('Token management requires FreePBX admin login or localhost access.');
		}
		$id = $params['id'];
		$confirm = !empty($params['confirm']) && $params['confirm'] === true;
		$db = $this->freepbx->Database;

		$sth = $db->prepare("SELECT username, CONCAT(LEFT(token, 8), '...') as preview FROM oc_api_tokens WHERE id = ?");
		$sth->execute([$id]);
		$token = $sth->fetch(\PDO::FETCH_ASSOC);
		if (!$token) throw new \Exception("Token {$id} not found");

		if (!$confirm) {
			return ['dry_run' => true, 'message' => "Would revoke API token {$token['preview']} for `{$token['username']}`."];
		}

		$sth = $db->prepare("UPDATE oc_api_tokens SET active = 0 WHERE id = ?");
		$sth->execute([$id]);

		return ['dry_run' => false, 'message' => "API token revoked for `{$token['username']}`."];
	}
}
