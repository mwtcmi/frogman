<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class DiagnoseTrunk extends AbstractTool {
	public function name() { return 'fm_diagnose_trunk'; }
	public function description() { return 'Composite SIP diagnostic for a trunk — registration, qualify, config, and recent call attempts. Params: id (required, trunk ID).'; }

	public function validate($params) {
		if (empty($params['id'])) return 'Parameter "id" is required (trunk ID)';
		if (!preg_match('/^\d+$/', $params['id'])) return 'Parameter "id" must be numeric';
		return true;
	}

	public function execute($params, $context) {
		$trunkId = $params['id'];
		$astman = $this->freepbx->astman;
		if (!$astman || !$astman->connected()) throw new \Exception('Cannot connect to Asterisk Manager');

		$db = $this->freepbx->Database;

		// 1. Trunk config from DB
		$sth = $db->prepare("SELECT trunkid, name, tech, outcid, channelid, disabled FROM trunks WHERE trunkid = ?");
		$sth->execute([$trunkId]);
		$trunk = $sth->fetch(\PDO::FETCH_ASSOC);

		if (empty($trunk)) {
			throw new \Exception("Trunk {$trunkId} not found");
		}

		$result = ['trunk_id' => $trunkId, 'trunk' => $trunk, 'checks' => []];
		$trunkName = $trunk['channelid'];
		$isPjsip = strtolower($trunk['tech']) === 'pjsip';

		// 2. Disabled check
		if ($trunk['disabled'] === 'on') {
			$result['checks']['disabled'] = true;
			$result['summary'] = "Trunk {$trunkId} ({$trunk['name']}) is DISABLED.";
			return $result;
		}
		$result['checks']['disabled'] = false;

		// 3. PJSIP registration status
		if ($isPjsip && $trunkName) {
			$regRes = $astman->Command("pjsip show registration {$trunkName}");
			$regData = trim($regRes['data'] ?? '');

			$regState = 'unknown';
			if (preg_match('/Status\s*:\s*(\S+)/i', $regData, $m)) {
				$regState = $m[1];
			}

			$result['checks']['registration'] = [
				'state' => $regState,
				'raw' => $regData,
			];

			// Endpoint detail
			$epRes = $astman->Command("pjsip show endpoint {$trunkName}");
			$epData = trim($epRes['data'] ?? '');
			$result['checks']['endpoint_raw'] = $epData;

			// Qualify
			$qualRes = $astman->send_request('PJSIPQualify', ['Endpoint' => $trunkName]);
			$result['checks']['qualify'] = $qualRes;
		}

		// 4. Routes using this trunk
		$routes = $this->freepbx->Core->getTrunkRoutesByID($trunkId);
		$result['checks']['outbound_routes'] = $routes;

		// 5. Recent outbound CDR through this trunk
		$cdrSth = $db->prepare(
			"SELECT calldate, src, dst, disposition, duration, billsec, channel, dstchannel
			 FROM asteriskcdrdb.cdr
			 WHERE channel LIKE ? OR dstchannel LIKE ?
			 ORDER BY calldate DESC LIMIT 10"
		);
		$trunkPattern = "%PJSIP/{$trunkName}%";
		$cdrSth->execute([$trunkPattern, $trunkPattern]);
		$cdr = $cdrSth->fetchAll(\PDO::FETCH_ASSOC);
		$result['checks']['recent_cdr'] = [
			'count' => count($cdr),
			'records' => $cdr,
		];

		// 6. Build summary
		$issues = [];
		if (isset($result['checks']['registration'])) {
			$regState = strtolower($result['checks']['registration']['state']);
			if ($regState !== 'registered' && $regState !== 'registered(tcp)' && $regState !== 'registered(udp)' && $regState !== 'registered(tls)') {
				$issues[] = "Registration state: {$result['checks']['registration']['state']}";
			}
		}
		if (empty($result['checks']['outbound_routes'])) {
			$issues[] = "No outbound routes use this trunk";
		}
		if (!empty($cdr)) {
			$failed = array_filter($cdr, function($r) { return $r['disposition'] === 'FAILED'; });
			if (count($failed) > count($cdr) / 2) {
				$issues[] = sprintf("%d of last %d calls FAILED", count($failed), count($cdr));
			}
		}

		$result['summary'] = empty($issues)
			? "Trunk {$trunkId} ({$trunk['name']}) appears healthy."
			: "Trunk {$trunkId} ({$trunk['name']}) has issues: " . implode('; ', $issues);

		return $result;
	}
}
