<?php
namespace FreePBX\modules\Frogman\Tools;

require_once __DIR__ . '/AbstractTool.php';

class GetRinggroup extends AbstractTool {

	public function name() {
		return 'fm_get_ringgroup';
	}

	public function description() {
		return 'Get full details for a specific ring group by number, including member list and strategy.';
	}

	public function validate($params) {
		if (empty($params['id'])) {
			return 'Parameter "id" is required (ring group number)';
		}
		if (!preg_match('/^\d+$/', $params['id'])) {
			return 'Parameter "id" must be numeric';
		}
		return true;
	}

	public function requiredPermission() {
		return null;
	}

	public function execute($params, $context) {
		$grpnum = $params['id'];

		$group = $this->freepbx->Ringgroups->get($grpnum);
		if (empty($group)) {
			throw new \Exception("Ring group {$grpnum} not found");
		}

		// Parse member list
		$members = [];
		if (!empty($group['grplist'])) {
			$members = array_map('trim', explode('-', $group['grplist']));
		}

		$group['members'] = $members;
		$group['member_count'] = count($members);

		return $group;
	}
}
