<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class CreateApiToken extends AbstractTool {
	public function name() { return 'fm_create_api_token'; }
	public function description() { return 'Generate an API token for remote access. Params: username (required), description (optional), level (read/write/admin, default read). Requires confirm:true.'; }
	public function validate($params) {
		if (empty($params['username'])) return 'Parameter "username" is required';
		if (!empty($params['level']) && !in_array($params['level'], ['read', 'write', 'admin'])) {
			return 'Parameter "level" must be read, write, or admin';
		}
		return true;
	}
	public function permissionLevel() { return self::PERM_ADMIN; }
	public function execute($params, $context) {
		// Token management requires FreePBX session or localhost — prevent remote bot escalation
		$ip = $_SERVER['REMOTE_ADDR'] ?? '';
		$hasSession = !empty($_SESSION['AMP_user']);
		$isLocal = in_array($ip, ['127.0.0.1', '::1', '']);
		if (!$hasSession && !$isLocal) {
			throw new \Exception('Token management requires FreePBX admin login or localhost access.');
		}
		$username = $params['username'];
		$description = $params['description'] ?? '';
		$level = $params['level'] ?? 'read';
		$confirm = !empty($params['confirm']) && $params['confirm'] === true;

		if (!$confirm) {
			$userSan = $this->frogman->sanitizeForChat($username);
			$levelSan = $this->frogman->sanitizeForChat($level);
			return ['dry_run' => true, 'message' => "Would create API token for `{$userSan}` with `{$levelSan}` access."];
		}

		// GHSA-9xf5-9ghq-p6cw — store `sha256$<hash>`; return the raw token to the
		// caller once. A DB leak surfaces hashes, not reusable tokens. The prefix
		// makes the row self-describing for the install.php idempotent migration.
		$token = bin2hex(random_bytes(32));
		$tokenStored = 'sha256$' . hash('sha256', $token);
		$db = $this->freepbx->Database;
		$sth = $db->prepare("INSERT INTO oc_api_tokens (username, token, description, level, active, created_at) VALUES (?, ?, ?, ?, 1, ?)");
		$sth->execute([$username, $tokenStored, $description, $level, time()]);

		$userSan = $this->frogman->sanitizeForChat($username);
		return [
			'dry_run' => false,
			'message' => "API token created for `{$userSan}`.",
			'token' => $token,
			'level' => $level,
			'note' => 'Save this token — it cannot be retrieved again. Use header: X-Frogman-Token',
		];
	}
}
