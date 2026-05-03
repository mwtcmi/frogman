<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class AsteriskDbGet extends AbstractTool {
	public function name() { return 'fm_astdb_get'; }
	public function description() { return 'Read a value from the Asterisk database. Params: family (required), key (required).'; }
	public function validate($params) {
		if (empty($params['family'])) return 'Parameter "family" is required';
		if (empty($params['key'])) return 'Parameter "key" is required';
		return true;
	}
	public function execute($params, $context) {
		$astman = $this->freepbx->astman;
		if (!$astman || !$astman->connected()) throw new \Exception('Cannot connect to Asterisk Manager');
		$value = $astman->database_get($params['family'], $params['key']);
		return ['family' => $params['family'], 'key' => $params['key'], 'value' => $value];
	}
}
