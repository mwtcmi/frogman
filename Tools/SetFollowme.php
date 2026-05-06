<?php
namespace FreePBX\modules\Frogman\Tools;

require_once __DIR__ . '/AbstractTool.php';

class SetFollowme extends AbstractTool {

	public function name() {
		return 'fm_set_followme';
	}

	public function description() {
		return 'Configure Follow Me for an extension. Params: ext (required), numbers (comma-separated list of numbers to ring), ringtime (seconds, default 20), strategy (default ringallv2-prim — ring desk first then external; or ringallv2/ringall-prim/ringall/hunt-prim/hunt/memoryhunt-prim/memoryhunt/firstnotonphone/firstavailable). Requires confirm:true.';
	}

	public function validate($params) {
		if (empty($params['ext'])) {
			return 'Parameter "ext" is required';
		}
		if (!preg_match('/^\d+$/', $params['ext'])) {
			return 'Parameter "ext" must be numeric';
		}
		if (empty($params['numbers'])) {
			return 'Parameter "numbers" is required (comma-separated list of numbers to ring)';
		}
		$validStrategies = [
			'ringallv2-prim', 'ringallv2',
			'ringall-prim', 'ringall',
			'hunt-prim', 'hunt',
			'memoryhunt-prim', 'memoryhunt',
			'firstnotonphone', 'firstavailable',
		];
		if (isset($params['strategy']) && !in_array($params['strategy'], $validStrategies)) {
			return 'Invalid strategy. Must be one of: ' . implode(', ', $validStrategies);
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

		// Findmefollow's BMO add() calls the global findmefollow_allusers() function defined
		// in the module's functions.inc.php. CLI bootstrap loads it; HTTP/MCP doesn't —
		// causing "undefined function" errors when this tool runs from chat. Load it explicitly.
		$this->freepbx->Modules->loadFunctionsInc('findmefollow');

		// Verify the extension exists
		$user = $this->freepbx->Core->getUser($ext);
		if (empty($user)) {
			throw new \Exception("Extension {$ext} not found");
		}

		$numbers = str_replace(',', '-', $params['numbers']);
		$ringtime = isset($params['ringtime']) ? (int) $params['ringtime'] : 20;
		$strategy = $params['strategy'] ?? 'ringallv2-prim';

		$preview = [
			'action' => 'set_followme',
			'extension' => $ext,
			'name' => $user['name'],
			'follow_list' => $numbers,
			'ring_time' => $ringtime,
			'strategy' => $strategy,
		];

		if (!$confirm) {
			return [
				'dry_run' => true,
				'message' => "Would configure Follow Me on ext {$ext}: ring {$numbers} for {$ringtime}s using {$strategy}. Pass confirm:true to execute.",
				'preview' => $preview,
			];
		}

		// Check if follow me already exists for this extension
		$existing = $this->freepbx->Findmefollow->get($ext);

		$data = [
			'strategy' => $strategy,
			'grptime' => $ringtime,
			'grplist' => $numbers,
			'postdest' => "ext-local,{$ext},dest",
			'grppre' => '',
			'annmsg_id' => '',
			'dring' => '',
			'needsconf' => '',
			'remotealert_id' => '',
			'toolate_id' => '',
			'ringing' => 'Ring',
			'pre_ring' => 0,
			'ddial' => '',
			'changecid' => 'default',
			'fixedcid' => '',
		];

		if (!empty($existing)) {
			$this->freepbx->Findmefollow->del($ext);
		}
		$this->freepbx->Findmefollow->add($ext, $data);

		return [
			'dry_run' => false,
			'message' => "Follow Me configured for ext {$ext}: ring {$numbers} for {$ringtime}s",
			'needs_reload' => true,
		];
	}
}
