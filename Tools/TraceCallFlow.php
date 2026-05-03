<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class TraceCallFlow extends AbstractTool {
	public function name() { return 'fm_trace_call_flow'; }
	public function description() { return 'Trace the call flow path for a DID or extension — shows the full route from inbound to final destination. Params: did (DID number) or ext (extension number).'; }
	public function validate($params) {
		if (empty($params['did']) && empty($params['ext'])) return 'Parameter "did" or "ext" is required';
		return true;
	}
	public function execute($params, $context) {
		$db = $this->freepbx->Database;
		$nodes = [];
		$edges = [];
		$nodeId = 0;

		if (!empty($params['did'])) {
			$did = $params['did'];
			$nodes[] = ['id' => 'n' . $nodeId++, 'label' => "DID: {$did}", 'type' => 'did'];

			// Find inbound route
			$sth = $db->prepare("SELECT extension, destination, description FROM incoming WHERE extension = ? OR cidnum = ?");
			$sth->execute([$did, $did]);
			$route = $sth->fetch(\PDO::FETCH_ASSOC);

			if (!$route) {
				// Try without leading 1 or with it
				$altDid = strlen($did) === 11 ? substr($did, 1) : '1' . $did;
				$sth->execute([$altDid, $altDid]);
				$route = $sth->fetch(\PDO::FETCH_ASSOC);
			}

			if (!$route) {
				return ['nodes' => $nodes, 'edges' => [], 'error' => "No inbound route found for {$did}"];
			}

			$routeNode = 'n' . $nodeId++;
			$desc = $route['description'] ?: 'Inbound Route';
			$nodes[] = ['id' => $routeNode, 'label' => $desc, 'type' => 'route'];
			$edges[] = ['from' => 'n0', 'to' => $routeNode, 'label' => ''];

			// Follow the destination chain
			$this->traceDestination($route['destination'], $routeNode, $nodes, $edges, $nodeId, $db, 0);
		}

		if (!empty($params['ext'])) {
			$ext = $params['ext'];
			$name = $this->getExtensionName($ext, $db);
			$label = $name ? "{$ext} ({$name})" : "Ext: {$ext}";
			$nodes[] = ['id' => 'n' . $nodeId++, 'label' => $label, 'type' => 'extension'];

			// Check if extension has follow me
			try {
				if (\FreePBX::Modules()->checkStatus('findmefollow')) {
					$fm = $this->freepbx->Findmefollow->getSettingsById($ext);
					if (!empty($fm) && !empty($fm['grplist'])) {
						$fmNode = 'n' . $nodeId++;
						$nodes[] = ['id' => $fmNode, 'label' => "Follow Me: {$fm['grplist']}", 'type' => 'followme'];
						$edges[] = ['from' => 'n0', 'to' => $fmNode, 'label' => 'ring'];
					}
				}
			} catch (\Exception $e) {}

			// Check call forward
			$astman = $this->freepbx->astman;
			if ($astman && $astman->connected()) {
				$cf = $astman->database_get('CF', $ext);
				if (!empty($cf)) {
					$cfNode = 'n' . $nodeId++;
					$nodes[] = ['id' => $cfNode, 'label' => "Forward: {$cf}", 'type' => 'forward'];
					$edges[] = ['from' => 'n0', 'to' => $cfNode, 'label' => 'CF'];
				}
			}

			// Check voicemail
			$sth = $db->prepare("SELECT extension FROM users WHERE extension = ? AND voicemail != 'novm'");
			$sth->execute([$ext]);
			if ($sth->fetch()) {
				$vmNode = 'n' . $nodeId++;
				$nodes[] = ['id' => $vmNode, 'label' => "Voicemail: {$ext}", 'type' => 'voicemail'];
				$edges[] = ['from' => 'n0', 'to' => $vmNode, 'label' => 'no answer'];
			}
		}

		return ['nodes' => $nodes, 'edges' => $edges];
	}

	private function getExtensionName($ext, $db) {
		$sth = $db->prepare("SELECT name FROM users WHERE extension = ?");
		$sth->execute([$ext]);
		$row = $sth->fetch(\PDO::FETCH_ASSOC);
		return $row ? $row['name'] : null;
	}

	private function traceDestination($dest, $fromNode, &$nodes, &$edges, &$nodeId, $db, $depth) {
		if ($depth > 10 || empty($dest)) return; // prevent loops

		// Parse destination format: context,exten,priority
		$parts = explode(',', $dest);
		$context = $parts[0] ?? '';
		$exten = $parts[1] ?? '';

		// Map FreePBX destination contexts to readable types
		if (strpos($context, 'from-did-direct') !== false) {
			$node = 'n' . $nodeId++;
			$name = $this->getExtensionName($exten, $db);
			$label = $name ? "{$exten} ({$name})" : "Ext: {$exten}";
			$nodes[] = ['id' => $node, 'label' => $label, 'type' => 'extension'];
			$edges[] = ['from' => $fromNode, 'to' => $node, 'label' => ''];
			$this->traceExtension($exten, $node, $nodes, $edges, $nodeId, $db, $depth + 1);

		} elseif (strpos($context, 'ext-group') !== false) {
			$node = 'n' . $nodeId++;
			$grpInfo = $this->getRingGroupInfo($exten, $db);
			$label = $grpInfo ? "Ring Group: {$grpInfo['description']} ({$exten})" : "Ring Group: {$exten}";
			$nodes[] = ['id' => $node, 'label' => $label, 'type' => 'ringgroup'];
			$edges[] = ['from' => $fromNode, 'to' => $node, 'label' => ''];
			if ($grpInfo) {
				$this->traceRingGroup($grpInfo, $node, $nodes, $edges, $nodeId, $db, $depth + 1);
			}

		} elseif (strpos($context, 'ivr-') !== false) {
			$ivrId = preg_replace('/[^0-9]/', '', $exten);
			$node = 'n' . $nodeId++;
			$ivrInfo = $this->getIvrInfo($ivrId, $db);
			$label = $ivrInfo ? "IVR: {$ivrInfo['name']}" : "IVR: {$ivrId}";
			$nodes[] = ['id' => $node, 'label' => $label, 'type' => 'ivr'];
			$edges[] = ['from' => $fromNode, 'to' => $node, 'label' => ''];
			if ($ivrInfo) {
				$this->traceIvr($ivrInfo, $node, $nodes, $edges, $nodeId, $db, $depth + 1);
			}

		} elseif (strpos($context, 'timeconditions') !== false) {
			$tcId = preg_replace('/[^0-9]/', '', $exten);
			$node = 'n' . $nodeId++;
			$tcInfo = $this->getTimeconditionInfo($tcId, $db);
			$label = $tcInfo ? "Time: {$tcInfo['name']}" : "Time Condition: {$tcId}";
			$nodes[] = ['id' => $node, 'label' => $label, 'type' => 'timecondition'];
			$edges[] = ['from' => $fromNode, 'to' => $node, 'label' => ''];
			if ($tcInfo) {
				// True (matched) destination
				if (!empty($tcInfo['truegoto'])) {
					$this->traceDestination($tcInfo['truegoto'], $node, $nodes, $edges, $nodeId, $db, $depth + 1);
				}
				// False (unmatched) destination
				if (!empty($tcInfo['falsegoto'])) {
					$falseNode = 'n' . $nodeId++;
					$nodes[] = ['id' => $falseNode, 'label' => 'After Hours', 'type' => 'label'];
					$edges[] = ['from' => $node, 'to' => $falseNode, 'label' => 'no match'];
					$this->traceDestination($tcInfo['falsegoto'], $falseNode, $nodes, $edges, $nodeId, $db, $depth + 1);
				}
			}

		} elseif (strpos($context, 'ext-queues') !== false) {
			$node = 'n' . $nodeId++;
			$qInfo = $this->getQueueInfo($exten, $db);
			$label = $qInfo ? "Queue: {$qInfo['descr']} ({$exten})" : "Queue: {$exten}";
			$nodes[] = ['id' => $node, 'label' => $label, 'type' => 'queue'];
			$edges[] = ['from' => $fromNode, 'to' => $node, 'label' => ''];

		} elseif (strpos($context, 'app-announcement') !== false) {
			$node = 'n' . $nodeId++;
			$nodes[] = ['id' => $node, 'label' => "Announcement: {$exten}", 'type' => 'announcement'];
			$edges[] = ['from' => $fromNode, 'to' => $node, 'label' => ''];

		} elseif (strpos($context, 'ext-local') !== false) {
			$node = 'n' . $nodeId++;
			$name = $this->getExtensionName($exten, $db);
			$label = $name ? "{$exten} ({$name})" : "Ext: {$exten}";
			$nodes[] = ['id' => $node, 'label' => $label, 'type' => 'extension'];
			$edges[] = ['from' => $fromNode, 'to' => $node, 'label' => ''];

		} elseif (strpos($context, 'app-blackhole') !== false) {
			$node = 'n' . $nodeId++;
			$labels = ['hangup' => 'Hangup', 'congestion' => 'Congestion', 'busy' => 'Busy', 'zapateller' => 'Zapateller', 'musiconhold' => 'Music on Hold', 'ring' => 'Ring Forever'];
			$label = $labels[$exten] ?? "Terminate: {$exten}";
			$nodes[] = ['id' => $node, 'label' => $label, 'type' => 'terminate'];
			$edges[] = ['from' => $fromNode, 'to' => $node, 'label' => ''];

		} elseif (strpos($dest, 'vmu') !== false || strpos($context, 'ext-local') !== false) {
			$vmExt = preg_replace('/[^0-9]/', '', $exten);
			$node = 'n' . $nodeId++;
			$nodes[] = ['id' => $node, 'label' => "Voicemail: {$vmExt}", 'type' => 'voicemail'];
			$edges[] = ['from' => $fromNode, 'to' => $node, 'label' => ''];

		} else {
			// Unknown or unconfigured destination
			$node = 'n' . $nodeId++;
			$raw = trim($dest);
			$label = ($raw === 'goto' || empty($raw)) ? '⚠️ Not Configured' : $raw;
			$type = ($raw === 'goto' || empty($raw)) ? 'terminate' : 'unknown';
			$nodes[] = ['id' => $node, 'label' => $label, 'type' => $type];
			$edges[] = ['from' => $fromNode, 'to' => $node, 'label' => ''];
		}
	}

	private function traceExtension($ext, $fromNode, &$nodes, &$edges, &$nodeId, $db, $depth) {
		if ($depth > 10) return;
		// Check voicemail
		$sth = $db->prepare("SELECT extension, name FROM users WHERE extension = ? AND voicemail != 'novm'");
		$sth->execute([$ext]);
		$row = $sth->fetch(\PDO::FETCH_ASSOC);
		if ($row) {
			$node = 'n' . $nodeId++;
			$name = $row['name'] ? " ({$row['name']})" : '';
			$nodes[] = ['id' => $node, 'label' => "VM: {$ext}{$name}", 'type' => 'voicemail'];
			$edges[] = ['from' => $fromNode, 'to' => $node, 'label' => 'no answer'];
		}
	}

	private function traceRingGroup($info, $fromNode, &$nodes, &$edges, &$nodeId, $db, $depth) {
		if ($depth > 10) return;
		// Members
		$members = explode('-', $info['grplist'] ?? '');
		foreach ($members as $m) {
			$m = trim($m);
			if (!empty($m) && is_numeric($m)) {
				$node = 'n' . $nodeId++;
				$name = $this->getExtensionName($m, $db);
				$label = $name ? "{$m} ({$name})" : "Ext: {$m}";
				$nodes[] = ['id' => $node, 'label' => $label, 'type' => 'extension'];
				$edges[] = ['from' => $fromNode, 'to' => $node, 'label' => ''];
			}
		}
		// Failover destination
		if (!empty($info['postdest'])) {
			$this->traceDestination($info['postdest'], $fromNode, $nodes, $edges, $nodeId, $db, $depth + 1);
		}
	}

	private function traceIvr($info, $fromNode, &$nodes, &$edges, &$nodeId, $db, $depth) {
		if ($depth > 10) return;
		// Timeout destination
		if (!empty($info['timeout_destination'])) {
			$this->traceDestination($info['timeout_destination'], $fromNode, $nodes, $edges, $nodeId, $db, $depth + 1);
		}
		// Invalid destination
		if (!empty($info['invalid_destination'])) {
			$this->traceDestination($info['invalid_destination'], $fromNode, $nodes, $edges, $nodeId, $db, $depth + 1);
		}
	}

	private function getRingGroupInfo($grpnum, $db) {
		$sth = $db->prepare("SELECT grpnum, description, grplist, strategy, grptime, postdest FROM ringgroups WHERE grpnum = ?");
		$sth->execute([$grpnum]);
		return $sth->fetch(\PDO::FETCH_ASSOC) ?: null;
	}

	private function getIvrInfo($id, $db) {
		try {
			$details = $this->freepbx->Ivr->getDetails($id);
			return $details ?: null;
		} catch (\Exception $e) { return null; }
	}

	private function getTimeconditionInfo($id, $db) {
		$sth = $db->prepare("SELECT timeconditions_id, displayname, truegoto, falsegoto FROM timeconditions WHERE timeconditions_id = ?");
		$sth->execute([$id]);
		$row = $sth->fetch(\PDO::FETCH_ASSOC);
		if ($row) {
			$row['name'] = $row['displayname'];
		}
		return $row ?: null;
	}

	private function getQueueInfo($ext, $db) {
		$sth = $db->prepare("SELECT extension, descr FROM queues_config WHERE extension = ?");
		$sth->execute([$ext]);
		return $sth->fetch(\PDO::FETCH_ASSOC) ?: null;
	}
}
