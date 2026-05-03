<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class AddTimecondition extends AbstractTool {
	public function name() { return 'fm_add_time_condition'; }
	public function description() { return 'Create a time condition. Params: name (required), timegroup (time group ID, required), truegoto (destination when matched, required), falsegoto (destination when not matched, required). Requires confirm:true.'; }
	public function validate($params) {
		if (empty($params['name'])) return 'Parameter "name" is required';
		if (empty($params['truegoto'])) return 'Parameter "truegoto" is required (destination when time matches)';
		if (empty($params['falsegoto'])) return 'Parameter "falsegoto" is required (destination when time does not match)';
		return true;
	}
	public function permissionLevel() { return self::PERM_WRITE; }
	public function execute($params, $context) {
		$confirm = !empty($params['confirm']) && $params['confirm'] === true;
		$name = $params['name'];
		if (!$confirm) {
			return ['dry_run' => true, 'message' => "Would create time condition \"{$name}\": matched → {$params['truegoto']}, unmatched → {$params['falsegoto']}. Reply yes to confirm."];
		}

		$post = [
			'displayname' => $name,
			'time' => $params['timegroup'] ?? 0,
			'timezone' => $params['timezone'] ?? '',
			'goto0' => 'goto', 'goto0goto' => $params['truegoto'],
			'goto1' => 'goto', 'goto1goto' => $params['falsegoto'],
			'invert_hint' => '0',
			'fcc_password' => '',
			'deptname' => '',
			'mode' => 'time-group',
			'calendar-id' => '',
			'calendar-group' => '',
		];

		$id = $this->freepbx->Timeconditions->addTimeCondition($post);
		return ['dry_run' => false, 'message' => "Time condition \"{$name}\" created (ID: {$id})", 'id' => $id, 'needs_reload' => true];
	}
}
