<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class RevokeApiToken extends AbstractTool {
	public function name() { return 'fm_revoke_api_token'; }
	public function description() { return 'Revoke an API token. Params: either id (numeric) or username. Requires confirm:true. If username matches multiple active rows, the tool errors and asks for an id.'; }
	public function validate($params) {
		if (empty($params['id']) && empty($params['username'])) return 'Parameter "id" or "username" is required';
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
		$confirm = !empty($params['confirm']) && $params['confirm'] === true;
		$db = $this->freepbx->Database;

		// Resolve username → id. Filtering by active=1 means a revoke command targeting
		// a username will skip already-revoked rows for that username — revoke is a
		// no-op on revoked rows anyway, and ignoring them lets "revoke ksbot" Just Work
		// even after a previous KSbot token was already revoked.
		$id = $params['id'] ?? null;
		if (empty($id)) {
			$sth = $db->prepare("SELECT id FROM oc_api_tokens WHERE username = ? AND active = 1");
			$sth->execute([$params['username']]);
			$ids = $sth->fetchAll(\PDO::FETCH_COLUMN);
			$userSan = $this->frogman->sanitizeForChat($params['username']);
			if (empty($ids)) {
				throw new \Exception("No active token found for username `{$userSan}`");
			}
			if (count($ids) > 1) {
				throw new \Exception("Multiple active tokens for `{$userSan}` (ids: " . implode(', ', $ids) . "). Specify by id.");
			}
			$id = (int)$ids[0];
		}

		$sth = $db->prepare("SELECT username, description FROM oc_api_tokens WHERE id = ?");
		$sth->execute([$id]);
		$token = $sth->fetch(\PDO::FETCH_ASSOC);
		if (!$token) throw new \Exception("Token {$id} not found");

		$userSan = $this->frogman->sanitizeForChat($token['username']);
		$descSan = $this->frogman->sanitizeForChat($token['description'] ?? '');
		$label = "token #{$id} (`{$userSan}`" . (!empty($token['description']) ? ", \"{$descSan}\"" : '') . ")";

		if (!$confirm) {
			return ['dry_run' => true, 'message' => "Would revoke {$label}."];
		}

		$sth = $db->prepare("UPDATE oc_api_tokens SET active = 0 WHERE id = ?");
		$sth->execute([$id]);

		return ['dry_run' => false, 'message' => "{$label} revoked."];
	}
}
