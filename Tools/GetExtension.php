<?php
namespace FreePBX\modules\Frogman\Tools;

require_once __DIR__ . '/AbstractTool.php';

class GetExtension extends AbstractTool {

	public function name() {
		return 'fm_get_extension';
	}

	public function description() {
		return 'Retrieve full details for a single extension by number. Returns user config, device config, and Asterisk DB settings.';
	}

	public function validate($params) {
		if (empty($params['ext'])) {
			return 'Parameter "ext" is required';
		}
		if (!preg_match('/^\d+$/', $params['ext'])) {
			return 'Parameter "ext" must be numeric';
		}
		return true;
	}

	public function requiredPermission() {
		return null; // read-only, no special permission needed
	}

	public function execute($params, $context) {
		$ext = $params['ext'];

		// Try BMO Core — this is the reliable path on FreePBX 17
		$user = $this->freepbx->Core->getUser($ext);
		if (empty($user)) {
			throw new \Exception("Extension {$ext} not found");
		}

		$device = $this->freepbx->Core->getDevice($ext);

		return [
			'extension' => $ext,
			'user' => $user,
			'device' => !empty($device) ? $device : null,
		];
	}
}
