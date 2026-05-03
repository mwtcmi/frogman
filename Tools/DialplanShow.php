<?php
namespace FreePBX\modules\Frogman\Tools;

require_once __DIR__ . '/AbstractTool.php';
require_once dirname(__DIR__) . '/Dialplan/DialplanFile.php';

class DialplanShow extends AbstractTool {
	public function name() { return 'fm_dialplan_show'; }
	public function description() { return 'List all custom dialplan contexts in extensions_custom.conf.'; }
	public function validate($params) { return true; }
	public function requiredPermission() { return null; }

	public function execute($params, $context) {
		$contexts = \FreePBX\modules\Frogman\Dialplan\DialplanFile::parse();
		$result = [];
		foreach ($contexts as $name => $ctx) {
			$result[] = [
				'context' => $name,
				'lines' => count($ctx['lines']),
				'comment' => $ctx['comment'] ?: null,
			];
		}
		return ['count' => count($result), 'contexts' => $result];
	}
}
