<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class ListUsers extends AbstractTool {
	public function name() { return 'fm_list_users'; }
	public function description() { return 'List all User Manager users.'; }
	public function validate($params) { return true; }
	public function execute($params, $context) {
		$users = $this->freepbx->Userman->getAllUsers(); $result = []; foreach($users as $u) { $result[] = ['id' => $u['id'] ?? '', 'username' => $u['username'] ?? '', 'name' => $u['displayname'] ?? '', 'email' => $u['email'] ?? '']; } return ['count' => count($result), 'users' => $result];
	}
}
