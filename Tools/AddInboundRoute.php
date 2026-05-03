<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class AddInboundRoute extends AbstractTool {
	public function name() { return 'fm_add_inbound_route'; }
	public function description() { return 'Add an inbound route (DID). Params: extension (DID number, required), destination (required, e.g. "from-internal,1001,1"), description (optional), cidnum (optional). Requires confirm:true.'; }
	public function validate($params) {
		if (empty($params['extension'])) return 'Parameter "extension" is required';
		if (empty($params['destination'])) return 'Parameter "destination" is required';
		return true;
	}
	public function requiredPermission() { return null; }
	public function permissionLevel() { return self::PERM_WRITE; }
	public function execute($params, $context) {
		$confirm = !empty($params['confirm']) && $params['confirm'] === true;
		$incoming = [
			'extension' => $params['extension'],
			'cidnum' => $params['cidnum'] ?? '',
			'destination' => $params['destination'],
			'description' => $params['description'] ?? '',
			'privacyman' => 0, 'alertinfo' => '', 'ringing' => '', 'fanswer' => '',
			'mohclass' => 'default', 'grppre' => '', 'delay_answer' => 0, 'pricid' => '',
			'pmmaxretries' => '', 'pmminlength' => '', 'reversal' => '', 'rvolume' => '',
		];
		if (!$confirm) {
			return ['dry_run' => true, 'message' => "Would add inbound route: DID {$params['extension']} → {$params['destination']}. Reply yes to confirm.", 'route' => $incoming];
		}
		$dest = $incoming['destination'];
		unset($incoming['destination']);
		\FreePBX::Core()->addDID(array_merge($incoming, ['destination' => $dest]));
		return ['dry_run' => false, 'message' => "Inbound route added: DID {$params['extension']} → {$dest}", 'needs_reload' => true];
	}
}
