<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class ListFeatureCodes extends AbstractTool {
	public function name() { return 'fm_list_feature_codes'; }
	public function description() { return 'List all feature codes with their status.'; }
	public function validate($params) { return true; }
	public function requiredPermission() { return null; }
	public function execute($params, $context) {
		$features = $this->freepbx->Featurecodeadmin->getAllFeaturesDetailed();
		$result = [];
		if (!empty($features)) {
			foreach ($features as $fc) {
				if (!is_array($fc) || empty($fc['featurename'])) continue;
				$code = $fc['customcode'] ?: $fc['defaultcode'] ?? '';
				$enabled = !empty($fc['featureenabled']) && !empty($fc['moduleenabled']);
				$result[] = [
					'code' => $code,
					'description' => $fc['featuredescription'] ?? '',
					'module' => $fc['moduledescription'] ?? $fc['modulename'] ?? '',
					'enabled' => $enabled,
				];
			}
		}
		// Sort: enabled first, then by code
		usort($result, function($a, $b) {
			if ($a['enabled'] !== $b['enabled']) return $b['enabled'] - $a['enabled'];
			return strcmp($a['code'], $b['code']);
		});
		return ['count' => count($result), 'feature_codes' => $result];
	}
}
