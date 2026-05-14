<?php
namespace FreePBX\modules\Frogman\Tools;

require_once __DIR__ . '/AbstractTool.php';

class RinggroupAddMember extends AbstractTool {

	public function name() {
		return 'fm_ringgroup_add_member';
	}

	public function description() {
		return 'Add a member (extension or external number) to a ring group. Params: id (ring group number), member (ext or number). Requires confirm:true.';
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
		if (!preg_match('/^[\d*+#]+$/', $params['member'])) {
			return 'Parameter "member" must be a valid extension or phone number';
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

		// Check if already a member. Preserve the dry_run contract on no-op early exit:
		// dry_run reflects whether confirm was passed, NOT whether a write happened —
		// otherwise a preview call gets dry_run:false even though nothing ran.
		$cleanMember = preg_replace('/[^0-9*+#]/', '', $member);
		foreach ($currentMembers as $m) {
			$cleanExisting = preg_replace('/[^0-9*+#]/', '', $m);
			if ($cleanExisting === $cleanMember) {
				return [
					'dry_run' => !$confirm,
					'message' => "{$member} is already a member of ring group {$grpnum}",
					'noop' => true,
				];
			}
		}

		$newMembers = array_merge($currentMembers, [$member]);
		$newList = implode('-', $newMembers);

		if (!$confirm) {
			return [
				'dry_run' => true,
				'message' => "Would add {$member} to ring group {$grpnum} ({$group['description']}). Current members: " . implode(', ', $currentMembers) . ". Pass confirm:true to execute.",
				'preview' => [
					'ringgroup' => $grpnum,
					'description' => $group['description'],
					'current_members' => $currentMembers,
					'new_members' => $newMembers,
				],
			];
		}

		$this->freepbx->Ringgroups->updateExtensionLists($grpnum, $newList);

		return [
			'dry_run' => false,
			'message' => "Added {$member} to ring group {$grpnum}",
			'members' => $newMembers,
			'needs_reload' => true,
		];
	}
}
