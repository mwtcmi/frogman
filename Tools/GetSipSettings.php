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

		// Transport info from AMI (no BMO equivalent for live PJSIP transport state).
		$astman = $this->freepbx->astman;
		if ($astman && $astman->connected()) {
			$res = $astman->Command('pjsip show transports');
			$body = trim($res['data'] ?? '');
			$body = preg_replace('/^Privilege:\s*Command\s*\R?/m', '', $body, 1);
			$result['transports'] = trim($body);
		}

		// RTP range + external IP through the Sipsettings BMO so a FreePBX update
		// can change the underlying storage (kvstore key, conf file path, table
		// name) without breaking us.
		$ss = $this->freepbx->Sipsettings;
		$rtpStart = $ss->getConfig('rtpstart');
		$rtpEnd = $ss->getConfig('rtpend');
		$externIp = $ss->getConfig('externip');
		$localNets = $ss->getConfig('localnetworks');
		if ($rtpStart !== false && $rtpStart !== '') $result['rtp_start'] = $rtpStart;
		if ($rtpEnd !== false && $rtpEnd !== '') $result['rtp_end'] = $rtpEnd;
		if ($externIp !== false && $externIp !== '') $result['external_ip'] = $externIp;
		if ($localNets !== false && $localNets !== '') $result['local_networks'] = $localNets;

		return $result;
	}
}
