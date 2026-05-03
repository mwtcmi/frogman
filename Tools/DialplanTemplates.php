<?php
namespace FreePBX\modules\Frogman\Tools;

require_once __DIR__ . '/AbstractTool.php';
require_once dirname(__DIR__) . '/Dialplan/Templates.php';

class DialplanTemplates extends AbstractTool {
	public function name() { return 'fm_dialplan_templates'; }
	public function description() { return 'List available dialplan templates.'; }
	public function validate($params) { return true; }
	public function requiredPermission() { return null; }

	public function execute($params, $context) {
		return [
			'templates' => \FreePBX\modules\Frogman\Dialplan\TemplateRegistry::listTemplates(),
		];
	}
}
