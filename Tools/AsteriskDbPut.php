<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class AsteriskDbPut extends AbstractTool {
	public function name() { return 'fm_astdb_put'; }
	public function description() { return 'Write a value to the Asterisk database. Params: family (required), key (required), value (required). Requires confirm:true.'; }
	public function validate($params) {
		if (empty($params['family'])) return 'Parameter "family" is required';
		if (empty($params['key'])) return 'Parameter "key" is required';
		if (!isset($params['value'])) return 'Parameter "value" is required';
		return true;
	}
	public function permissionLevel() { return self::PERM_ADMIN; }
	public function execute($params, $context) {
		$confirm = !empty($params['confirm']) && $params['confirm'] === true;
		if (!$confirm) return ['dry_run' => true, 'message' => "Would set AstDB {$params['family']}/{$params['key']} = {$params['value']}. Reply yes to confirm."];
		$astman = $this->freepbx->astman;
		if (!$astman || !$astman->connected()) throw new \Exception('Cannot connect to Asterisk Manager');
		$astman->database_put($params['family'], $params['key'], $params['value']);
		return ['dry_run' => false, 'message' => "AstDB set: {$params['family']}/{$params['key']} = {$params['value']}"];
	}
}
