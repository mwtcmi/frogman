<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class GetCdrStats extends AbstractTool {
	public function name() { return 'fm_get_cdr_stats'; }
	public function description() { return 'Get CDR statistics — call counts by disposition, avg duration. Optional: date_from, date_to.'; }
	public function validate($params) { return true; }
	public function execute($params, $context) {
		$db = $this->freepbx->Database; $where = ''; $binds = []; if(!empty($params['date_from'])) { $where .= ' AND calldate >= ?'; $binds[] = $params['date_from']; } if(!empty($params['date_to'])) { $where .= ' AND calldate <= ?'; $binds[] = $params['date_to']; } $sth = $db->prepare('SELECT disposition, COUNT(*) as count, AVG(duration) as avg_duration, AVG(billsec) as avg_billsec, SUM(billsec) as total_billsec FROM asteriskcdrdb.cdr WHERE 1=1' . $where . ' GROUP BY disposition'); $sth->execute($binds); $stats = $sth->fetchAll(\PDO::FETCH_ASSOC); $total = $db->prepare('SELECT COUNT(*) as total FROM asteriskcdrdb.cdr WHERE 1=1' . $where); $total->execute($binds); $t = $total->fetch(\PDO::FETCH_ASSOC); return ['total_calls' => (int)$t['total'], 'by_disposition' => $stats];
	}
}
