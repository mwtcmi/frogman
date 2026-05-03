<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class AddIvr extends AbstractTool {
	public function name() { return 'fm_add_ivr'; }
	public function description() { return 'Create an IVR via FreePBX. Params: name (required), description (optional), announcement (recording ID, optional), timeout (seconds, default 10), invalid_destination (dest string, optional), timeout_destination (dest string, optional), entries (array of {selection, dest}, required). Requires confirm:true.'; }
	public function validate($params) {
		if (empty($params['name'])) return 'Parameter "name" is required';
		if (empty($params['entries']) || !is_array($params['entries'])) return 'Parameter "entries" is required (array of {selection, dest})';
		return true;
	}
	public function permissionLevel() { return self::PERM_WRITE; }
	public function execute($params, $context) {
		$confirm = !empty($params['confirm']) && $params['confirm'] === true;
		$name = $params['name'];
		$entries = $params['entries'];

		if (!$confirm) {
			$entryList = [];
			foreach ($entries as $e) {
				$entryList[] = "{$e['selection']} → {$e['dest']}";
			}
			return ['dry_run' => true, 'message' => "Would create IVR \"{$name}\" with entries: " . implode(', ', $entryList) . ". Reply yes to confirm."];
		}

		$vals = [
			'id' => null,
			'name' => $name,
			'description' => $params['description'] ?? '',
			'announcement' => $params['announcement'] ?? null,
			'directdial' => $params['directdial'] ?? 'ext-local',
			'invalid_loops' => $params['invalid_loops'] ?? '3',
			'invalid_retry_recording' => $params['invalid_retry_recording'] ?? '',
			'invalid_destination' => $params['invalid_destination'] ?? '',
			'timeout_enabled' => $params['timeout_enabled'] ?? '1',
			'invalid_recording' => $params['invalid_recording'] ?? '',
			'retvm' => $params['retvm'] ?? '0',
			'timeout_time' => $params['timeout'] ?? 10,
			'timeout_recording' => $params['timeout_recording'] ?? '',
			'timeout_retry_recording' => $params['timeout_retry_recording'] ?? '',
			'timeout_destination' => $params['timeout_destination'] ?? '',
			'timeout_loops' => $params['timeout_loops'] ?? '3',
			'timeout_append_announce' => 1,
			'invalid_append_announce' => 1,
			'timeout_ivr_ret' => 0,
			'invalid_ivr_ret' => 0,
			'alertinfo' => '',
			'rvolume' => '',
			'strict_dial_timeout' => 2,
			'accept_pound_key' => 0,
		];

		$id = $this->freepbx->Ivr->saveDetails($vals);

		// Save entries using saveEntry (array of entries format)
		$entryData = [];
		foreach ($entries as $e) {
			$entryData[] = [
				'ivr_id' => $id,
				'selection' => $e['selection'],
				'dest' => $e['dest'],
				'ivr_ret' => $e['ivr_ret'] ?? 0,
			];
		}
		$this->freepbx->Ivr->saveEntry($id, $entryData);

		return ['dry_run' => false, 'message' => "IVR \"{$name}\" created (ID: {$id})", 'id' => $id, 'needs_reload' => true];
	}
}
