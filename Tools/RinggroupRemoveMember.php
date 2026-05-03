<?php
namespace FreePBX\modules\Frogman\Tools;

require_once __DIR__ . '/AbstractTool.php';

class RinggroupRemoveMember extends AbstractTool {

	public function name() {
		return 'fm_ringgroup_remove_member';
	}

	public function description() {
		return 'Remove a member from a ring group. Params: id (ring group number), member (ext or number). Requires confirm:true.';
	}

	public function validate($params) {
		if (empty($params['id'])) {
			return 'Parameter "id" is required (ring group number)';
		}
		if (!preg_match('/^\d+$/', $params['id'])) {
			return 'Parameter "id" must be numeric';
		}
		if (empty($params['member'])) {
			return 'Parameter "member" is required';
		}
		return true;
	}

	public function requiredPermission() {
		return 'write:extension';
	}

	public function permissionLevel() { return self::PERM_WRITE; }
	public function execute($params, $context) {
		$grpnum = $params['id'];
		$member = $params['member'];
		$confirm = !empty($params['confirm']) && $params['confirm'] === true;

		$group = $this->freepbx->Ringgroups->get($grpnum);
		if (empty($group)) {
			throw new \Exception("Ring group {$grpnum} not found");
		}

		$currentMembers = !empty($group['grplist']) ? array_map('trim', explode('-', $group['grplist'])) : [];

		// Find the member to remove
		$cleanMember = preg_replace('/[^0-9*+#]/', '', $member);
		$found = false;
		$newMembers = [];
		foreach ($currentMembers as $m) {
			$cleanExisting = preg_replace('/[^0-9*+#]/', '', $m);
			if ($cleanExisting === $cleanMember && !$found) {
				$found = true;
				continue;
			}
			$newMembers[] = $m;
		}

		if (!$found) {
			throw new \Exception("{$member} is not a member of ring group {$grpnum}");
		}

		if (!$confirm) {
			return [
				'dry_run' => true,
				'message' => "Would remove {$member} from ring group {$grpnum} ({$group['description']}). Remaining members: " . implode(', ', $newMembers) . ". Pass confirm:true to execute.",
				'preview' => [
					'ringgroup' => $grpnum,
					'description' => $group['description'],
					'current_members' => $currentMembers,
					'new_members' => $newMembers,
				],
			];
		}

		$newList = implode('-', $newMembers);
		$this->freepbx->Ringgroups->updateExtensionLists($grpnum, $newList);

		return [
			'dry_run' => false,
			'message' => "Removed {$member} from ring group {$grpnum}",
			'members' => $newMembers,
			'needs_reload' => true,
		];
	}
}
