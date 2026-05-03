<?php
namespace FreePBX\modules\Frogman\Tools;

require_once __DIR__ . '/AbstractTool.php';

class AddExtension extends AbstractTool {

	public function name() {
		return 'fm_add_extension';
	}

	public function description() {
		return 'Create a new PJSIP extension. Params: ext (required), name (required), secret, vm (yes/no), vmpwd, email. Requires confirm:true to execute.';
	}

	public function validate($params) {
		if (empty($params['ext'])) {
			return 'Parameter "ext" is required';
		}
		if (!preg_match('/^\d+$/', $params['ext'])) {
			return 'Parameter "ext" must be numeric';
		}
		if (empty($params['name'])) {
			return 'Parameter "name" is required';
		}
		return true;
	}

	public function requiredPermission() {
		return 'write:extension';
	}

	public function permissionLevel() { return self::PERM_WRITE; }
	public function execute($params, $context) {
		$ext = $params['ext'];
		$name = $params['name'];
		$secret = $params['secret'] ?? bin2hex(random_bytes(8));
		$vm = $params['vm'] ?? 'no';
		$vmpwd = $params['vmpwd'] ?? '';
		$email = $params['email'] ?? '';
		$confirm = !empty($params['confirm']) && $params['confirm'] === true;

		// Check if extension already exists
		$existing = $this->freepbx->Core->getDevice($ext);
		if (!empty($existing)) {
			throw new \Exception("Extension {$ext} already exists");
		}

		$preview = [
			'action' => 'add_extension',
			'extension' => $ext,
			'name' => $name,
			'tech' => 'pjsip',
			'secret' => $secret,
			'voicemail' => $vm,
		];

		if (!$confirm) {
			return [
				'dry_run' => true,
				'message' => "Would create extension {$ext} ({$name}) as PJSIP. Pass confirm:true to execute.",
				'preview' => $preview,
			];
		}

		$data = [
			'extension' => $ext,
			'name' => $name,
			'tech' => 'pjsip',
			'outboundcid' => '',
			'secret' => $secret,
			'vm' => $vm,
			'vmpwd' => $vmpwd,
			'email' => $email,
		];

		$result = $this->freepbx->Core->processQuickCreate('pjsip', $ext, $data);

		if (empty($result['status'])) {
			throw new \Exception("Failed to create extension {$ext}");
		}

		return [
			'dry_run' => false,
			'message' => "Extension {$ext} ({$name}) created successfully",
			'extension' => $ext,
			'secret' => $secret,
			'needs_reload' => true,
		];
	}
}
