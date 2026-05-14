<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

/**
 * Delete a User Manager user.
 *
 * Useful for cleaning up orphaned Userman entries — e.g. after macro tests left
 * test users behind, or after a manual extension delete didn't tear down the
 * linked Userman side. We've written ad-hoc PHP for this twice before; this is
 * the proper BMO wrapper.
 *
 * Notes:
 *   - Only deletes the Userman row. Does NOT delete the linked extension if
 *     one exists — the dry-run preview flags that case so the operator knows.
 *   - Userman->deleteUserByID() returns a [status, type, message] array shape
 *     in FreePBX 17 (same shape as addUser).
 */
class DeleteUsermanUser extends AbstractTool {
	public function name() { return 'fm_delete_userman_user'; }
	public function description() { return 'Delete a User Manager user by id or username. Useful for cleaning up orphaned Userman entries left by macro tests or manual extension deletes. Pass exactly one of: id (numeric uid) OR username (string). Requires confirm:true.'; }

	public function validate($params) {
		$hasId       = isset($params['id']) && $params['id'] !== '';
		$hasUsername = isset($params['username']) && $params['username'] !== '';
		if (!$hasId && !$hasUsername) return 'One of "id" (numeric uid) or "username" is required';
		if ($hasId && $hasUsername)   return 'Pass only one of "id" or "username", not both';
		if ($hasId && !preg_match('/^\d+$/', (string)$params['id'])) return 'Parameter "id" must be numeric';
		if ($hasUsername && !preg_match('/^[a-zA-Z0-9_\-\.@]+$/', (string)$params['username'])) {
			return 'Parameter "username" must be alphanumeric (with _ - . @ allowed)';
		}
		return true;
	}

	public function permissionLevel() { return self::PERM_ADMIN; }

	public function execute($params, $context) {
		$confirm = !empty($params['confirm']) && $params['confirm'] === true;
		$userman = $this->freepbx->Userman;

		// Resolve via the right lookup so we have the canonical user record either way.
		$user = !empty($params['id'])
			? $userman->getUserByID((int)$params['id'])
			: $userman->getUserByUsername((string)$params['username']);

		if (empty($user) || empty($user['id'])) {
			$ident = !empty($params['id']) ? "id={$params['id']}" : "username={$params['username']}";
			throw new \Exception("User Manager user not found ({$ident})");
		}

		$uid          = (int)$user['id'];
		$username     = $user['username'] ?? '';
		$displayname  = $user['displayname'] ?? null;
		$defaultExt   = $user['default_extension'] ?? null;
		$hasExtension = $defaultExt && $defaultExt !== 'none';

		if (!$confirm) {
			$extNote = $hasExtension
				? " — linked to extension {$defaultExt} (the extension itself will NOT be deleted; use `delete extension {$defaultExt}` for that)"
				: '';
			return [
				'dry_run' => true,
				'message' => "Would delete User Manager user `{$username}` (uid {$uid}){$extNote}. Reply yes to confirm.",
				'user' => [
					'id'                => $uid,
					'username'          => $username,
					'displayname'       => $displayname,
					'default_extension' => $defaultExt,
				],
				'extension_will_remain' => $hasExtension,
			];
		}

		$res = $userman->deleteUserByID($uid);
		// Userman->deleteUserByID returns ['status' => bool, 'type' => ..., 'message' => ...].
		// Status false = failure; status true = success.
		if (is_array($res) && isset($res['status']) && !$res['status']) {
			throw new \Exception('Failed to delete user: ' . ($res['message'] ?? 'unknown error'));
		}

		return [
			'dry_run' => false,
			'message' => "User Manager user `{$username}` (uid {$uid}) deleted." . ($hasExtension ? " Extension {$defaultExt} was NOT deleted." : ''),
			'deleted' => [
				'id'                => $uid,
				'username'          => $username,
				'displayname'       => $displayname,
				'default_extension' => $defaultExt,
			],
		];
	}
}
