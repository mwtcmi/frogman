<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class UpdateActivation extends AbstractTool {
	public function name() { return 'fm_update_activation'; }
	public function description() { return 'Refresh system activation and license from Sangoma portal — equivalent to clicking the Update Activation button on Sysadmin > Activation. Backgrounded because Apache restarts mid-call. Re-check `show license` after ~15s to confirm the new license took effect. Requires confirm:true.'; }
	public function validate($params) { return true; }
	public function permissionLevel() { return self::PERM_ADMIN; }
	public function execute($params, $context) {
		$confirm = !empty($params['confirm']) && $params['confirm'] === true;
		if (!$confirm) {
			return ['dry_run' => true, 'message' => "Would refresh activation license from Sangoma portal. Apache will restart (~15s). After it completes, re-check status."];
		}

		$sa = $this->freepbx->Sysadmin;
		$depid = $sa->getDeploymentName();
		if (empty($depid)) {
			return ['error' => 'No deployment ID found — system is not activated.'];
		}

		if (!$this->canSudo()) {
			return [
				'needs_root' => true,
				'message' => "This command requires root access.",
			];
		}

		// `fwconsole sa activate <depid>` is the GUI-equivalent (full handshake +
		// regen + apache restart). Apache restart kills the current request from
		// the web chat, so background it. CLI invocations are unaffected either way.
		$logFile = '/tmp/frogman-activation-' . time() . '.log';
		$this->runFwconsole(['sa', 'activate', $depid], [
			'sudo' => true,
			'background' => true,
			'log_file' => $logFile,
		]);

		return [
			'message' => "Activation refresh started in the background. Apache will restart in ~10s; the new license takes effect once it returns. Re-check with `show license` in ~15s.",
			'deployment_id' => $depid,
			'log_file' => $logFile,
			'background' => true,
		];
	}
}
