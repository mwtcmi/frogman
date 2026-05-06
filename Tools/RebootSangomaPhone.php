<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class RebootSangomaPhone extends AbstractTool {
	public function name() { return 'fm_reboot_sangoma_phone'; }
	public function description() { return 'Reboot a Sangoma phone via DPMA NOTIFY. Phone goes down for ~30 seconds. Sangoma/DPMA phones only — does nothing on Yealink, Polycom, etc. Params: ext (required), confirm (true to execute).'; }

	public function validate($params) {
		if (empty($params['ext'])) return 'Parameter "ext" is required';
		if (!preg_match('/^\d+$/', $params['ext'])) return 'Parameter "ext" must be numeric';
		return true;
	}

	public function requiredPermission() { return null; }
	public function permissionLevel() { return self::PERM_WRITE; }

	public function execute($params, $context) {
		$ext = $params['ext'];
		$confirm = !empty($params['confirm']) && $params['confirm'] === true;

		$endpoint = \FreePBX::Endpoint();

		// Verify the phone is actually a Sangoma phone before we'd reboot it.
		// EPM signature: endpointGetMapping($ext, $orderby, $detail); single result in [0].
		$mapping = null;
		try {
			$rows = $endpoint->endpointGetMapping("{$ext}-1", '', true) ?: [];
			$mapping = $rows[0] ?? null;
		} catch (\Throwable $e) {
			$mapping = null;
		}
		if (empty($mapping)) {
			return ['dry_run' => !$confirm, 'error' => "Extension {$ext} has no EPM mapping — cannot reboot a phone that isn't provisioned via Endpoint Manager."];
		}
		$brand = strtolower($mapping['brand'] ?? $mapping['vendor'] ?? '');
		$isSangoma = (strpos($brand, 'sangoma') !== false) || (strpos($brand, 'digium') !== false);
		if (!$isSangoma) {
			return ['dry_run' => !$confirm, 'error' => "Extension {$ext} is mapped to a {$brand} phone, not Sangoma/Digium — DPMA reboot would have no effect. Use the brand-appropriate reboot tool."];
		}

		if (!$confirm) {
			$model = $mapping['model'] ?? 'Sangoma phone';
			return [
				'dry_run' => true,
				'message' => "Would reboot Sangoma phone {$ext} ({$model}). Phone will go down for ~30 seconds. Reply yes to confirm.",
				'phone' => ['ext' => $ext, 'model' => $model, 'mac' => $mapping['mac'] ?? null],
			];
		}

		$result = null;
		try {
			$result = $endpoint->endpointRebootSangomaPhones([$ext]);
		} catch (\Throwable $e) {
			throw new \Exception('Reboot failed: ' . $e->getMessage());
		}

		return [
			'dry_run' => false,
			'message' => "Reboot NOTIFY sent to Sangoma phone {$ext}. Phone will be unavailable for ~30 seconds.",
			'result' => $result,
		];
	}
}
