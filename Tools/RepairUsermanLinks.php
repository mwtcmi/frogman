<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class RepairUsermanLinks extends AbstractTool {
	public function name() { return 'fm_repair_userman_links'; }
	public function description() {
		return 'Repair User Manager users that are missing default-group membership or extension assignment (a state that prevents UCP login). Idempotent — already-correct users are skipped. Params: ext (optional, repair only this extension), userid (optional, repair only this userman user ID); default = scan all users with a default_extension. Requires confirm:true.';
	}
	public function validate($params) {
		if (!empty($params['ext']) && !preg_match('/^\d+$/', (string)$params['ext'])) {
			return 'Parameter "ext" must be numeric';
		}
		if (!empty($params['userid']) && !is_numeric($params['userid'])) {
			return 'Parameter "userid" must be numeric';
		}
		return true;
	}
	public function permissionLevel() { return self::PERM_WRITE; }

	public function execute($params, $context) {
		$confirm = !empty($params['confirm']) && $params['confirm'] === true;
		$userman = $this->freepbx->Userman;

		// Resolve target users
		if (!empty($params['userid'])) {
			$u = $userman->getUserByID((int)$params['userid']);
			$targets = !empty($u) ? [$u] : [];
		} elseif (!empty($params['ext'])) {
			$u = $userman->getUserByDefaultExtension($params['ext']);
			$targets = !empty($u) ? [$u] : [];
		} else {
			$targets = $userman->getAllUsers();
		}

		$directory = $userman->getDefaultDirectory();
		$defaultGroupIds = $userman->getDefaultGroups($directory['id'] ?? null);
		$defaultGroupIds = is_array($defaultGroupIds) ? $defaultGroupIds : [];
		$allGroups = $userman->getAllGroups();

		$report = [];
		foreach ($targets as $u) {
			$uid = (int)$u['id'];
			$ext = $u['default_extension'] ?? '';
			if (!$ext || $ext === 'none') continue;

			$missingGroups = [];
			foreach ($allGroups as $g) {
				if (!in_array($g['id'], $defaultGroupIds)) continue;
				$members = is_array($g['users'] ?? null) ? $g['users'] : [];
				$members = array_map('intval', $members);
				if (!in_array($uid, $members, true)) {
					$missingGroups[] = $g;
				}
			}

			$assigned = $userman->getGlobalSettingByID($uid, 'assigned');
			$assignedList = is_array($assigned) ? array_map('strval', $assigned) : [];
			$needsAssigned = !in_array((string)$ext, $assignedList, true);

			if (empty($missingGroups) && !$needsAssigned) continue;

			$report[] = [
				'uid' => $uid,
				'username' => $u['username'],
				'ext' => (string)$ext,
				'missing_group_ids' => array_column($missingGroups, 'id'),
				'missing_group_names' => array_column($missingGroups, 'groupname'),
				'needs_assigned' => $needsAssigned,
			];
		}

		if (empty($report)) {
			// Preserve the dry_run contract: dry_run reflects whether the caller passed
			// confirm:true, NOT whether a write happened. A nothing-to-repair early exit
			// shouldn't report dry_run:false on a preview call — callers using dry_run to
			// distinguish "would have done" vs "did" would get the wrong signal.
			return [
				'dry_run' => !$confirm,
				'message' => 'All User Manager users with extensions are already correctly wired. Nothing to repair.',
				'repaired' => 0,
				'total' => 0,
				'noop' => true,
			];
		}

		if (!$confirm) {
			$lines = ["Would repair " . count($report) . " User Manager user(s):"];
			foreach ($report as $r) {
				$bits = [];
				if (!empty($r['missing_group_names'])) $bits[] = 'add to ' . implode(', ', $r['missing_group_names']);
				if ($r['needs_assigned']) $bits[] = "set assigned=[{$r['ext']}]";
				$lines[] = "  - {$r['username']} (uid={$r['uid']}, ext={$r['ext']}): " . implode('; ', $bits);
			}
			return [
				'dry_run' => true,
				'message' => implode("\n", $lines) . "\n\nPass confirm:true to apply.",
				'preview' => $report,
			];
		}

		// Apply repairs. Group membership requires re-fetching state per iteration since
		// updateGroup() persists the user list and the next user joining the same group
		// must see the prior addition.
		$repaired = 0;
		$errors = [];
		foreach ($report as $r) {
			try {
				if ($r['needs_assigned']) {
					$userman->setGlobalSettingByID($r['uid'], 'assigned', [$r['ext']]);
				}
				if (!empty($r['missing_group_ids'])) {
					$freshGroups = $userman->getAllGroups();
					foreach ($freshGroups as $g) {
						if (!in_array($g['id'], $r['missing_group_ids'])) continue;
						$members = is_array($g['users'] ?? null) ? $g['users'] : [];
						$members = array_map('intval', $members);
						if (in_array($r['uid'], $members, true)) continue;
						$members[] = $r['uid'];
						$userman->updateGroup(
							$g['id'],
							$g['groupname'],
							$g['groupname'],
							$g['description'],
							$members
						);
					}
				}
				$repaired++;
			} catch (\Throwable $e) {
				$errors[] = "uid={$r['uid']}: " . $e->getMessage();
			}
		}

		$msg = "Repaired {$repaired} of " . count($report) . " User Manager user(s).";
		if (!empty($errors)) $msg .= " Errors: " . implode('; ', $errors);

		return [
			'dry_run' => false,
			'message' => $msg,
			'repaired' => $repaired,
			'total' => count($report),
			'errors' => $errors,
		];
	}
}
