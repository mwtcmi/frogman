<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

/**
 * Whole-PBX call-routing map. Walks every entrypoint (or a single chosen one)
 * and emits a Mermaid flowchart with shared destinations deduped into one node.
 *
 * Closes the gaps in the two prior mermaid tools:
 *  - fm_did_destination_map renders all DIDs but only first-hop.
 *  - fm_trace_call_flow follows every branch but only one entrypoint at a time,
 *    and silently skips IVR option destinations + queue failover + announcement
 *    post-destination.
 *
 * In-walls: BMO for Ivr/Ringgroups/Findmefollow, AMI for live CF state,
 * direct reads of FreePBX-owned tables for incoming/timeconditions/queues_details/
 * announcement (read-only — never writes another module's tables).
 */
class PbxRouteMap extends AbstractTool {
	private $db;
	private $nodes = [];          // dedup key (e.g. "ivr:8") => ['id','type','label','entrypoint']
	private $edges = [];          // [['from','to','label'], ...]
	private $entrypoints = [];    // key => true
	private $unknownDests = [];   // key => ['raw','label']
	private $warnings = [];
	private $nodeCounter = 0;
	private $maxDepth = 8;
	private $maxNodes = 500;
	private $ivrEntriesCache = null;
	private $edgeSeen = [];       // (from->to|label) seen-set to suppress identical edges

	public function name() { return 'fm_pbx_route_map'; }

	public function description() {
		return 'Map the entire PBX call routing as one Mermaid flowchart. Walks every inbound entrypoint (or a chosen scope), follows IVR options, TC true/false, queue failover, ring-group members + post-dest, announcement post-dest, voicemail, follow-me, and call-forward. Shared destinations dedup into one node. Params: scope (all / did:X / ext:X / ivr:X / tc:X / rg:X / queue:X, default all), depth (1-12, default 8), mode (full / summary, default full), cluster_by (none / type, default type). Read-only.';
	}

	public function validate($params) {
		if (!empty($params['scope']) && !is_string($params['scope'])) {
			return 'Parameter "scope" must be a string (e.g. "all", "did:5551234", "ext:1001").';
		}
		if (isset($params['depth'])) {
			$d = (int)$params['depth'];
			if ($d < 1 || $d > 12) return 'Parameter "depth" must be between 1 and 12.';
		}
		if (!empty($params['mode']) && !in_array($params['mode'], ['full','summary'], true)) {
			return 'Parameter "mode" must be "full" or "summary".';
		}
		if (!empty($params['cluster_by']) && !in_array($params['cluster_by'], ['none','type'], true)) {
			return 'Parameter "cluster_by" must be "none" or "type".';
		}
		return true;
	}

	public function execute($params, $context) {
		$this->db = $this->freepbx->Database;
		$this->maxDepth = max(1, min(12, (int)($params['depth'] ?? 8)));
		$scope = trim($params['scope'] ?? 'all');
		$mode = $params['mode'] ?? 'full';
		$clusterBy = $params['cluster_by'] ?? 'type';

		$entries = $this->resolveScope($scope);
		if (empty($entries)) {
			return [
				'scope' => $scope,
				'error' => 'No entrypoint resolved. Try scope="all" or a specific id like scope="did:5551234", scope="ext:1001", scope="ivr:8", scope="tc:3", scope="rg:600", scope="queue:700".',
			];
		}

		foreach ($entries as $e) {
			$epId = $this->ensureNode($e['key'], $e['type'], $e['label'], true);
			$this->entrypoints[$e['key']] = true;
			if (!empty($e['destination'])) {
				$this->trace($e['destination'], $epId, 1);
			} elseif (!empty($e['expand'])) {
				foreach ($e['expand'] as $kind => $val) {
					$this->dispatchTypeExpand($kind, $val, $epId, 1);
				}
			}
		}

		$result = [
			'scope' => $scope,
			'depth' => $this->maxDepth,
			'mode' => $mode,
			'cluster_by' => $clusterBy,
			'entrypoint_count' => count($this->entrypoints),
			'node_count' => count($this->nodes),
			'edge_count' => count($this->edges),
			'summary' => $this->buildSummary(),
			'unknown_destinations' => array_values($this->unknownDests),
		];

		if (count($this->nodes) > $this->maxNodes && $mode === 'full') {
			$this->warnings[] = "Graph has {$result['node_count']} nodes (cap {$this->maxNodes}). Forcing summary mode. Scope down with did:X, tc:X, or ivr:X for a focused view.";
			$mode = 'summary';
			$result['mode'] = 'summary';
		}
		$result['warnings'] = $this->warnings;

		if ($mode === 'full') {
			$result['mermaid'] = $this->buildMermaid($clusterBy);
		}
		return $result;
	}

	// ── Scope resolution ──────────────────────────────────────────────

	private function resolveScope($scope) {
		if ($scope === 'all' || $scope === '') {
			$rows = $this->db->query("SELECT extension, destination, description FROM incoming ORDER BY extension")->fetchAll(\PDO::FETCH_ASSOC);
			$entries = [];
			foreach ($rows as $r) {
				$did = trim($r['extension']);
				if ($did === '' || $did === 'ANY') $did = 'any';
				$desc = trim($r['description']);
				$label = $desc !== '' ? "{$did}\\n{$desc}" : $did;
				$entries[] = ['type'=>'did', 'key'=>"did:{$did}", 'label'=>$label, 'destination'=>$r['destination']];
			}
			return $entries;
		}
		if (strpos($scope, ':') === false) return [];
		list($t, $v) = explode(':', $scope, 2);
		$t = strtolower(trim($t));
		$v = trim($v);
		if ($v === '') return [];
		switch ($t) {
			case 'did':
				$sth = $this->db->prepare("SELECT extension, destination, description FROM incoming WHERE extension = ? OR cidnum = ? LIMIT 1");
				$sth->execute([$v, $v]);
				$r = $sth->fetch(\PDO::FETCH_ASSOC);
				if (!$r) return [];
				$desc = trim($r['description']);
				return [['type'=>'did', 'key'=>"did:{$v}", 'label'=>$desc !== '' ? "{$v}\\n{$desc}" : $v, 'destination'=>$r['destination']]];
			case 'ext':
				$name = $this->lookupExtensionName($v);
				return [['type'=>'extension', 'key'=>"ext:{$v}", 'label'=>$name ? "Ext {$v} ({$name})" : "Ext {$v}", 'expand'=>['extension'=>$v]]];
			case 'tc':
				$sth = $this->db->prepare("SELECT displayname FROM timeconditions WHERE timeconditions_id = ?");
				$sth->execute([$v]);
				$n = $sth->fetchColumn() ?: '';
				return [['type'=>'timecondition', 'key'=>"tc:{$v}", 'label'=>$n ? "Time: {$n}" : "Time Cond {$v}", 'expand'=>['timecondition'=>$v]]];
			case 'ivr':
				$n = '';
				try { $d = $this->freepbx->Ivr->getDetails($v); $n = $d['name'] ?? ''; } catch (\Throwable $e) {}
				return [['type'=>'ivr', 'key'=>"ivr:{$v}", 'label'=>$n ? "IVR: {$n}" : "IVR {$v}", 'expand'=>['ivr'=>$v]]];
			case 'rg':
				$n = '';
				try { $g = $this->freepbx->Ringgroups->get($v); $n = $g['description'] ?? ''; } catch (\Throwable $e) {}
				return [['type'=>'ringgroup', 'key'=>"rg:{$v}", 'label'=>$n ? "Ring Group {$v}: {$n}" : "Ring Group {$v}", 'expand'=>['ringgroup'=>$v]]];
			case 'queue':
				$sth = $this->db->prepare("SELECT descr FROM queues_config WHERE extension = ? LIMIT 1");
				$sth->execute([$v]);
				$n = $sth->fetchColumn() ?: '';
				return [['type'=>'queue', 'key'=>"q:{$v}", 'label'=>$n ? "Queue {$v}: {$n}" : "Queue {$v}", 'expand'=>['queue'=>$v]]];
		}
		return [];
	}

	private function dispatchTypeExpand($kind, $val, $fromId, $depth) {
		switch ($kind) {
			case 'extension':     $this->traceExtension($val, $fromId, $depth); break;
			case 'ringgroup':     $this->traceRingGroup($val, $fromId, $depth); break;
			case 'ivr':           $this->traceIvr($val, $fromId, $depth); break;
			case 'timecondition': $this->traceTimeCondition($val, $fromId, $depth); break;
			case 'queue':         $this->traceQueue($val, $fromId, $depth); break;
		}
	}

	// ── Graph plumbing ────────────────────────────────────────────────

	private function ensureNode($key, $type, $label, $isEntrypoint = false) {
		if (isset($this->nodes[$key])) {
			if ($isEntrypoint) $this->nodes[$key]['entrypoint'] = true;
			return $this->nodes[$key]['id'];
		}
		$id = 'n' . $this->nodeCounter++;
		$this->nodes[$key] = ['id'=>$id, 'type'=>$type, 'label'=>$label, 'entrypoint'=>$isEntrypoint];
		return $id;
	}

	private function addEdge($from, $to, $label = '') {
		$sig = $from . '->' . $to . '|' . $label;
		if (isset($this->edgeSeen[$sig])) return;
		$this->edgeSeen[$sig] = true;
		$this->edges[] = ['from'=>$from, 'to'=>$to, 'label'=>$label];
	}

	// ── Recursive trace ───────────────────────────────────────────────

	private function trace($destStr, $fromId, $depth, $edgeLabel = '') {
		if ($depth > $this->maxDepth) {
			$this->warnings[] = "Depth cap ({$this->maxDepth}) hit; truncated at node {$fromId}.";
			return;
		}
		$resolved = $this->describeDestination($destStr, $this->db);
		$key = $resolved['key'];
		$isNew = !isset($this->nodes[$key]);
		$nodeId = $this->ensureNode($key, $resolved['type'], $resolved['label']);
		$this->addEdge($fromId, $nodeId, $edgeLabel);
		if (!$isNew) return;

		if ($resolved['type'] === 'unknown') {
			$this->unknownDests[$key] = ['raw'=>$destStr, 'label'=>$resolved['label']];
			return;
		}
		switch ($resolved['type']) {
			case 'extension':
				$this->traceExtension($this->stripPrefix($key, 'ext:'), $nodeId, $depth);
				break;
			case 'ringgroup':
				$this->traceRingGroup($this->stripPrefix($key, 'rg:'), $nodeId, $depth);
				break;
			case 'ivr':
				$this->traceIvr($this->stripPrefix($key, 'ivr:'), $nodeId, $depth);
				break;
			case 'timecondition':
				$this->traceTimeCondition($this->stripPrefix($key, 'tc:'), $nodeId, $depth);
				break;
			case 'queue':
				$this->traceQueue($this->stripPrefix($key, 'q:'), $nodeId, $depth);
				break;
			case 'announcement':
				$this->traceAnnouncement($this->stripPrefix($key, 'ann:'), $nodeId, $depth);
				break;
			// voicemail, terminate are leaves
		}
	}

	private function stripPrefix($key, $prefix) {
		return (strpos($key, $prefix) === 0) ? substr($key, strlen($prefix)) : $key;
	}

	private function traceExtension($ext, $nodeId, $depth) {
		$sth = $this->db->prepare("SELECT extension FROM users WHERE extension = ? AND voicemail != 'novm'");
		$sth->execute([$ext]);
		if ($sth->fetch()) {
			$vmId = $this->ensureNode("vm:{$ext}", 'voicemail', "Voicemail {$ext}");
			$this->addEdge($nodeId, $vmId, 'no answer');
		}
		try {
			if (\FreePBX::Modules()->checkStatus('findmefollow')) {
				$fm = $this->freepbx->Findmefollow->getSettingsById($ext);
				if (!empty($fm) && !empty($fm['grplist'])) {
					$grp = preg_replace('/[^0-9,\-#]/', '', (string)$fm['grplist']);
					$fmId = $this->ensureNode("fm:{$ext}", 'followme', "Follow-Me {$ext}: {$grp}");
					$this->addEdge($nodeId, $fmId, 'ring');
					if (!empty($fm['postdest'])) {
						$this->trace($fm['postdest'], $fmId, $depth + 1, 'no answer');
					}
				}
			}
		} catch (\Throwable $e) {}
		$astman = $this->freepbx->astman ?? null;
		if ($astman && $astman->connected()) {
			try {
				$cf = $astman->database_get('CF', $ext);
				if (!empty($cf)) {
					$safe = preg_replace('/[^0-9+*#]/', '', (string)$cf);
					$cfId = $this->ensureNode("cf:{$ext}", 'forward', "CF -> {$safe}");
					$this->addEdge($nodeId, $cfId, 'forward');
				}
			} catch (\Throwable $e) {}
		}
	}

	private function traceRingGroup($grpnum, $nodeId, $depth) {
		try { $g = $this->freepbx->Ringgroups->get($grpnum); }
		catch (\Throwable $e) { return; }
		if (!$g) return;
		$members = preg_split('/[-,]/', (string)($g['grplist'] ?? ''));
		foreach ($members as $m) {
			$m = trim((string)$m);
			$digits = preg_replace('/[^0-9]/', '', $m);
			if ($digits === '') continue;
			$this->trace("from-did-direct,{$digits},1", $nodeId, $depth + 1);
		}
		if (!empty($g['postdest'])) {
			$this->trace($g['postdest'], $nodeId, $depth + 1, 'no answer');
		}
	}

	private function traceIvr($id, $nodeId, $depth) {
		try { $details = $this->freepbx->Ivr->getDetails($id); }
		catch (\Throwable $e) { return; }
		if (!$details) return;

		if (!empty($details['timeout_destination'])) {
			$this->trace($details['timeout_destination'], $nodeId, $depth + 1, 'timeout');
		}
		if (!empty($details['invalid_destination'])) {
			$this->trace($details['invalid_destination'], $nodeId, $depth + 1, 'invalid');
		}
		if ($this->ivrEntriesCache === null) {
			try { $this->ivrEntriesCache = $this->freepbx->Ivr->getAllEntries(); }
			catch (\Throwable $e) { $this->ivrEntriesCache = []; }
		}
		// Ivr->getAllEntries() returns array keyed by ivr_id, value = list of entry rows.
		// Not a flat list — earlier draft iterated wrong shape and silently emitted no edges.
		$entries = is_array($this->ivrEntriesCache) ? ($this->ivrEntriesCache[$id] ?? []) : [];
		foreach ($entries as $entry) {
			$dest = (string)($entry['dest'] ?? '');
			if ($dest === '') continue;
			$sel = (string)($entry['selection'] ?? '');
			$label = $sel !== '' ? "press {$sel}" : '';
			$this->trace($dest, $nodeId, $depth + 1, $label);
		}
	}

	private function traceTimeCondition($id, $nodeId, $depth) {
		$sth = $this->db->prepare("SELECT truegoto, falsegoto FROM timeconditions WHERE timeconditions_id = ?");
		$sth->execute([$id]);
		$row = $sth->fetch(\PDO::FETCH_ASSOC);
		if (!$row) return;
		if (!empty($row['truegoto'])) $this->trace($row['truegoto'], $nodeId, $depth + 1, 'match');
		if (!empty($row['falsegoto'])) $this->trace($row['falsegoto'], $nodeId, $depth + 1, 'no match');
	}

	private function traceQueue($ext, $nodeId, $depth) {
		// Queue failover destination is queues_config.dest (a direct column).
		// queues_details holds Asterisk-side keyword/data settings and does NOT
		// carry the failover destination — earlier draft queried the wrong table.
		try {
			$sth = $this->db->prepare("SELECT dest FROM queues_config WHERE extension = ? LIMIT 1");
			$sth->execute([$ext]);
			$dest = $sth->fetchColumn();
			if ($dest) {
				$this->trace($dest, $nodeId, $depth + 1, 'failover');
			}
		} catch (\Throwable $e) {}
	}

	private function traceAnnouncement($id, $nodeId, $depth) {
		try {
			$sth = $this->db->prepare("SELECT post_dest FROM announcement WHERE announcement_id = ?");
			$sth->execute([$id]);
			$dest = $sth->fetchColumn();
			if ($dest) {
				$this->trace($dest, $nodeId, $depth + 1, 'after');
			}
		} catch (\Throwable $e) {}
	}

	// ── Output ────────────────────────────────────────────────────────

	private function buildSummary() {
		$s = [];
		foreach ($this->nodes as $n) {
			$s[$n['type']] = ($s[$n['type']] ?? 0) + 1;
		}
		ksort($s);
		return $s;
	}

	private function buildMermaid($clusterBy) {
		$shapes = [
			'did'           => ['([', '])'],
			'extension'     => ['[',  ']'],
			'ringgroup'     => ['{{', '}}'],
			'queue'         => ['[/', '/]'],
			'ivr'           => ['(',  ')'],
			'voicemail'     => ['[\\','/]'],
			'timecondition' => ['{',  '}'],
			'announcement'  => ['[(', ')]'],
			'terminate'     => ['((', '))'],
			'followme'      => ['[',  ']'],
			'forward'       => ['[',  ']'],
			'unknown'       => ['[',  ']'],
		];
		$classes = [
			'did'           => ':::did',
			'extension'     => ':::ext',
			'ringgroup'     => ':::rg',
			'queue'         => ':::queue',
			'ivr'           => ':::ivr',
			'voicemail'     => ':::vm',
			'timecondition' => ':::tc',
			'announcement'  => ':::ann',
			'terminate'     => ':::term',
			'followme'      => ':::fm',
			'forward'       => ':::fwd',
			'unknown'       => ':::unknown',
		];

		$mer = "flowchart LR\n";

		if ($clusterBy === 'type') {
			$byType = [];
			foreach ($this->nodes as $n) $byType[$n['type']][] = $n;
			$titles = [
				'did'=>'Inbound DIDs', 'extension'=>'Extensions', 'ringgroup'=>'Ring Groups',
				'queue'=>'Queues', 'ivr'=>'IVRs', 'voicemail'=>'Voicemail',
				'timecondition'=>'Time Conditions', 'announcement'=>'Announcements',
				'terminate'=>'Terminations', 'followme'=>'Follow-Me', 'forward'=>'Call Forward',
				'unknown'=>'Unconfigured',
			];
			$sgIdx = 0;
			foreach ($byType as $type => $nodes) {
				$sg = 'sg' . $sgIdx++;
				$title = $titles[$type] ?? $type;
				$mer .= "    subgraph {$sg} [\"{$title}\"]\n";
				foreach ($nodes as $n) {
					$mer .= $this->renderNode($n, $shapes, $classes, '        ');
				}
				$mer .= "    end\n";
			}
		} else {
			foreach ($this->nodes as $n) {
				$mer .= $this->renderNode($n, $shapes, $classes, '    ');
			}
		}

		foreach ($this->edges as $e) {
			if ($e['label'] !== '') {
				$lbl = $this->escapeMermaid($e['label']);
				$mer .= "    {$e['from']} -->|{$lbl}| {$e['to']}\n";
			} else {
				$mer .= "    {$e['from']} --> {$e['to']}\n";
			}
		}

		$mer .= "    classDef did fill:#0f5a59,stroke:#0f5a59,color:#fff\n";
		$mer .= "    classDef ext fill:#fff,stroke:#009d9d,color:#0f5a59\n";
		$mer .= "    classDef rg fill:#e8f4f4,stroke:#009d9d,color:#0f5a59\n";
		$mer .= "    classDef queue fill:#d0ebeb,stroke:#0f5a59,color:#0f5a59\n";
		$mer .= "    classDef ivr fill:#f3a25e,stroke:#c77a30,color:#fff\n";
		$mer .= "    classDef vm fill:#f0e6f4,stroke:#7a4b8a,color:#3d2348\n";
		$mer .= "    classDef tc fill:#e6f0e6,stroke:#5a8a5a,color:#234823\n";
		$mer .= "    classDef ann fill:#fff4e0,stroke:#c77a30,color:#5d3a14\n";
		$mer .= "    classDef term fill:#f4d6d6,stroke:#a04848,color:#5a1f1f\n";
		$mer .= "    classDef fm fill:#fff,stroke:#7a4b8a,color:#3d2348\n";
		$mer .= "    classDef fwd fill:#fff,stroke:#c77a30,color:#5d3a14\n";
		$mer .= "    classDef unknown fill:#f5f5f5,stroke:#888,color:#444,stroke-dasharray:5 3\n";
		return $mer;
	}

	private function renderNode($n, $shapes, $classes, $indent) {
		$shape = $shapes[$n['type']] ?? ['[', ']'];
		$class = $classes[$n['type']] ?? '';
		$label = $this->escapeMermaid($n['label']);
		return "{$indent}{$n['id']}{$shape[0]}\"{$label}\"{$shape[1]}{$class}\n";
	}

	private function escapeMermaid($s) {
		return str_replace(['"', '`', '|'], ["'", "'", '/'], (string)$s);
	}
}
