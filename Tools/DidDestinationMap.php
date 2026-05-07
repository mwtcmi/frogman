<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class DidDestinationMap extends AbstractTool {
	public function name() { return 'fm_did_destination_map'; }
	public function description() { return 'Map every inbound DID to its first-hop destination as a Mermaid flowchart. Useful for "where do all my DIDs go" audits. Optional: filter (substring match on DID or description), to (substring match on destination label).'; }

	public function validate($params) { return true; }

	public function execute($params, $context) {
		$db = $this->freepbx->Database;
		$filter = trim($params['filter'] ?? '');
		$toFilter = strtolower(trim($params['to'] ?? ''));

		$rows = $db->query("SELECT extension, cidnum, destination, description FROM incoming ORDER BY extension")->fetchAll(\PDO::FETCH_ASSOC);

		$dids = [];
		$dests = [];      // dedup map: signature => ['id'=>..., 'label'=>..., 'type'=>...]
		$edges = [];
		$summary = ['extension'=>0, 'ringgroup'=>0, 'ivr'=>0, 'queue'=>0, 'voicemail'=>0, 'timecondition'=>0, 'announcement'=>0, 'terminate'=>0, 'unknown'=>0];
		$destId = 0;

		foreach ($rows as $r) {
			$did = trim($r['extension']);
			if ($did === '' || $did === 'ANY') $did = 'any';
			$desc = trim($r['description']);

			// Optional filter
			if ($filter !== '') {
				$blob = strtolower($did . ' ' . $desc);
				if (strpos($blob, strtolower($filter)) === false) continue;
			}

			$resolved = $this->describeDestination($r['destination'], $db);
			if ($toFilter !== '' && strpos(strtolower($resolved['label']), $toFilter) === false) continue;

			// Dedup destinations by (type, exten/key) so multiple DIDs → same RG share one node.
			$sig = $resolved['type'] . '::' . $resolved['key'];
			if (!isset($dests[$sig])) {
				$dests[$sig] = [
					'id' => 'd' . $destId++,
					'label' => $resolved['label'],
					'type' => $resolved['type'],
				];
			}

			$didLabel = $desc !== '' ? "{$did}\\n{$desc}" : $did;
			$didKey = 'did:' . $did;
			if (!isset($dids[$didKey])) {
				$dids[$didKey] = [
					'id' => 'i' . count($dids),
					'label' => $didLabel,
				];
			}

			$edges[] = ['from' => $dids[$didKey]['id'], 'to' => $dests[$sig]['id']];
			$summary[$resolved['type']] = ($summary[$resolved['type']] ?? 0) + 1;
		}

		// Build Mermaid flowchart LR
		$mer  = "flowchart LR\n";
		foreach ($dids as $d) {
			$mer .= "    {$d['id']}([\"📞 {$d['label']}\"])\n";
		}
		$shapeFor = [
			'extension'     => ['[',  ']',  ':::ext'],
			'ringgroup'     => ['{{', '}}', ':::rg'],
			'queue'         => ['[/', '/]', ':::queue'],
			'ivr'           => ['(',  ')',  ':::ivr'],
			'voicemail'     => ['[\\','/]', ':::vm'],
			'timecondition' => ['{',  '}',  ':::tc'],
			'announcement'  => ['(',  ')',  ':::ann'],
			'terminate'     => ['((', '))', ':::term'],
			'unknown'       => ['[',  ']',  ':::unknown'],
		];
		foreach ($dests as $d) {
			$shape = $shapeFor[$d['type']] ?? ['[', ']', ''];
			$mer .= "    {$d['id']}{$shape[0]}\"{$d['label']}\"{$shape[1]}{$shape[2]}\n";
		}
		foreach ($edges as $e) {
			$mer .= "    {$e['from']} --> {$e['to']}\n";
		}
		$mer .= "    classDef ext fill:#fff,stroke:#009d9d,color:#0f5a59\n";
		$mer .= "    classDef rg fill:#e8f4f4,stroke:#009d9d,color:#0f5a59\n";
		$mer .= "    classDef queue fill:#d0ebeb,stroke:#0f5a59,color:#0f5a59\n";
		$mer .= "    classDef ivr fill:#f3a25e,stroke:#c77a30,color:#fff\n";
		$mer .= "    classDef vm fill:#f0e6f4,stroke:#7a4b8a,color:#3d2348\n";
		$mer .= "    classDef tc fill:#e6f0e6,stroke:#5a8a5a,color:#234823\n";
		$mer .= "    classDef ann fill:#fff4e0,stroke:#c77a30,color:#5d3a14\n";
		$mer .= "    classDef term fill:#f4d6d6,stroke:#a04848,color:#5a1f1f\n";
		$mer .= "    classDef unknown fill:#f5f5f5,stroke:#888,color:#444,stroke-dasharray:5 3\n";

		return [
			'did_count' => count($dids),
			'destination_count' => count($dests),
			'edge_count' => count($edges),
			'filter' => $filter,
			'to' => $toFilter,
			'summary' => array_filter($summary),
			'mermaid' => $mer,
		];
	}

}
