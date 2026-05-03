<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class ListContacts extends AbstractTool {
	public function name() { return 'fm_list_contacts'; }
	public function description() { return 'List contact groups from the Contact Manager. Optional: group_id (show entries for a specific group).'; }
	public function validate($params) { return true; }
	public function execute($params, $context) {
		if (!empty($params['group_id'])) {
			$entries = $this->freepbx->Contactmanager->getEntriesByGroupID($params['group_id']);
			$result = [];
			if (!empty($entries)) {
				foreach ($entries as $entry) {
					$numbers = $this->freepbx->Contactmanager->getNumbersByEntryID($entry['id']);
					$numList = [];
					foreach ($numbers as $n) { $numList[] = $n['number'] ?? ''; }
					$result[] = [
						'id' => $entry['id'],
						'name' => trim(($entry['fname'] ?? '') . ' ' . ($entry['lname'] ?? '')),
						'company' => $entry['company'] ?? '',
						'numbers' => $numList,
					];
				}
			}
			return ['count' => count($result), 'contacts' => $result, 'group_id' => $params['group_id']];
		}

		$groups = $this->freepbx->Contactmanager->getGroups();
		$result = [];
		if (!empty($groups)) {
			foreach ($groups as $g) {
				$entries = $this->freepbx->Contactmanager->getEntriesByGroupID($g['id']);
				$result[] = [
					'id' => $g['id'],
					'name' => $g['name'] ?? '',
					'type' => $g['type'] ?? '',
					'entries' => count($entries),
				];
			}
		}
		return ['count' => count($result), 'groups' => $result];
	}
}
