<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class SetCallForward extends AbstractTool {
	public function name() { return 'fm_set_call_forward'; }
	public function description() { return 'Set call forwarding for an extension. Params: ext (required), number (required), type (optional: CF/CFB/CFU, default CF). Requires confirm:true.'; }
	public function validate($params) {
		if (empty($params['ext'])) return 'Parameter "ext" is required';
		if (empty($params['number'])) return 'Parameter "number" is required';
		return true;
	}
	public function requiredPermission() { return null; }
	public function permissionLevel() { return self::PERM_WRITE; }
	public function execute($params, $context) {
		$ext = $params['ext'];
		$number = $params['number'];
		$type = strtoupper($params['type'] ?? 'CF');
		$confirm = !empty($params['confirm']) && $params['confirm'] === true;
		$typeNames = ['CF' => 'all calls', 'CFB' => 'when busy', 'CFU' => 'when unavailable'];
		$typeName = $typeNames[$type] ?? $type;
		if (!$confirm) {
			return ['dry_run' => true, 'message' => "Would forward {$ext} ({$typeName}) to {$number}. Reply yes to confirm."];
		}
		$this->freepbx->Callforward->setNumberByExtension($ext, $number, $type);
		return ['dry_run' => false, 'message' => "Extension {$ext} now forwards ({$typeName}) to {$number}.", 'needs_reload' => true];
	}
}
