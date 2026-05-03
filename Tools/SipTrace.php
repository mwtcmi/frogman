<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class SipTrace extends AbstractTool {
	public function name() { return 'fm_sip_trace'; }
	public function description() { return 'Capture a time-bounded SIP trace via AMI. Params: action (required: "start", "stop", "status"), duration (seconds, default 10, max 30). Start begins capture, stop ends and returns captured data. Auto-stops after duration.'; }

	public function permissionLevel() { return self::PERM_ADMIN; }

	public function validate($params) {
		if (empty($params['action'])) return 'Parameter "action" is required (start, stop, or status)';
		if (!in_array($params['action'], ['start', 'stop', 'status'])) return 'Parameter "action" must be start, stop, or status';
		if (isset($params['duration'])) {
			$d = (int)$params['duration'];
			if ($d < 1 || $d > 30) return 'Parameter "duration" must be between 1 and 30 seconds';
		}
		return true;
	}

	public function execute($params, $context) {
		$astman = $this->freepbx->astman;
		if (!$astman || !$astman->connected()) throw new \Exception('Cannot connect to Asterisk Manager');

		$action = $params['action'];
		$traceFile = '/tmp/frogman_sip_trace.log';

		if ($action === 'start') {
			$duration = isset($params['duration']) ? min((int)$params['duration'], 30) : 10;

			// Clear any previous trace
			@file_put_contents($traceFile, '');

			// Enable PJSIP logger via AMI — output goes to Asterisk full log
			$astman->Command('pjsip set logger on');

			// Schedule auto-stop: write a marker file with expiry timestamp
			$expiry = time() + $duration;
			@file_put_contents($traceFile . '.meta', json_encode([
				'started' => time(),
				'duration' => $duration,
				'expiry' => $expiry,
			]));

			// Capture from Asterisk log — tail the log for SIP messages during the window
			$logFile = '/var/log/asterisk/full';
			$startMark = date('Y-m-d H:i:s');

			// Run the capture in background with timeout
			$cmd = sprintf(
				'timeout %d grep -a --line-buffered "PJSIP\\|SIP/2.0\\|sip:\\|CSeq\\|Via:\\|From:\\|To:\\|Call-ID\\|INVITE\\|BYE\\|REGISTER\\|ACK\\|CANCEL\\|OPTIONS" %s > %s 2>/dev/null &',
				$duration + 2,
				escapeshellarg($logFile),
				escapeshellarg($traceFile)
			);
			exec($cmd);

			return [
				'status' => 'started',
				'duration' => $duration,
				'auto_stop_at' => date('Y-m-d H:i:s', $expiry),
				'note' => "Trace running for {$duration}s. Use action=stop to retrieve results, or wait for auto-stop.",
			];
		}

		if ($action === 'stop') {
			// Disable PJSIP logger
			$astman->Command('pjsip set logger off');

			// Kill any running capture
			exec('pkill -f "frogman_sip_trace" 2>/dev/null');

			// Read captured data
			$traceData = '';
			if (file_exists($traceFile)) {
				$traceData = @file_get_contents($traceFile);
				// Cap output at 50KB to avoid flooding chat
				if (strlen($traceData) > 50000) {
					$traceData = substr($traceData, 0, 50000) . "\n\n... [TRUNCATED — trace exceeded 50KB] ...";
				}
			}

			$meta = null;
			if (file_exists($traceFile . '.meta')) {
				$meta = json_decode(@file_get_contents($traceFile . '.meta'), true);
				@unlink($traceFile . '.meta');
			}

			// Parse SIP messages from trace
			$messages = $this->parseSipMessages($traceData);

			// Cleanup
			@unlink($traceFile);

			return [
				'status' => 'stopped',
				'meta' => $meta,
				'message_count' => count($messages),
				'messages' => $messages,
				'raw_size_bytes' => strlen($traceData),
			];
		}

		if ($action === 'status') {
			$meta = null;
			$running = false;
			if (file_exists($traceFile . '.meta')) {
				$meta = json_decode(@file_get_contents($traceFile . '.meta'), true);
				$running = $meta && time() < $meta['expiry'];
			}

			$currentSize = file_exists($traceFile) ? filesize($traceFile) : 0;

			return [
				'running' => $running,
				'meta' => $meta,
				'capture_size_bytes' => $currentSize,
			];
		}
	}

	private function parseSipMessages($data) {
		if (empty($data)) return [];

		$messages = [];
		$lines = explode("\n", $data);
		foreach ($lines as $line) {
			$line = trim($line);
			if (empty($line)) continue;

			// Look for SIP request/response lines
			if (preg_match('/(INVITE|BYE|REGISTER|ACK|CANCEL|OPTIONS|NOTIFY|SUBSCRIBE|REFER|INFO|UPDATE|PRACK)\s+sip:/i', $line, $m)) {
				$messages[] = ['type' => 'request', 'method' => strtoupper($m[1]), 'line' => $line];
			} elseif (preg_match('/SIP\/2\.0\s+(\d{3})\s+(.+)/i', $line, $m)) {
				$messages[] = ['type' => 'response', 'code' => $m[1], 'reason' => trim($m[2]), 'line' => $line];
			}
		}

		// Cap parsed messages at 200
		if (count($messages) > 200) {
			$messages = array_slice($messages, 0, 200);
			$messages[] = ['type' => 'notice', 'line' => '... truncated at 200 messages'];
		}

		return $messages;
	}
}
