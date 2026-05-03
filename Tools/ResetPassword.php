<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class ResetPassword extends AbstractTool {
	public function name() { return 'fm_reset_password'; }
	public function description() { return 'Reset password for an admin user. Params: username (required), password (optional, auto-generates if omitted). Requires confirm:true.'; }
	public function validate($params) {
		if (empty($params['username'])) return 'Parameter "username" is required';
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
		$confirm = !empty($params['confirm']) && $params['confirm'] === true;

		$user = $this->freepbx->Userman->getUserByUsername($username);
		if (empty($user)) throw new \Exception("User '{$username}' not found");

		if (empty($params['password'])) {
			$upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
			$lower = 'abcdefghjkmnpqrstuvwxyz';
			$digits = '23456789';
			$special = '!@#$%&*?';
			$all = $upper . $lower . $digits . $special;
			$pw = $upper[random_int(0, strlen($upper)-1)] . $lower[random_int(0, strlen($lower)-1)] . $digits[random_int(0, strlen($digits)-1)] . $special[random_int(0, strlen($special)-1)];
			for ($i = 4; $i < 16; $i++) $pw .= $all[random_int(0, strlen($all)-1)];
			$password = str_shuffle($pw);
		} else {
			$password = $params['password'];
		}

		if (!$confirm) {
			$pwDisplay = empty($params['password']) ? 'auto-generated' : 'user-provided';
			return ['dry_run' => true, 'message' => "Would reset password for `{$username}`. Password: {$pwDisplay}."];
		}

		$this->freepbx->Userman->updateUser($user['id'], $username, $username, $password);

		return [
			'dry_run' => false,
			'message' => "Password reset for `{$username}`.",
			'username' => $username,
			'password' => $password,
		];
	}
}
