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
				$db = $this->freepbx->Database;
				foreach ($entries as $entry) {
					// Schema varies by group type. Internal (User Manager-backed) groups key
					// the array by uid and pre-populate the numbers field. External contact
					// groups use 'id' and require a getNumbersByEntryID() lookup.
					$entryId = $entry['id'] ?? $entry['uid'] ?? null;
					$name = trim(($entry['fname'] ?? '') . ' ' . ($entry['lname'] ?? ''));
					if ($name === '') $name = trim($entry['displayname'] ?? '');
					// Last resort for User Manager groups with blank user records — fall
					// back to the FreePBX extension's name from core users table.
					if ($name === '' && !empty($entry['default_extension'])) {
						$sth = $db->prepare("SELECT name FROM users WHERE extension = ?");
						$sth->execute([$entry['default_extension']]);
						$name = $sth->fetchColumn() ?: '';
					}
					if ($name === '') $name = $entry['user'] ?? '';

					$numList = [];
					if (!empty($entry['numbers']) && is_array($entry['numbers'])) {
						// Internal group — numbers already on the entry. May be array of
						// strings or array of {number, type, flags} dicts.
						foreach ($entry['numbers'] as $n) {
							if (is_string($n)) $numList[] = $n;
							elseif (is_array($n)) $numList[] = $n['number'] ?? '';
						}
					} elseif ($entryId !== null) {
						$numbers = $this->freepbx->Contactmanager->getNumbersByEntryID($entryId) ?: [];
						foreach ($numbers as $n) { $numList[] = $n['number'] ?? ''; }
					}

					$result[] = [
						'id' => $entryId,
						'name' => $name,
						'company' => $entry['company'] ?? '',
						'extension' => $entry['default_extension'] ?? null,
						'numbers' => array_values(array_filter($numList)),
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
