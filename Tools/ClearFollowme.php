<?php
namespace FreePBX\modules\Frogman\Tools;

require_once __DIR__ . '/AbstractTool.php';

class ClearFollowme extends AbstractTool {

	public function name() {
		return 'fm_clear_followme';
	}

	public function description() {
		return 'Remove Follow Me configuration from an extension. Params: ext (required). Requires confirm:true.';
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

		$user = $this->freepbx->Core->getUser($ext);
		if (empty($user)) {
			throw new \Exception("Extension {$ext} not found");
		}

		$existing = $this->freepbx->Findmefollow->get($ext);
		if (empty($existing)) {
			return [
				'dry_run' => false,
				'message' => "No Follow Me configured for extension {$ext}. Nothing to clear.",
			];
		}

		if (!$confirm) {
			return [
				'dry_run' => true,
				'message' => "Would remove Follow Me from ext {$ext} (currently ringing: {$existing['grplist']}). Pass confirm:true to execute.",
				'current_config' => [
					'grplist' => $existing['grplist'],
					'strategy' => $existing['strategy'],
					'grptime' => $existing['grptime'],
				],
			];
		}

		$this->freepbx->Findmefollow->del($ext, true);

		return [
			'dry_run' => false,
			'message' => "Follow Me removed from extension {$ext}",
			'needs_reload' => true,
		];
	}
}
