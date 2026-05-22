<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class DeleteApiToken extends AbstractTool {
	public function name() { return 'fm_delete_api_token'; }
	public function description() { return 'Permanently delete an API token. Params: either id (numeric) or username. Requires confirm:true. If username matches multiple rows, the tool errors and asks for an id.'; }
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

		// Resolve username → id. Unlike revoke (which only considers active rows),
		// delete considers active+revoked together because the common case is
		// "remove this revoked row from the list." Multiple matches still error and
		// demand an id — too easy to nuke the wrong row otherwise.
		$id = $params['id'] ?? null;
		if (empty($id)) {
			$sth = $db->prepare("SELECT id, active FROM oc_api_tokens WHERE username = ?");
			$sth->execute([$params['username']]);
			$rows = $sth->fetchAll(\PDO::FETCH_ASSOC);
			$userSan = $this->frogman->sanitizeForChat($params['username']);
			if (empty($rows)) {
				throw new \Exception("No token found for username `{$userSan}`");
			}
			if (count($rows) > 1) {
				$labels = array_map(function($r) { return $r['id'] . ($r['active'] ? '' : ' (revoked)'); }, $rows);
				throw new \Exception("Multiple tokens for `{$userSan}`: " . implode(', ', $labels) . ". Specify by id.");
			}
			$id = (int)$rows[0]['id'];
		}

		$sth = $db->prepare("SELECT username, description, active FROM oc_api_tokens WHERE id = ?");
		$sth->execute([$id]);
		$token = $sth->fetch(\PDO::FETCH_ASSOC);
		if (!$token) throw new \Exception("Token {$id} not found");

		$userSan = $this->frogman->sanitizeForChat($token['username']);
		$descSan = $this->frogman->sanitizeForChat($token['description'] ?? '');
		$label = "token #{$id} (`{$userSan}`" . (!empty($token['description']) ? ", \"{$descSan}\"" : '') . ")";

		if (!$confirm) {
			$status = $token['active'] ? 'active' : 'revoked';
			return ['dry_run' => true, 'message' => "Would permanently delete {$label} ({$status})."];
		}

		$sth = $db->prepare("DELETE FROM oc_api_tokens WHERE id = ?");
		$sth->execute([$id]);

		return ['dry_run' => false, 'message' => "{$label} permanently deleted."];
	}
}
