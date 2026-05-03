<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class SearchPbx extends AbstractTool {
	public function name() { return 'fm_search'; }
	public function description() { return 'Search across extensions, ring groups, queues, IVRs, and trunks by name or number. Params: query (required).'; }
	public function validate($params) {
		if (empty($params['query'])) return 'Parameter "query" is required';
		return true;
	}
	public function execute($params, $context) {
		$q = strtolower(trim($params['query']));
		$results = [];
		$db = $this->freepbx->Database;

		// Extensions
		$sth = $db->prepare("SELECT extension, name FROM users WHERE LOWER(name) LIKE ? OR extension LIKE ?");
		$sth->execute(["%{$q}%", "%{$q}%"]);
		foreach ($sth->fetchAll(\PDO::FETCH_ASSOC) as $row) {
			$results[] = ['type' => 'Extension', 'id' => $row['extension'], 'name' => $row['name']];
		}

		// Ring Groups
		$sth = $db->prepare("SELECT grpnum, description FROM ringgroups WHERE LOWER(description) LIKE ? OR grpnum LIKE ?");
		$sth->execute(["%{$q}%", "%{$q}%"]);
		foreach ($sth->fetchAll(\PDO::FETCH_ASSOC) as $row) {
			$results[] = ['type' => 'Ring Group', 'id' => $row['grpnum'], 'name' => $row['description']];
		}

		// Queues
		$sth = $db->prepare("SELECT extension, descr FROM queues_config WHERE LOWER(descr) LIKE ? OR extension LIKE ?");
		$sth->execute(["%{$q}%", "%{$q}%"]);
		foreach ($sth->fetchAll(\PDO::FETCH_ASSOC) as $row) {
			$results[] = ['type' => 'Queue', 'id' => $row['extension'], 'name' => $row['descr']];
		}

		// IVRs
		try {
			$ivrs = $this->freepbx->Ivr->getAllDetails();
			if (!empty($ivrs)) {
				foreach ($ivrs as $ivrId => $ivrData) {
					$ivr = is_array($ivrData) && isset($ivrData[0]) ? $ivrData[0] : $ivrData;
					$name = $ivr['name'] ?? '';
					$desc = $ivr['description'] ?? '';
					$id = $ivr['id'] ?? $ivrId;
					if (stripos($name, $q) !== false || stripos($desc, $q) !== false || strpos((string)$id, $q) !== false) {
						$results[] = ['type' => 'IVR', 'id' => $id, 'name' => $name];
					}
				}
			}
		} catch (\Exception $e) {}

		// Trunks
		$sth = $db->prepare("SELECT trunkid, name FROM trunks WHERE LOWER(name) LIKE ? OR trunkid LIKE ?");
		$sth->execute(["%{$q}%", "%{$q}%"]);
		foreach ($sth->fetchAll(\PDO::FETCH_ASSOC) as $row) {
			$results[] = ['type' => 'Trunk', 'id' => $row['trunkid'], 'name' => $row['name']];
		}

		return ['query' => $params['query'], 'count' => count($results), 'results' => $results];
	}
}
