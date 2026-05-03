<?php
namespace FreePBX\modules\Frogman\Tools;

require_once __DIR__ . '/AbstractTool.php';

class UpdateExtension extends AbstractTool {

	public function name() {
		return 'fm_update_extension';
	}

	public function description() {
		return 'Update an existing extension. Params: ext (required), plus any fields to change: name, secret, outboundcid. Requires confirm:true to execute.';
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

		// Verify extension exists
		$device = $this->freepbx->Core->getDevice($ext);
		if (empty($device)) {
			throw new \Exception("Extension {$ext} not found");
		}

		$user = $this->freepbx->Core->getUser($ext);

		// Build change set
		$changes = [];
		if (isset($params['name']) && $params['name'] !== $user['name']) {
			$changes['name'] = ['from' => $user['name'], 'to' => $params['name']];
		}
		if (isset($params['secret']) && $params['secret'] !== $device['secret']) {
			$changes['secret'] = ['from' => '***', 'to' => '***'];
		}
		if (isset($params['outboundcid']) && $params['outboundcid'] !== $user['outboundcid']) {
			$changes['outboundcid'] = ['from' => $user['outboundcid'], 'to' => $params['outboundcid']];
		}

		if (empty($changes)) {
			return [
				'dry_run' => false,
				'message' => "No changes detected for extension {$ext}",
			];
		}

		if (!$confirm) {
			return [
				'dry_run' => true,
				'message' => "Would update extension {$ext}. Pass confirm:true to execute.",
				'changes' => $changes,
			];
		}

		// Use the GraphQL update approach: delete + recreate with merged data
		$userman = $this->freepbx->Userman->getUserByUsername($ext);

		$merged = array_merge($user, $device);
		if (isset($params['name'])) {
			$merged['name'] = $params['name'];
			$merged['description'] = $params['name'];
		}
		if (isset($params['secret'])) {
			$merged['secret'] = $params['secret'];
		}
		if (isset($params['outboundcid'])) {
			$merged['outboundcid'] = $params['outboundcid'];
		}

		$merged['extension'] = $ext;
		$merged['tech'] = $device['tech'];
		$merged['vm'] = 'no';
		$merged['vmpwd'] = '';
		$merged['email'] = '';

		$this->freepbx->Core->delDevice($ext, true);
		$this->freepbx->Core->delUser($ext, true);
		if (!empty($userman)) {
			$this->freepbx->Userman->deleteUserByID($userman['id']);
		}

		$result = $this->freepbx->Core->processQuickCreate($device['tech'], $ext, $merged);

		if (empty($result['status'])) {
			throw new \Exception("Failed to update extension {$ext}");
		}

		return [
			'dry_run' => false,
			'message' => "Extension {$ext} updated successfully",
			'changes' => $changes,
			'needs_reload' => true,
		];
	}
}
