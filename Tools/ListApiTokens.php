<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class ListApiTokens extends AbstractTool {
	public function name() { return 'fm_list_api_tokens'; }
	public function description() { return 'List all API tokens. Identify by id/username/description/created_at — raw token values are never returned (only SHA-256 hashes are stored, see GHSA-9xf5-9ghq-p6cw).'; }
	public function validate($params) { return true; }
	public function permissionLevel() { return self::PERM_ADMIN; }
	public function execute($params, $context) {
		$db = $this->freepbx->Database;
		$sth = $db->query("SELECT id, username, description, level, active, created_at, last_used_at FROM oc_api_tokens ORDER BY created_at DESC");
		$tokens = $sth->fetchAll(\PDO::FETCH_ASSOC);
		$now = time();
		foreach ($tokens as &$t) {
			$t['created_at_human'] = date('Y-m-d H:i:s', $t['created_at']);
			$t['status'] = $t['active'] ? 'active' : 'revoked';
			$lastUsed = (int)($t['last_used_at'] ?? 0);
			$t['last_used_at'] = $lastUsed;
			$t['last_used_human'] = self::relativeTime($lastUsed, $now);
			// 60-day stale threshold — flags tokens worth reviewing in the sidebar.
			// Active tokens only; revoked ones aren't relevant to staleness signals.
			$t['stale'] = ($t['active'] && $lastUsed > 0 && ($now - $lastUsed) > 60 * 86400);
			$t['never_used'] = ($t['active'] && $lastUsed === 0);
		}
		return ['count' => count($tokens), 'tokens' => $tokens];
	}

	private static function relativeTime($ts, $now) {
		if ($ts <= 0) return 'never';
		$diff = $now - $ts;
		if ($diff < 60)       return $diff . 's ago';
		if ($diff < 3600)     return floor($diff / 60) . 'm ago';
		if ($diff < 86400)    return floor($diff / 3600) . 'h ago';
		if ($diff < 86400*30) return floor($diff / 86400) . 'd ago';
		return floor($diff / 86400) . 'd ago';
	}
}
