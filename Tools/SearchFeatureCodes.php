<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class SearchFeatureCodes extends AbstractTool {
	public function name() { return 'fm_search_feature_codes'; }
	public function description() { return 'Search feature codes by name or code. Params: query (required).'; }
	public function validate($params) { if (empty($params['query'])) return 'Parameter "query" is required';
		return true; }
	public function execute($params, $context) {
		$all = $this->freepbx->Featurecodeadmin->getAllFeaturesDetailed(); $results = []; $q = strtolower($params['query']); foreach($all as $fc) { if(!is_array($fc) || empty($fc['featurename'])) continue; if(stripos($fc['featuredescription'] ?? '', $q) !== false || stripos($fc['defaultcode'] ?? '', $q) !== false || stripos($fc['customcode'] ?? '', $q) !== false) { $results[] = ['name' => $fc['featuredescription'], 'code' => $fc['customcode'] ?: $fc['defaultcode'] ?? '', 'module' => $fc['moduledescription'] ?? '']; } } return ['query' => $params['query'], 'count' => count($results), 'results' => $results];
	}
}
