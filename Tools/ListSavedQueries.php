<?php
namespace FreePBX\modules\Frogman\Tools;

require_once __DIR__ . '/AbstractTool.php';

class ListSavedQueries extends AbstractTool {

	public function name() {
		return 'fm_list_saved_queries';
	}

	public function description() {
		return 'List all saved GraphQL queries with their names and parameter specs.';
	}

	public function validate($params) {
		return true;
	}

	public function requiredPermission() {
		return null;
	}

	public function execute($params, $context) {
		$db = $this->freepbx->Database;
		$sth = $db->query("SELECT id, name, query, param_spec, created_by, created_at FROM oc_saved_queries ORDER BY name");
		$rows = $sth->fetchAll(\PDO::FETCH_ASSOC);

		foreach ($rows as &$row) {
			$row['created_at_human'] = date('Y-m-d H:i:s', $row['created_at']);
		}
		unset($row);

		return [
			'count' => count($rows),
			'queries' => $rows,
		];
	}
}
