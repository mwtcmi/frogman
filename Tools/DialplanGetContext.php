<?php
namespace FreePBX\modules\Frogman\Tools;

require_once __DIR__ . '/AbstractTool.php';
require_once dirname(__DIR__) . '/Dialplan/DialplanFile.php';

class DialplanGetContext extends AbstractTool {
	public function name() { return 'fm_dialplan_get_context'; }
	public function description() { return 'Show the contents of a specific custom dialplan context. Params: name (required).'; }
	public function validate($params) {
		if (empty($params['name'])) return 'Parameter "name" is required';
		return true;
	}
	public function requiredPermission() { return null; }

	public function execute($params, $context) {
		$ctx = \FreePBX\modules\Frogman\Dialplan\DialplanFile::getContext($params['name']);
		if (!$ctx) throw new \Exception("Context '{$params['name']}' not found");
		return $ctx;
	}
}
