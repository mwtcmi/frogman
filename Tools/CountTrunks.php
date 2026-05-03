<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class CountTrunks extends AbstractTool {
	public function name() { return 'fm_count_trunks'; }
	public function description() { return 'Count trunks grouped by technology and status.'; }
	public function validate($params) { return true; }
	public function execute($params, $context) {
		$db = $this->freepbx->Database; $sth = $db->query('SELECT tech, disabled, COUNT(*) as count FROM trunks GROUP BY tech, disabled'); $result = $sth->fetchAll(\PDO::FETCH_ASSOC); return ['trunks' => $result];
	}
}
