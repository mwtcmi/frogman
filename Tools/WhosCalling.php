<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class WhosCalling extends AbstractTool {
	public function name() { return 'fm_whos_calling'; }
	public function description() { return 'Look up a phone number — check CDR history, contacts, and caller ID. Params: number (required).'; }
	public function validate($params) {
		if (empty($params['number'])) return 'Parameter "number" is required';
		return true;
	}
	public function execute($params, $context) {
		$number = $params['number'];
		$db = $this->freepbx->Database;
		$result = ['number' => $number];

		// Check CDR for this number
		$sth = $db->prepare("SELECT calldate, src, dst, disposition, duration, clid FROM asteriskcdrdb.cdr WHERE src = ? OR dst = ? ORDER BY calldate DESC LIMIT 10");
		$sth->execute([$number, $number]);
		$cdr = $sth->fetchAll(\PDO::FETCH_ASSOC);
		$result['cdr'] = ['count' => count($cdr), 'records' => $cdr];

		// Check if it's an internal extension
		$sth = $db->prepare("SELECT extension, name FROM users WHERE extension = ?");
		$sth->execute([$number]);
		$ext = $sth->fetch(\PDO::FETCH_ASSOC);
		if ($ext) $result['extension'] = $ext;

		// Check contacts
		try {
			$name = $this->freepbx->Contactmanager->getNamebyNumber($number);
			if ($name) $result['contact_name'] = $name;
		} catch (\Exception $e) {}

		// Check blacklist
		$astman = $this->freepbx->astman;
		if ($astman && $astman->connected()) {
			$bl = $astman->database_get('blacklist', $number);
			$result['blacklisted'] = !empty($bl);
		}

		return $result;
	}
}
