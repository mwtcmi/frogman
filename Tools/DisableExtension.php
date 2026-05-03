<?php
namespace FreePBX\modules\Frogman\Tools;

require_once __DIR__ . '/AbstractTool.php';

class DisableExtension extends AbstractTool {

	public function name() {
		return 'fm_disable_extension';
	}

	public function description() {
		return 'Delete/disable an extension. Params: ext (required). Requires confirm:true to execute.';
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
		return 'write:extension';
	}

	public function permissionLevel() { return self::PERM_WRITE; }
	public function execute($params, $context) {
		$ext = $params['ext'];
		$confirm = !empty($params['confirm']) && $params['confirm'] === true;

		$device = $this->freepbx->Core->getDevice($ext);
		$user = $this->freepbx->Core->getUser($ext);

		if (empty($device) && empty($user)) {
			throw new \Exception("Extension {$ext} not found");
		}

		$preview = [
			'action' => 'disable_extension',
			'extension' => $ext,
			'name' => !empty($user['name']) ? $user['name'] : 'unknown',
			'tech' => !empty($device['tech']) ? $device['tech'] : 'unknown',
		];

		if (!$confirm) {
			return [
				'dry_run' => true,
				'message' => "Would delete extension {$ext} ({$preview['name']}). This removes the device and user config. Pass confirm:true to execute.",
				'preview' => $preview,
			];
		}

		$this->freepbx->Core->delDevice($ext, false);
		$this->freepbx->Core->delUser($ext, false);

		return [
			'dry_run' => false,
			'message' => "Extension {$ext} has been deleted",
			'needs_reload' => true,
		];
	}
}
