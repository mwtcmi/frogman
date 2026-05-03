<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class GetBusiestExtensions extends AbstractTool {
	public function name() { return 'fm_get_busiest_extensions'; }
	public function description() { return 'Get the busiest extensions by call count. Optional: limit (default 10), date_from, date_to.'; }
	public function validate($params) { return true; }
	public function execute($params, $context) {
		$db = $this->freepbx->Database; $limit = min((int)($params['limit'] ?? 10), 50); $where = ''; $binds = []; if(!empty($params['date_from'])) { $where .= ' AND calldate >= ?'; $binds[] = $params['date_from']; } if(!empty($params['date_to'])) { $where .= ' AND calldate <= ?'; $binds[] = $params['date_to']; } $sth = $db->prepare('SELECT src as extension, COUNT(*) as calls, AVG(duration) as avg_duration FROM asteriskcdrdb.cdr WHERE src != "" AND src REGEXP "^[0-9]+$"' . $where . ' GROUP BY src ORDER BY calls DESC LIMIT ' . $limit); $sth->execute($binds); return ['extensions' => $sth->fetchAll(\PDO::FETCH_ASSOC)];
	}
}
