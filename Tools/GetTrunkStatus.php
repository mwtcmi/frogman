<?php
namespace FreePBX\modules\Frogman\Tools;

require_once __DIR__ . '/AbstractTool.php';

class GetTrunkStatus extends AbstractTool {

	public function name() {
		return 'fm_get_trunk_status';
	}

	public function description() {
		return 'Get detailed status and configuration for a specific trunk by ID. Includes PJSIP registration status if applicable.';
	}

	public function validate($params) {
		if (empty($params['id'])) {
			return 'Parameter "id" is required (trunk ID)';
		}
		if (!preg_match('/^\d+$/', $params['id'])) {
			return 'Parameter "id" must be numeric';
		}
		return true;
	}

	public function requiredPermission() {
		return null;
	}

	public function execute($params, $context) {
		$trunkId = $params['id'];

		// Check existence first — getTrunkDetails will break if trunk doesn't exist
		$db = $this->freepbx->Database;
		$sth = $db->prepare("SELECT trunkid, name, tech, outcid, channelid, disabled FROM trunks WHERE trunkid = ?");
		$sth->execute([$trunkId]);
		$trunkRow = $sth->fetch(\PDO::FETCH_ASSOC);

		if (empty($trunkRow)) {
			throw new \Exception("Trunk {$trunkId} not found");
		}

		$details = $this->freepbx->Core->getTrunkDetails($trunkId);
		$dialRules = $this->freepbx->Core->getTrunkDialRulesByID($trunkId);
		$routes = $this->freepbx->Core->getTrunkRoutesByID($trunkId);

		// Check PJSIP registration status via AMI
		$registrationStatus = null;
		$astman = $this->freepbx->astman;
		if ($astman && $astman->connected() && strtolower($trunkRow['tech']) === 'pjsip') {
			$trunkName = $trunkRow['channelid'];
			$res = $astman->Command("pjsip show registration {$trunkName}");
			$registrationStatus = isset($res['data']) ? trim($res['data']) : 'unknown';
		}

		return [
			'trunk' => $trunkRow,
			'config' => $details,
			'dial_rules' => $dialRules,
			'routes_using' => $routes,
			'registration_status' => $registrationStatus,
		];
	}
}
