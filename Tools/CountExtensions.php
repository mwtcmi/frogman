<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class CountExtensions extends AbstractTool {
	public function name() { return 'fm_count_extensions'; }
	public function description() { return 'Count extensions grouped by technology type.'; }
	public function validate($params) { return true; }
	public function execute($params, $context) {
		$db = $this->freepbx->Database; $sth = $db->query('SELECT tech, COUNT(*) as count FROM devices GROUP BY tech'); $result = $sth->fetchAll(\PDO::FETCH_ASSOC); $total = array_sum(array_column($result, 'count')); return ['total' => $total, 'by_tech' => $result];
	}
}
