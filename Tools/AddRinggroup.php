<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class AddRinggroup extends AbstractTool {
	public function name() { return 'fm_add_ringgroup'; }
	public function description() { return 'Create a new ring group. Params: grpnum (required), description (required), members (comma-separated extensions, required), strategy (optional: ringall/hunt/memoryhunt, default ringall), grptime (optional, default 20). Requires confirm:true.'; }
	public function validate($params) {
		if (empty($params['grpnum'])) return 'Parameter "grpnum" is required';
		if (empty($params['members'])) return 'Parameter "members" is required';
		return true;
	}
	public function requiredPermission() { return null; }
	public function permissionLevel() { return self::PERM_WRITE; }
	public function execute($params, $context) {
		$confirm = !empty($params['confirm']) && $params['confirm'] === true;
		$grpnum = $params['grpnum'];
		$desc = $params['description'] ?? "Ring Group {$grpnum}";
		$strategy = $params['strategy'] ?? 'ringall';
		$grptime = $params['grptime'] ?? 20;

		// Normalize the member list. ringgroups.grplist storage is dash-separated;
		// the GUI editor explodes on '-' and renders one extension per row. Accept
		// any combination of comma / space / semicolon separators from chat input
		// so "1001,1002,1003", "1001 1002 1003", "1001, 1002, 1003" all land
		// correctly in storage. Each token may end with '#' (Follow-Me / external).
		$tokens = preg_split('/[\s,;]+/', trim((string)$params['members']));
		$tokens = array_values(array_filter(array_map('trim', $tokens), 'strlen'));
		foreach ($tokens as $t) {
			if (!preg_match('/^\d+#?$/', $t)) {
				return ['error' => "Invalid member `{$t}` — expected digits, optionally suffixed with `#` for Follow-Me/external dial."];
			}
		}
		if (empty($tokens)) {
			return ['error' => 'No valid members given. Pass extensions separated by commas or spaces (e.g. "1001,1002,1003").'];
		}
		$members = implode('-', $tokens);

		if (!$confirm) {
			return ['dry_run' => true, 'message' => "Would create ring group {$grpnum} ({$desc}) with members " . implode(', ', $tokens) . ", strategy={$strategy}. Reply yes to confirm."];
		}
		$result = $this->freepbx->Ringgroups->add($grpnum, $strategy, $grptime, $members, 'app-blackhole,hangup,1', $desc, '', '0', '', '', '', '', 'Ring', '', '', 'default', '', '', 'dontcare', 'yes', '', '', 1);
		if ($result === false) throw new \Exception("Ring group {$grpnum} already exists or could not be created");
		return ['dry_run' => false, 'message' => "Ring group {$grpnum} ({$desc}) created", 'needs_reload' => true];
	}
}
