<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class CreateAdmin extends AbstractTool {
	public function name() { return 'fm_create_admin'; }
	public function description() { return 'Create an admin user. Params: username (required), password (optional, auto-generates strong password if omitted), name (optional), email (optional). Requires confirm:true.'; }
	public function validate($params) {
		if (empty($params['username'])) return 'Parameter "username" is required';
		if (!preg_match('/^[a-zA-Z0-9_\-\.]+$/', $params['username'])) return 'Username must be alphanumeric (with _ - . allowed)';
		if (!empty($params['password'])) {
			$pw = $params['password'];
			if (strlen($pw) < 12) return 'Password must be at least 12 characters';
			if (!preg_match('/[A-Z]/', $pw)) return 'Password must contain at least one uppercase letter';
			if (!preg_match('/[a-z]/', $pw)) return 'Password must contain at least one lowercase letter';
			if (!preg_match('/[0-9]/', $pw)) return 'Password must contain at least one number';
			if (!preg_match('/[^a-zA-Z0-9]/', $pw)) return 'Password must contain at least one special character';
		}
		return true;
	}
	public function permissionLevel() { return self::PERM_ADMIN; }
	public function execute($params, $context) {
		$username = $params['username'];
		$name = $params['name'] ?? $username;
		$email = $params['email'] ?? '';
		$confirm = !empty($params['confirm']) && $params['confirm'] === true;

		// Check if user already exists
		$existing = $this->freepbx->Userman->getUserByUsername($username);
		if (!empty($existing)) throw new \Exception("User '{$username}' already exists");

		// Generate or use provided password
		if (empty($params['password'])) {
			$password = $this->generateStrongPassword();
		} else {
			$password = $params['password'];
		}

		if (!$confirm) {
			$pwDisplay = empty($params['password']) ? 'auto-generated' : 'user-provided';
			return ['dry_run' => true, 'message' => "Would create admin user `{$username}` ({$name}). Password: {$pwDisplay}."];
		}

		// Create user via Userman
		$uid = $this->freepbx->Userman->addUser($username, $password, 'default', [
			'displayname' => $name,
			'email' => $email,
		]);

		if (empty($uid)) throw new \Exception("Failed to create user '{$username}'");

		// Set as admin in FreePBX
		$this->freepbx->Core->addAMPUser($username, $password, 'admin,*', '*');

		// Set Frogman permission to admin
		$db = $this->freepbx->Database;
		$sth = $db->prepare("INSERT INTO oc_permissions (username, level) VALUES (?, 'admin') ON DUPLICATE KEY UPDATE level = 'admin'");
		$sth->execute([$username]);

		return [
			'dry_run' => false,
			'message' => "Admin user `{$username}` created.",
			'username' => $username,
			'password' => $password,
			'needs_reload' => true,
		];
	}

	private function generateStrongPassword($length = 16) {
		$upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
		$lower = 'abcdefghjkmnpqrstuvwxyz';
		$digits = '23456789';
		$special = '!@#$%&*?';
		$all = $upper . $lower . $digits . $special;

		// Ensure at least one of each type
		$pw = '';
		$pw .= $upper[random_int(0, strlen($upper) - 1)];
		$pw .= $lower[random_int(0, strlen($lower) - 1)];
		$pw .= $digits[random_int(0, strlen($digits) - 1)];
		$pw .= $special[random_int(0, strlen($special) - 1)];

		for ($i = 4; $i < $length; $i++) {
			$pw .= $all[random_int(0, strlen($all) - 1)];
		}

		// Shuffle so the required chars aren't always first
		$pw = str_shuffle($pw);
		return $pw;
	}
}
