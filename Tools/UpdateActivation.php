<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class UpdateActivation extends AbstractTool {
	public function name() { return 'fm_update_activation'; }
	public function description() { return 'Refresh system activation and license from Sangoma portal. Restarts Apache. Requires confirm:true.'; }
	public function validate($params) { return true; }
	public function permissionLevel() { return self::PERM_ADMIN; }
	public function execute($params, $context) {
		$confirm = !empty($params['confirm']) && $params['confirm'] === true;
		if (!$confirm) {
			return ['dry_run' => true, 'message' => "Would refresh activation license from Sangoma portal. This restarts Apache."];
		}

		$output = [];
		exec('script -qc "/usr/sbin/fwconsole sa update --no-ansi 2>&1" /dev/null', $output, $ec);
		$raw = implode("\n", $output);

		// Parse the license table if present (only shows on new/changed license)
		$license = [];
		foreach ($output as $line) {
			if (preg_match('/\|\s*(.+?)\s*\|\s*(.+?)\s*\|/', $line, $m)) {
				$key = trim($m[1]);
				$val = trim($m[2]);
				if ($key === 'Item' || preg_match('/^[\-\+]+$/', $key)) continue;
				$license[$key] = $val;
			}
		}

		// Always supplement with BMO data
		$activated = $this->freepbx->Sysadmin->isActivated();
		$deployType = $this->freepbx->Sysadmin->getDeploymentType();
		$deployName = $this->freepbx->Sysadmin->getDeploymentName();

		$support = [];
		try {
			$cl = $this->freepbx->Sysadmin->CommercialLicense();
			$contractInfo = $cl->isSupportContractExpired();
			if (is_array($contractInfo)) {
				$support = $contractInfo;
			}
		} catch (\Exception $e) {}

		$changed = strpos($raw, 'New Licence') !== false;

		return [
			'message' => $changed ? 'Activation refreshed — new license applied.' : 'Activation refreshed — license unchanged.',
			'license' => $license,
			'activated' => $activated,
			'deployment_id' => $deployName,
			'deployment_type' => $deployType,
			'support_contract' => $support,
		];
	}
}
