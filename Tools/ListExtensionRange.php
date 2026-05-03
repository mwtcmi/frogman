<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class ListExtensionRange extends AbstractTool {
	public function name() { return 'fm_list_extension_range'; }
	public function description() { return 'List extensions in a number range. Params: from (required), to (required).'; }
	public function validate($params) { if (empty($params['from']) || empty($params['to'])) return 'Parameters "from" and "to" are required';
		return true; }
	public function execute($params, $context) {
		$db = $this->freepbx->Database; $sth = $db->prepare('SELECT u.extension, u.name, d.tech FROM users u JOIN devices d ON u.extension = d.id WHERE CAST(u.extension AS UNSIGNED) BETWEEN ? AND ? ORDER BY CAST(u.extension AS UNSIGNED)'); $sth->execute([(int)$params['from'], (int)$params['to']]); $result = $sth->fetchAll(\PDO::FETCH_ASSOC); return ['count' => count($result), 'extensions' => $result, 'range' => $params['from'] . '-' . $params['to']];
	}
}
