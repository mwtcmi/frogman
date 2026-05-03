<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class GetAdvancedSetting extends AbstractTool {
	public function name() { return 'fm_get_advanced_setting'; }
	public function description() { return 'Get a FreePBX advanced setting value. Params: key (required). Or pass key=list to list common settings.'; }
	public function validate($params) {
		if (empty($params['key'])) return 'Parameter "key" is required';
		return true;
	}
	public function requiredPermission() { return null; }
	public function execute($params, $context) {
		$key = strtoupper($params['key']);
		if ($key === 'LIST') {
			$common = ['AMPWEBROOT','AMPMGRUSER','ASTVERSION','ASTSIPDRIVER','AMPWEBADDRESS','AMPVMUMASK','AMPBADNUMBER','CLOBERFREEPBXCONF','USEDEVSTATE','BIKINITONE','FCBEEPONLY'];
			$result = [];
			foreach ($common as $k) {
				$result[$k] = $this->freepbx->Config->get($k);
			}
			return ['settings' => $result];
		}
		$value = $this->freepbx->Config->get($key);
		return ['key' => $key, 'value' => $value];
	}
}
