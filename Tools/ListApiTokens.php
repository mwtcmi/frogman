<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class ListApiTokens extends AbstractTool {
	public function name() { return 'fm_list_api_tokens'; }
	public function description() { return 'List all API tokens (tokens are masked).'; }
	public function validate($params) { return true; }
	public function permissionLevel() { return self::PERM_ADMIN; }
	public function execute($params, $context) {
		$db = $this->freepbx->Database;
		$sth = $db->query("SELECT id, username, CONCAT(LEFT(token, 8), '...') as token_preview, description, level, active, created_at FROM oc_api_tokens ORDER BY created_at DESC");
		$tokens = $sth->fetchAll(\PDO::FETCH_ASSOC);
		foreach ($tokens as &$t) {
			$t['created_at_human'] = date('Y-m-d H:i:s', $t['created_at']);
			$t['status'] = $t['active'] ? 'active' : 'revoked';
		}
		return ['count' => count($tokens), 'tokens' => $tokens];
	}
}
