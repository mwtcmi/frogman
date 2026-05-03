<?php
namespace FreePBX\modules\Frogman\Tools;

require_once __DIR__ . '/AbstractTool.php';
require_once dirname(__DIR__) . '/Dialplan/DialplanFile.php';
require_once dirname(__DIR__) . '/Dialplan/Templates.php';

class DialplanApply extends AbstractTool {
	public function name() { return 'fm_dialplan_apply'; }
	public function description() { return 'Generate and apply a dialplan template. Params: template (required), plus template-specific params. Requires confirm:true.'; }

	public function validate($params) {
		if (empty($params['template'])) return 'Parameter "template" is required';
		$t = \FreePBX\modules\Frogman\Dialplan\TemplateRegistry::get($params['template']);
		if (!$t) {
			$ids = array_keys(\FreePBX\modules\Frogman\Dialplan\TemplateRegistry::all());
			return "Unknown template '{$params['template']}'. Available: " . implode(', ', $ids);
		}
		return true;
	}

	public function requiredPermission() { return null; }

	public function permissionLevel() { return self::PERM_WRITE; }
	public function execute($params, $context) {
		$templateId = $params['template'];
		$confirm = !empty($params['confirm']) && $params['confirm'] === true;
		unset($params['template'], $params['confirm']);

		$template = \FreePBX\modules\Frogman\Dialplan\TemplateRegistry::get($templateId);
		$df = \FreePBX\modules\Frogman\Dialplan\DialplanFile::class;

		// Generate the dialplan block
		$block = $template->generate($params);

		// Extract context name from the generated block
		$contextName = null;
		if (preg_match('/^\[([^\]]+)\]/m', $block, $m)) {
			$contextName = $m[1];
		}

		if (!$confirm) {
			return [
				'dry_run' => true,
				'message' => "Would add this to extensions_custom.conf. Reply yes to confirm.",
				'context' => $contextName,
				'dialplan' => $block,
			];
		}

		// Check for conflict
		if ($contextName && $df::contextExists($contextName)) {
			// Remove old version first
			$df::backup();
			$df::removeContext($contextName);
		} else {
			$df::backup();
		}

		// Write and reload
		$df::appendContext($block);
		$df::reloadDialplan();

		// Validate
		$validation = null;
		if ($contextName) {
			$validation = $df::validateDialplan($contextName);
		}

		return [
			'dry_run' => false,
			'message' => "Dialplan written and reloaded.",
			'context' => $contextName,
			'template' => $templateId,
			'validation' => $validation,
		];
	}
}
