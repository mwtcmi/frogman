<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class GetSipSettings extends AbstractTool {
	public function name() { return 'fm_get_sip_settings'; }
	public function description() { return 'Get SIP/PJSIP settings — transports, bind address, RTP range.'; }
	public function validate($params) { return true; }
	public function requiredPermission() { return null; }
	public function execute($params, $context) {
		$result = [
			'sip_driver' => $this->freepbx->Config->get('ASTSIPDRIVER') ?: 'chan_pjsip',
		];

		// Get transport info from AMI
		$astman = $this->freepbx->astman;
		if ($astman && $astman->connected()) {
			$res = $astman->Command('pjsip show transports');
			$result['transports'] = trim($res['data'] ?? '');
		}

		// RTP range from rtp config files
		$rtpConf = @file_get_contents('/etc/asterisk/rtp_additional.conf') ?: '';
		if (preg_match('/rtpstart\s*=\s*(\d+)/', $rtpConf, $m)) $result['rtp_start'] = $m[1];
		if (preg_match('/rtpend\s*=\s*(\d+)/', $rtpConf, $m)) $result['rtp_end'] = $m[1];

		// External IP from sipsettings kvstore or pjsip config
		$db = $this->freepbx->Database;
		$sth = $db->prepare("SELECT keyword, data FROM kvstore_Sipsettings WHERE keyword IN ('externip','localnetworks') AND id = 'noid'");
		try {
			$sth->execute();
			$rows = $sth->fetchAll(\PDO::FETCH_KEY_PAIR);
			if (!empty($rows['externip'])) $result['external_ip'] = $rows['externip'];
			if (!empty($rows['localnetworks'])) $result['local_networks'] = $rows['localnetworks'];
		} catch (\Exception $e) {
			// kvstore may not have these keys
		}

		return $result;
	}
}
