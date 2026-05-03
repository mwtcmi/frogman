<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class ListCallForwards extends AbstractTool {
	public function name() { return 'fm_list_call_forwards'; }
	public function description() { return 'List call forwarding status for all extensions.'; }
	public function validate($params) { return true; }
	public function execute($params, $context) {
		$db = $this->freepbx->Database; $astman = $this->freepbx->astman; $users = $db->query('SELECT extension, name FROM users ORDER BY extension')->fetchAll(\PDO::FETCH_ASSOC); $result = []; foreach($users as $u) { $cf = $astman ? $astman->database_get('CF', $u['extension']) : ''; if(!empty($cf)) { $result[] = ['ext' => $u['extension'], 'name' => $u['name'], 'forward_to' => $cf]; } } return ['count' => count($result), 'forwards' => $result];
	}
}
