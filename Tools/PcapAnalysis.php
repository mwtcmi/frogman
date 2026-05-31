<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class PcapAnalysis extends AbstractTool {
	const MAX_FILE_BYTES = 104857600; // 100 MiB
	const MAX_PACKET_BYTES = 262144;
	const MAX_TCP_STREAM_BYTES = 1048576; // 1 MiB per directional stream

	public function name() { return 'fm_analyze_pcap'; }
	public function description() { return 'Read-only SIP decoder for classic .pcap captures. Params: path (required), call_id (optional), max_messages (default 200, max 500), max_calls (default 25, max 100). Returns SIP ladders, call summaries, and agent-facing analysis grouped by Call-ID.'; }
	public function permissionLevel() { return self::PERM_ADMIN; }

	public function validate($params) {
		if (empty($params['path']) || !is_string($params['path'])) {
			return 'Parameter "path" is required';
		}
		$err = null;
		$this->resolvePath($params['path'], $err);
		if ($err !== null) return $err;
		if (isset($params['call_id']) && (!is_string($params['call_id']) || trim($params['call_id']) === '')) {
			return 'Parameter "call_id" must be a non-empty string when supplied';
		}
		foreach (['max_messages' => 500, 'max_calls' => 100] as $key => $max) {
			if (isset($params[$key])) {
				$v = (int)$params[$key];
				if ($v < 1 || $v > $max) return "Parameter \"{$key}\" must be between 1 and {$max}";
			}
		}
		return true;
	}

	public function execute($params, $context) {
		$err = null;
		$path = $this->resolvePath($params['path'], $err);
		if ($err !== null) throw new \Exception($err);

		$maxMessages = isset($params['max_messages']) ? min((int)$params['max_messages'], 500) : 200;
		$maxCalls = isset($params['max_calls']) ? min((int)$params['max_calls'], 100) : 25;
		$filterCallId = isset($params['call_id']) ? trim((string)$params['call_id']) : null;

		$data = @file_get_contents($path);
		if ($data === false) throw new \Exception("Unable to read capture file");

		$decoded = $this->decodePcap($data, $maxMessages, $filterCallId);
		if (!empty($decoded['unsupported'])) {
			return $decoded + [
				'path' => $path,
				'file_size_bytes' => strlen($data),
			];
		}

		$calls = $this->groupByCallId($decoded['messages'], $maxCalls);
		$summary = $this->summariseCapture($calls, $decoded);

		return [
			'status' => 'ok',
			'path' => $path,
			'file_size_bytes' => strlen($data),
			'linktype' => $decoded['linktype'],
			'packet_count' => $decoded['packet_count'],
			'sip_message_count' => count($decoded['messages']),
			'call_count' => count($calls),
			'truncated' => $decoded['truncated'],
			'warnings' => $decoded['warnings'],
			'analysis' => $summary,
			'calls' => $calls,
		];
	}

	private function captureBases() {
		return [
			'/var/spool/asterisk/frogman/captures',
			'/var/spool/asterisk/packetcapture',
		];
	}

	private function resolvePath($path, &$err) {
		$err = null;
		$path = trim((string)$path);
		if ($path === '') {
			$err = 'Parameter "path" is required';
			return null;
		}
		if (strpos($path, "\0") !== false) {
			$err = 'Path contains an invalid NUL byte';
			return null;
		}
		if (!preg_match('/\.(?:pcap|cap)$/i', $path)) {
			$err = 'Capture path must end in .pcap or .cap';
			return null;
		}
		$real = realpath($path);
		if ($real === false || !is_file($real)) {
			$err = 'Capture path does not resolve to a file';
			return null;
		}
		if (!preg_match('/\.(?:pcap|cap)$/i', $real)) {
			$err = 'Resolved capture path must end in .pcap or .cap';
			return null;
		}
		if (!is_readable($real)) {
			$err = 'Capture file is not readable';
			return null;
		}
		$size = @filesize($real);
		if ($size === false || $size < 24) {
			$err = 'Capture file is too small to be a classic pcap';
			return null;
		}
		if ($size > self::MAX_FILE_BYTES) {
			$err = 'Capture file exceeds the 100 MiB safety limit';
			return null;
		}

		foreach ($this->captureBases() as $base) {
			$baseReal = realpath($base);
			if ($baseReal === false || !is_dir($baseReal)) continue;
			$prefix = rtrim($baseReal, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
			if ($real === $baseReal || strpos($real, $prefix) === 0) {
				return $real;
			}
		}

		$err = 'Capture path is outside the allowlisted capture directories: ' . implode(', ', $this->captureBases());
		return null;
	}

	private function decodePcap($data, $maxMessages, $filterCallId) {
		$warnings = [];
		$magic = substr($data, 0, 4);
		$hex = bin2hex($magic);

		if ($hex === '0a0d0d0a') {
			return [
				'status' => 'unsupported',
				'unsupported' => true,
				'reason' => 'pcapng_not_supported',
				'hint' => 'Convert to classic pcap first, for example: editcap -F pcap input.pcapng output.pcap',
			];
		}

		$littleEndian = null;
		$fracScale = 1000000;
		if ($hex === 'd4c3b2a1' || $hex === '4d3cb2a1') {
			$littleEndian = true;
			if ($hex === '4d3cb2a1') $fracScale = 1000000000;
		} elseif ($hex === 'a1b2c3d4' || $hex === 'a1b23c4d') {
			$littleEndian = false;
			if ($hex === 'a1b23c4d') $fracScale = 1000000000;
		} else {
			return [
				'status' => 'unsupported',
				'unsupported' => true,
				'reason' => 'not_classic_pcap',
				'hint' => 'Only classic libpcap files are supported in v1.',
			];
		}

		$linktype = $this->u32($data, 20, $littleEndian);
		$offset = 24;
		$packetCount = 0;
		$messages = [];
		$tcpStreams = [];
		$truncated = false;
		$total = strlen($data);

		while ($offset + 16 <= $total) {
			$tsSec = $this->u32($data, $offset, $littleEndian);
			$tsFrac = $this->u32($data, $offset + 4, $littleEndian);
			$inclLen = $this->u32($data, $offset + 8, $littleEndian);
			$offset += 16;
			$packetCount++;

			if ($inclLen > self::MAX_PACKET_BYTES) {
				$warnings[] = "Packet {$packetCount} exceeded per-packet safety cap";
				$offset += $inclLen;
				continue;
			}
			if ($offset + $inclLen > $total) {
				$warnings[] = "Packet {$packetCount} is truncated in the capture file";
				$truncated = true;
				break;
			}

			$packet = substr($data, $offset, $inclLen);
			$offset += $inclLen;

			$decoded = $this->decodePacket($packet, $linktype);
			if ($decoded === null) continue;
			$tEpoch = $tsSec + ($tsFrac / $fracScale);

			if ($decoded['transport'] === 'TCP') {
				if ($decoded['payload'] === '') continue;
				$key = $decoded['stream_key'];
				if (!isset($tcpStreams[$key])) {
					$tcpStreams[$key] = [
						'src' => $decoded['src'],
						'dst' => $decoded['dst'],
						'transport' => 'TCP',
						'segments' => [],
						'bytes' => 0,
						'first_time' => date('c', $tsSec),
						'first_epoch' => $tEpoch,
					];
				}
				$tcpStreams[$key]['bytes'] += strlen($decoded['payload']);
				if ($tcpStreams[$key]['bytes'] > self::MAX_TCP_STREAM_BYTES) {
					$warnings[] = "TCP stream {$key} exceeded reassembly safety cap";
					continue;
				}
				$tcpStreams[$key]['segments'][] = [
					'seq' => $decoded['seq'],
					'payload' => $decoded['payload'],
					'time' => date('c', $tsSec),
					't_epoch' => $tEpoch,
				];
				continue;
			}

			foreach ($this->parseSipPayloads($decoded['payload']) as $msg) {
				if ($filterCallId !== null && strcasecmp($msg['call_id'] ?? '', $filterCallId) !== 0) continue;
				$msg['time'] = date('c', $tsSec);
				$msg['t_epoch'] = $tEpoch;
				$msg['src'] = $decoded['src'];
				$msg['dst'] = $decoded['dst'];
				$msg['transport'] = $decoded['transport'];
				$messages[] = $msg;
			}

			if (count($messages) >= $maxMessages) {
				$truncated = true;
				$warnings[] = "SIP message output capped at {$maxMessages}";
				break;
			}
		}

		if (!empty($tcpStreams)) {
			foreach ($this->parseTcpStreams($tcpStreams, $filterCallId, $warnings) as $msg) {
				$messages[] = $msg;
				if (count($messages) >= $maxMessages) {
					$truncated = true;
					$warnings[] = "SIP message output capped at {$maxMessages}";
					break;
				}
			}
			usort($messages, function($a, $b) {
				if ($a['t_epoch'] == $b['t_epoch']) return 0;
				return ($a['t_epoch'] < $b['t_epoch']) ? -1 : 1;
			});
		}

		return [
			'linktype' => $linktype,
			'packet_count' => $packetCount,
			'messages' => $messages,
			'truncated' => $truncated,
			'warnings' => array_values(array_unique($warnings)),
		];
	}

	private function decodePacket($packet, $linktype) {
		$ipOffset = null;
		$ipVersion = 4;
		if ($linktype === 1) {
			if (strlen($packet) < 14) return null;
			$ethType = $this->u16($packet, 12, false);
			$ipOffset = 14;
			while (($ethType === 0x8100 || $ethType === 0x88a8) && strlen($packet) >= $ipOffset + 4) {
				$ethType = $this->u16($packet, $ipOffset + 2, false);
				$ipOffset += 4;
			}
			if ($ethType === 0x86dd) $ipVersion = 6;
			elseif ($ethType !== 0x0800) return null;
		} elseif ($linktype === 113) {
			if (strlen($packet) < 16) return null;
			$proto = $this->u16($packet, 14, false);
			if ($proto === 0x86dd) $ipVersion = 6;
			elseif ($proto !== 0x0800) return null;
			$ipOffset = 16;
		} elseif ($linktype === 276) {
			if (strlen($packet) < 20) return null;
			$proto = $this->u16($packet, 0, false);
			if ($proto === 0x86dd) $ipVersion = 6;
			elseif ($proto !== 0x0800) return null;
			$ipOffset = 20;
		} else {
			return null;
		}

		$ip = substr($packet, $ipOffset);
		return $ipVersion === 6 ? $this->decodeIpv6($ip) : $this->decodeIpv4($ip);
	}

	private function decodeIpv4($ip) {
		if (strlen($ip) < 20) return null;
		$verIhl = ord($ip[0]);
		if (($verIhl >> 4) !== 4) return null;
		$ihl = ($verIhl & 0x0f) * 4;
		if ($ihl < 20 || strlen($ip) < $ihl) return null;
		$totalLen = $this->u16($ip, 2, false);
		if ($totalLen > 0 && $totalLen <= strlen($ip)) $ip = substr($ip, 0, $totalLen);

		$proto = ord($ip[9]);
		$srcIp = long2ip($this->u32($ip, 12, false));
		$dstIp = long2ip($this->u32($ip, 16, false));

		if ($proto === 17) {
			if (strlen($ip) < $ihl + 8) return null;
			$srcPort = $this->u16($ip, $ihl, false);
			$dstPort = $this->u16($ip, $ihl + 2, false);
			$payload = substr($ip, $ihl + 8);
			return [
				'transport' => 'UDP',
				'src' => "{$srcIp}:{$srcPort}",
				'dst' => "{$dstIp}:{$dstPort}",
				'payload' => $payload,
			];
		}

		if ($proto === 6) {
			if (strlen($ip) < $ihl + 20) return null;
			$srcPort = $this->u16($ip, $ihl, false);
			$dstPort = $this->u16($ip, $ihl + 2, false);
			$dataOffset = (ord($ip[$ihl + 12]) >> 4) * 4;
			if ($dataOffset < 20 || strlen($ip) < $ihl + $dataOffset) return null;
			$payload = substr($ip, $ihl + $dataOffset);
			return [
				'transport' => 'TCP',
				'src' => "{$srcIp}:{$srcPort}",
				'dst' => "{$dstIp}:{$dstPort}",
				'stream_key' => "{$srcIp}:{$srcPort}>{$dstIp}:{$dstPort}",
				'seq' => $this->u32($ip, $ihl + 4, false),
				'payload' => $payload,
			];
		}

		return null;
	}

	private function decodeIpv6($ip) {
		if (strlen($ip) < 40) return null;
		if ((ord($ip[0]) >> 4) !== 6) return null;
		$payloadLen = $this->u16($ip, 4, false);
		$next = ord($ip[6]);
		$srcIp = $this->formatIpv6(substr($ip, 8, 16));
		$dstIp = $this->formatIpv6(substr($ip, 24, 16));
		$offset = 40;
		$end = $payloadLen > 0 && 40 + $payloadLen <= strlen($ip) ? 40 + $payloadLen : strlen($ip);

		while (in_array($next, [0, 43, 60], true)) {
			if ($offset + 2 > $end) return null;
			$nextHdr = ord($ip[$offset]);
			$hdrLen = (ord($ip[$offset + 1]) + 1) * 8;
			if ($offset + $hdrLen > $end) return null;
			$next = $nextHdr;
			$offset += $hdrLen;
		}
		if ($next === 44 || $next === 51 || $next === 50) return null;

		if ($next === 17) {
			if ($offset + 8 > $end) return null;
			$srcPort = $this->u16($ip, $offset, false);
			$dstPort = $this->u16($ip, $offset + 2, false);
			$payload = substr($ip, $offset + 8, $end - ($offset + 8));
			return [
				'transport' => 'UDP',
				'src' => "[{$srcIp}]:{$srcPort}",
				'dst' => "[{$dstIp}]:{$dstPort}",
				'payload' => $payload,
			];
		}

		if ($next === 6) {
			if ($offset + 20 > $end) return null;
			$srcPort = $this->u16($ip, $offset, false);
			$dstPort = $this->u16($ip, $offset + 2, false);
			$dataOffset = (ord($ip[$offset + 12]) >> 4) * 4;
			if ($dataOffset < 20 || $offset + $dataOffset > $end) return null;
			$payload = substr($ip, $offset + $dataOffset, $end - ($offset + $dataOffset));
			return [
				'transport' => 'TCP',
				'src' => "[{$srcIp}]:{$srcPort}",
				'dst' => "[{$dstIp}]:{$dstPort}",
				'stream_key' => "[{$srcIp}]:{$srcPort}>[{$dstIp}]:{$dstPort}",
				'seq' => $this->u32($ip, $offset + 4, false),
				'payload' => $payload,
			];
		}

		return null;
	}

	private function parseTcpStreams($streams, $filterCallId, &$warnings) {
		$messages = [];
		foreach ($streams as $key => $stream) {
			usort($stream['segments'], function($a, $b) {
				if ($a['seq'] == $b['seq']) return 0;
				return ($a['seq'] < $b['seq']) ? -1 : 1;
			});
			$payload = '';
			$firstEpoch = null;
			$firstTime = null;
			foreach ($stream['segments'] as $segment) {
				if ($firstEpoch === null) {
					$firstEpoch = $segment['t_epoch'];
					$firstTime = $segment['time'];
				}
				$payload .= $segment['payload'];
				if (strlen($payload) > self::MAX_TCP_STREAM_BYTES) {
					$warnings[] = "TCP stream {$key} exceeded reassembly safety cap";
					continue 2;
				}
			}
			$parsed = $this->parseSipPayloads($payload);
			foreach ($parsed as $msg) {
				if ($filterCallId !== null && strcasecmp($msg['call_id'] ?? '', $filterCallId) !== 0) continue;
				$msg['time'] = $firstTime ?: date('c', 0);
				$msg['t_epoch'] = $firstEpoch ?: 0;
				$msg['src'] = $stream['src'];
				$msg['dst'] = $stream['dst'];
				$msg['transport'] = 'TCP';
				$msg['reassembled'] = count($stream['segments']) > 1;
				$messages[] = $msg;
			}
		}
		return $messages;
	}

	private function parseSipPayloads($payload) {
		if ($payload === '') return [];
		if (!$this->looksLikeSip($payload)) return [];

		$text = preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', '', $payload);
		$chunks = preg_split('/(?=SIP\/2\.0\s+\d{3}\b|(?:INVITE|ACK|BYE|CANCEL|REGISTER|OPTIONS|INFO|UPDATE|PRACK|REFER|NOTIFY|SUBSCRIBE)\s+\S+\s+SIP\/2\.0)/', $text, -1, PREG_SPLIT_NO_EMPTY);
		$messages = [];
		foreach ($chunks as $chunk) {
			$msg = $this->parseSipMessageText($chunk);
			if ($msg !== null) $messages[] = $msg;
		}
		return $messages;
	}

	private function parseSipMessageText($text) {
		$parts = preg_split("/\r\n\r\n|\n\n|\r\r/", $text, 2);
		$head = $parts[0] ?? '';
		$sdp = $parts[1] ?? '';
		$lines = preg_split('/\r\n|\n|\r/', $head);
		$first = trim((string)array_shift($lines));
		if ($first === '') return null;

		$isRequest = (bool)preg_match('/^[A-Z][A-Z0-9_-]+\s+\S+\s+SIP\/2\.0$/', $first);
		$isResponse = (bool)preg_match('/^SIP\/2\.0\s+\d{3}\b/', $first);
		if (!$isRequest && !$isResponse) return null;

		$headers = $this->parseHeaders($lines);
		$callId = $headers['call-id'] ?? $headers['i'] ?? null;
		if ($callId === null || trim($callId) === '') return null;

		$msg = [
			'type' => $isRequest ? 'request' : 'response',
			'line' => $first,
			'call_id' => trim($callId),
			'cseq' => isset($headers['cseq']) ? trim($headers['cseq']) : null,
			'from' => isset($headers['from']) ? trim($headers['from']) : (isset($headers['f']) ? trim($headers['f']) : null),
			'to' => isset($headers['to']) ? trim($headers['to']) : (isset($headers['t']) ? trim($headers['t']) : null),
			'reason' => isset($headers['reason']) ? trim($headers['reason']) : $this->statusReason($first),
			'sdp' => $this->summariseSdp($sdp),
		];
		if ($isRequest && preg_match('/^([A-Z][A-Z0-9_-]+)\s+/', $first, $m)) {
			$msg['method'] = $m[1];
		}
		if ($isResponse && preg_match('/^SIP\/2\.0\s+(\d{3})\s*(.*)$/', $first, $m)) {
			$msg['status_code'] = (int)$m[1];
			$msg['status_reason'] = trim($m[2]);
		}
		return $msg;
	}

	private function looksLikeSip($payload) {
		$sample = substr($payload, 0, 4096);
		if (preg_match('/^(?:SIP\/2\.0|INVITE|ACK|BYE|CANCEL|REGISTER|OPTIONS|INFO|UPDATE|PRACK|REFER|NOTIFY|SUBSCRIBE)\b/', $sample)) return true;
		return (bool)preg_match('/\r?\n(?:Call-ID|i|CSeq):\s*/i', $sample);
	}

	private function parseHeaders($lines) {
		$headers = [];
		$current = null;
		foreach ($lines as $line) {
			if ($line === '') break;
			if (($line[0] === ' ' || $line[0] === "\t") && $current !== null) {
				$headers[$current] .= ' ' . trim($line);
				continue;
			}
			if (!preg_match('/^([A-Za-z][A-Za-z0-9_-]*):\s*(.*)$/', $line, $m)) continue;
			$name = strtolower($m[1]);
			if (!in_array($name, ['call-id', 'i', 'cseq', 'from', 'f', 'to', 't', 'reason'], true)) {
				$current = null;
				continue;
			}
			$headers[$name] = trim($m[2]);
			$current = $name;
		}
		return $headers;
	}

	private function summariseSdp($sdp) {
		if (trim($sdp) === '') return null;
		$out = ['connection' => null, 'media' => []];
		foreach (preg_split('/\r\n|\n|\r/', $sdp) as $line) {
			$line = trim($line);
			if (strpos($line, 'c=') === 0 && $out['connection'] === null) {
				$out['connection'] = substr($line, 2);
			} elseif (strpos($line, 'm=') === 0) {
				$out['media'][] = substr($line, 2);
			}
		}
		if ($out['connection'] === null && empty($out['media'])) return null;
		return $out;
	}

	private function groupByCallId($messages, $maxCalls) {
		$calls = [];
		foreach ($messages as $msg) {
			$id = $msg['call_id'];
			if (!isset($calls[$id])) {
				if (count($calls) >= $maxCalls) continue;
				$calls[$id] = [
					'call_id' => $id,
					'message_count' => 0,
					'first_time' => $msg['time'],
					'last_time' => $msg['time'],
					'duration_ms' => 0,
					'summary' => [
						'from' => null,
						'to' => null,
						'endpoints' => [],
						'methods' => [],
						'status_codes' => [],
						'final_status' => null,
						'invite_final_status' => null,
						'release_reason' => null,
						'media' => [],
						'request_count' => 0,
						'response_count' => 0,
						'retransmissions' => 0,
						'largest_gap_ms' => 0,
						'outcome' => 'unknown',
						'observations' => [],
						'diagnostic_hints' => [],
					],
					'messages' => [],
					'_first_epoch' => $msg['t_epoch'],
					'_last_epoch' => $msg['t_epoch'],
					'_seen_messages' => [],
				];
			}
			$this->updateCallSummary($calls[$id]['summary'], $msg);
			$base = $calls[$id]['_first_epoch'];
			$msg['t_ms'] = (int)round(($msg['t_epoch'] - $base) * 1000);
			$this->trackMessageShape($calls[$id], $msg);
			unset($msg['t_epoch'], $msg['call_id']);
			$calls[$id]['messages'][] = $msg;
			$calls[$id]['message_count']++;
			$calls[$id]['last_time'] = $msg['time'];
			$calls[$id]['_last_epoch'] = $calls[$id]['_first_epoch'] + ($msg['t_ms'] / 1000);
			$calls[$id]['duration_ms'] = (int)round(($calls[$id]['_last_epoch'] - $calls[$id]['_first_epoch']) * 1000);
		}
		foreach ($calls as &$call) {
			$call['summary']['methods'] = array_keys($call['summary']['methods']);
			$call['summary']['status_codes'] = array_values($call['summary']['status_codes']);
			$call['summary']['media'] = array_values($call['summary']['media']);
			$call['summary']['endpoints'] = array_values($call['summary']['endpoints']);
			$call['summary']['observations'] = $this->deriveObservations($call);
			$call['summary']['outcome'] = $this->deriveOutcome($call);
			$call['summary']['diagnostic_hints'] = $this->deriveDiagnosticHints($call);
			unset($call['_seen_messages']);
			unset($call['_first_epoch'], $call['_last_epoch']);
		}
		unset($call);
		return array_values($calls);
	}

	private function updateCallSummary(&$summary, $msg) {
		if ($summary['from'] === null && !empty($msg['from'])) {
			$summary['from'] = $msg['from'];
		}
		if ($summary['to'] === null && !empty($msg['to'])) {
			$summary['to'] = $msg['to'];
		}
		if (!empty($msg['src'])) {
			$summary['endpoints'][$msg['src']] = [
				'address' => $msg['src'],
				'role' => 'source',
				'transport' => $msg['transport'] ?? null,
			];
		}
		if (!empty($msg['dst'])) {
			$summary['endpoints'][$msg['dst']] = [
				'address' => $msg['dst'],
				'role' => 'destination',
				'transport' => $msg['transport'] ?? null,
			];
		}
		if ($msg['type'] === 'request' && preg_match('/^([A-Z][A-Z0-9_-]+)\s+/', $msg['line'], $m)) {
			$summary['methods'][$m[1]] = true;
			$summary['request_count']++;
		}
		if ($msg['type'] === 'response' && preg_match('/^SIP\/2\.0\s+(\d{3})\s*(.*)$/', $msg['line'], $m)) {
			$summary['response_count']++;
			$code = (int)$m[1];
			$reason = trim($m[2]);
			$cseqMethod = null;
			if (!empty($msg['cseq']) && preg_match('/\b([A-Z][A-Z0-9_-]+)\s*$/', $msg['cseq'], $cm)) {
				$cseqMethod = $cm[1];
			}
			$status = ['code' => $code, 'reason' => $reason, 'cseq_method' => $cseqMethod];
			$summary['status_codes'][] = $status;
			if ($code >= 200) {
				$summary['final_status'] = $status;
				if ($cseqMethod === 'INVITE') {
					$summary['invite_final_status'] = $status;
				}
			}
		}
		if (!empty($msg['reason'])) {
			$summary['release_reason'] = $msg['reason'];
		}
		if (!empty($msg['sdp'])) {
			$key = json_encode($msg['sdp']);
			$summary['media'][$key] = $msg['sdp'];
		}
	}

	private function trackMessageShape(&$call, $msg) {
		$keyParts = [
			$msg['src'] ?? '',
			$msg['dst'] ?? '',
			$msg['line'] ?? '',
			$msg['cseq'] ?? '',
		];
		$key = md5(implode('|', $keyParts));
		if (isset($call['_seen_messages'][$key])) {
			$call['summary']['retransmissions']++;
		}
		$call['_seen_messages'][$key] = true;

		if (!empty($call['messages'])) {
			$prev = end($call['messages']);
			$prevMs = isset($prev['t_ms']) ? (int)$prev['t_ms'] : 0;
			$gap = max(0, (int)$msg['t_ms'] - $prevMs);
			if ($gap > $call['summary']['largest_gap_ms']) {
				$call['summary']['largest_gap_ms'] = $gap;
			}
		}
	}

	private function deriveObservations($call) {
		$obs = [];
		$methods = array_flip($call['summary']['methods']);
		$final = $call['summary']['invite_final_status'] ?: $call['summary']['final_status'];
		$hasInvite = isset($methods['INVITE']);
		$hasBye = isset($methods['BYE']);
		$hasCancel = isset($methods['CANCEL']);
		$hasAck = isset($methods['ACK']);

		if ($final !== null) {
			$code = (int)$final['code'];
			if ($code >= 500) {
				$obs[] = 'server_error_response';
			} elseif ($code === 401 || $code === 407) {
				$obs[] = 'authentication_challenge';
			} elseif ($code === 403) {
				$obs[] = 'forbidden_response';
			} elseif ($code === 404 || $code === 484) {
				$obs[] = 'number_or_route_not_found';
			} elseif ($code === 486 || $code === 600) {
				$obs[] = 'busy_response';
			} elseif ($code === 487 || $hasCancel) {
				$obs[] = 'cancelled_before_answer';
			} elseif ($code >= 400) {
				$obs[] = 'failed_final_response';
			} elseif ($code >= 200 && $code < 300 && $hasInvite) {
				$obs[] = 'answered_invite';
			}
		} elseif ($hasInvite) {
			$obs[] = 'invite_without_final_response_in_capture';
		}

		if ($hasInvite && isset($methods['PRACK'])) $obs[] = 'reliable_provisional_response_used';
		if ($hasInvite && !$hasAck && $final !== null && (int)$final['code'] >= 200 && (int)$final['code'] < 300) {
			$obs[] = 'answered_without_ack_seen';
		}
		if ($hasBye) $obs[] = 'normal_dialog_teardown_seen';
		if (!empty($call['summary']['media'])) $obs[] = 'sdp_present';
		if (!empty($call['summary']['retransmissions'])) $obs[] = 'retransmissions_seen';
		if (!empty($call['summary']['largest_gap_ms']) && $call['summary']['largest_gap_ms'] >= 3000) $obs[] = 'large_signalling_gap';
		foreach ($call['summary']['media'] as $media) {
			if (!empty($media['connection']) && $this->isPrivateAddressInSdp($media['connection'])) {
				$obs[] = 'private_sdp_connection_address';
				break;
			}
		}
		return array_values(array_unique($obs));
	}

	private function deriveOutcome($call) {
		$summary = $call['summary'];
		$final = $summary['invite_final_status'] ?: $summary['final_status'];
		$methods = array_flip($summary['methods']);
		if ($final !== null) {
			$code = (int)$final['code'];
			if ($code >= 200 && $code < 300 && isset($methods['INVITE'])) return 'answered';
			if ($code === 487 || isset($methods['CANCEL'])) return 'cancelled';
			if ($code === 486 || $code === 600) return 'busy';
			if ($code === 401 || $code === 407) return 'auth_challenge';
			if ($code >= 400) return 'failed';
			return 'completed';
		}
		if (isset($methods['INVITE'])) return 'incomplete_capture';
		if (isset($methods['REGISTER'])) return 'registration';
		if (isset($methods['OPTIONS'])) return 'qualify_or_options';
		return 'signalling_seen';
	}

	private function deriveDiagnosticHints($call) {
		$hints = [];
		$obs = array_flip($call['summary']['observations']);
		$outcome = $call['summary']['outcome'];
		if ($outcome === 'answered') {
			$hints[] = 'INVITE reached a 2xx final response; inspect RTP/media separately if audio was bad.';
		}
		if ($outcome === 'busy') {
			$hints[] = 'Remote or downstream endpoint returned busy; signalling itself completed with a busy final response.';
		}
		if ($outcome === 'cancelled') {
			$hints[] = 'Call was cancelled before answer; compare CANCEL timing with user action or upstream timeout.';
		}
		if ($outcome === 'failed') {
			$hints[] = 'INVITE ended in a failure response; check the final status and Reason header first.';
		}
		if (isset($obs['invite_without_final_response_in_capture'])) {
			$hints[] = 'Capture has an INVITE but no final response; capture may be one-sided, too short, or missing the answering path.';
		}
		if (isset($obs['answered_without_ack_seen'])) {
			$hints[] = '2xx response is present but ACK is missing in the capture; possible packet loss, asymmetric capture, or ACK routed elsewhere.';
		}
		if (isset($obs['retransmissions_seen'])) {
			$hints[] = 'Repeated identical SIP messages were seen; this often points at packet loss, no response, or an unreachable peer.';
		}
		if (isset($obs['large_signalling_gap'])) {
			$hints[] = 'There is a multi-second signalling gap; check timers, DNS, authentication challenge handling, and upstream response time.';
		}
		if (isset($obs['private_sdp_connection_address'])) {
			$hints[] = 'SDP advertises a private connection address; if either side is remote, NAT/media configuration is suspect.';
		}
		if (empty($hints)) {
			$hints[] = 'No obvious signalling fault flagged from SIP headers alone.';
		}
		return $hints;
	}

	private function summariseCapture($calls, $decoded) {
		$statuses = [];
		$observations = [];
		$transports = [];
		$outcomes = [];
		$topCalls = [];
		foreach ($calls as $call) {
			$final = $call['summary']['invite_final_status'] ?: ($call['summary']['final_status'] ?? null);
			if ($final !== null) {
				$key = (string)$final['code'];
				if (!isset($statuses[$key])) {
					$statuses[$key] = ['code' => (int)$final['code'], 'reason' => $final['reason'], 'count' => 0];
				}
				$statuses[$key]['count']++;
			}
			foreach ($call['summary']['observations'] as $obs) {
				$observations[$obs] = ($observations[$obs] ?? 0) + 1;
			}
			$outcome = $call['summary']['outcome'] ?? 'unknown';
			$outcomes[$outcome] = ($outcomes[$outcome] ?? 0) + 1;
			foreach ($call['messages'] as $msg) {
				$transport = $msg['transport'] ?? 'unknown';
				$transports[$transport] = ($transports[$transport] ?? 0) + 1;
			}
			$topCalls[] = [
				'call_id' => $call['call_id'],
				'outcome' => $outcome,
				'duration_ms' => $call['duration_ms'],
				'message_count' => $call['message_count'],
				'final_status' => $call['summary']['invite_final_status'] ?: $call['summary']['final_status'],
				'observations' => $call['summary']['observations'],
			];
		}
		usort($topCalls, function($a, $b) {
			if ($a['message_count'] === $b['message_count']) return $b['duration_ms'] <=> $a['duration_ms'];
			return $b['message_count'] <=> $a['message_count'];
		});
		return [
			'final_status_counts' => array_values($statuses),
			'observation_counts' => $observations,
			'outcome_counts' => $outcomes,
			'transport_counts' => $transports,
			'top_calls' => array_slice($topCalls, 0, 10),
			'reader_summary' => $this->deriveReaderSummary($calls, $decoded, $outcomes, $observations),
			'focus' => $this->deriveFocusCall($calls),
			'packet_count' => $decoded['packet_count'],
		];
	}

	private function deriveReaderSummary($calls, $decoded, $outcomes, $observations) {
		$callCount = count($calls);
		$sipCount = count($decoded['messages'] ?? []);
		$inviteStats = $this->countInviteOutcomes($calls);
		$nonInviteFailures = $this->countNonInviteFailures($calls);
		$lines = [];
		$lines[] = "This capture contains {$sipCount} SIP message(s) grouped into {$callCount} transaction(s) or call(s).";

		$answered = (int)($inviteStats['answered'] ?? 0);
		$completed = (int)($outcomes['completed'] ?? 0);
		$failed = (int)($inviteStats['failed'] ?? 0);
		$busy = (int)($inviteStats['busy'] ?? 0);
		$cancelled = (int)($inviteStats['cancelled'] ?? 0);
		$incomplete = (int)($inviteStats['incomplete_capture'] ?? 0);
		$authOnly = (int)($outcomes['auth_challenge'] ?? 0);

		if ($inviteStats['total'] > 0 && ($failed || $busy || $cancelled || $incomplete)) {
			$issues = [];
			if ($failed) $issues[] = "{$failed} failed";
			if ($busy) $issues[] = "{$busy} busy";
			if ($cancelled) $issues[] = "{$cancelled} cancelled";
			if ($incomplete) $issues[] = "{$incomplete} incomplete";
			$lines[] = "Attention: " . implode(', ', $issues) . " INVITE call flow(s) need review.";
		} elseif ($inviteStats['total'] > 0 && $answered) {
			$lines[] = "Main result: {$answered} INVITE call flow(s) reached 200 OK, so the captured calls look successful at SIP signalling level.";
		} elseif ($completed) {
			$ok = [];
			if ($completed) $ok[] = "{$completed} completed non-INVITE transaction(s)";
			$lines[] = "Main result: " . implode(', ', $ok) . " reached successful SIP responses.";
		} elseif ($authOnly) {
			$lines[] = "Main result: authentication challenges were seen; these are often normal for SIP digest auth unless the flow stops there.";
		} else {
			$lines[] = "Main result: SIP signalling was decoded, but no clear completed call outcome was identified.";
		}

		if ($nonInviteFailures > 0 && $inviteStats['total'] > 0 && !$failed && !$busy && !$cancelled && !$incomplete) {
			$lines[] = "{$nonInviteFailures} non-call transaction(s), such as OPTIONS/qualify checks, returned failure responses; that does not necessarily mean the captured calls failed.";
		}

		$notables = [];
		if (!empty($observations['retransmissions_seen'])) {
			$notables[] = "retransmissions were seen";
		}
		if (!empty($observations['large_signalling_gap'])) {
			$notables[] = "multi-second signalling gaps were seen";
		}
		if (!empty($observations['private_sdp_connection_address'])) {
			$notables[] = "private SDP media addresses were advertised";
		}
		if (!empty($observations['answered_without_ack_seen'])) {
			$notables[] = "at least one answered call had no ACK in the capture";
		}
		if (!empty($observations['number_or_route_not_found'])) {
			$notables[] = "a not-found response suggests a dialled number or route mismatch";
		}
		if (!empty($notables)) {
			$lines[] = "Notable clues: " . implode('; ', $notables) . ".";
		} else {
			$lines[] = "No obvious SIP signalling fault was flagged from headers alone.";
		}

		if ($callCount > 1) {
			$lines[] = "Best next step: re-run with a specific call_id to narrow the output to one ladder.";
		}

		return $lines;
	}

	private function countInviteOutcomes($calls) {
		$out = ['total' => 0];
		foreach ($calls as $call) {
			$methods = array_flip($call['summary']['methods'] ?? []);
			if (!isset($methods['INVITE'])) continue;
			$out['total']++;
			$outcome = $call['summary']['outcome'] ?? 'unknown';
			$out[$outcome] = ($out[$outcome] ?? 0) + 1;
		}
		return $out;
	}

	private function countNonInviteFailures($calls) {
		$count = 0;
		foreach ($calls as $call) {
			$methods = array_flip($call['summary']['methods'] ?? []);
			if (isset($methods['INVITE'])) continue;
			$outcome = $call['summary']['outcome'] ?? 'unknown';
			if ($outcome === 'failed') $count++;
		}
		return $count;
	}

	private function deriveFocusCall($calls) {
		$best = null;
		$bestScore = -1;
		$bestReason = null;
		foreach ($calls as $call) {
			$summary = $call['summary'] ?? [];
			$outcome = $summary['outcome'] ?? 'unknown';
			$methods = array_flip($summary['methods'] ?? []);
			$obs = array_flip($summary['observations'] ?? []);
			$score = 0;
			$reason = 'most relevant SIP ladder';
			if (isset($methods['INVITE']) && $outcome === 'failed') { $score += 120; $reason = 'failed INVITE call'; }
			elseif (isset($methods['INVITE']) && ($outcome === 'busy' || $outcome === 'cancelled')) { $score += 100; $reason = "{$outcome} INVITE call"; }
			elseif (isset($methods['INVITE']) && $outcome === 'incomplete_capture') { $score += 90; $reason = 'incomplete INVITE call'; }
			elseif (isset($methods['INVITE']) && $outcome === 'answered') { $score += 40; $reason = 'answered call with the most signalling detail'; }
			elseif ($outcome === 'failed') { $score += 35; $reason = 'failed non-call SIP transaction'; }
			elseif ($outcome === 'auth_challenge') { $score += 15; $reason = 'authentication challenge transaction'; }
			if (isset($obs['answered_without_ack_seen'])) $score += 40;
			if (isset($obs['number_or_route_not_found'])) $score += 35;
			if (isset($obs['retransmissions_seen'])) $score += 25;
			if (isset($obs['large_signalling_gap'])) $score += 20;
			if (isset($obs['private_sdp_connection_address'])) $score += 10;
			$score += min((int)($call['message_count'] ?? 0), 20);
			if ($score > $bestScore) {
				$bestScore = $score;
				$best = $call['call_id'] ?? null;
				$bestReason = $reason;
			}
		}
		if ($best === null) return null;
		return ['call_id' => $best, 'reason' => $bestReason];
	}

	private function isPrivateAddressInSdp($connection) {
		if (!preg_match('/\bIP4\s+([0-9.]+)/i', $connection, $m)) return false;
		$ip = $m[1];
		$long = ip2long($ip);
		if ($long === false) return false;
		$long = sprintf('%u', $long);
		$ranges = [
			['10.0.0.0', '10.255.255.255'],
			['172.16.0.0', '172.31.255.255'],
			['192.168.0.0', '192.168.255.255'],
			['169.254.0.0', '169.254.255.255'],
			['127.0.0.0', '127.255.255.255'],
		];
		foreach ($ranges as $range) {
			$start = sprintf('%u', ip2long($range[0]));
			$end = sprintf('%u', ip2long($range[1]));
			if ($long >= $start && $long <= $end) return true;
		}
		return false;
	}

	private function statusReason($line) {
		if (preg_match('/^SIP\/2\.0\s+\d{3}\s+(.+)$/', $line, $m)) {
			return trim($m[1]);
		}
		return null;
	}

	private function u16($data, $offset, $littleEndian) {
		if ($offset + 2 > strlen($data)) return 0;
		$v = unpack($littleEndian ? 'v' : 'n', substr($data, $offset, 2));
		return (int)$v[1];
	}

	private function u32($data, $offset, $littleEndian) {
		if ($offset + 4 > strlen($data)) return 0;
		$v = unpack($littleEndian ? 'V' : 'N', substr($data, $offset, 4));
		return (int)$v[1];
	}

	private function formatIpv6($bytes) {
		if (strlen($bytes) !== 16) return '';
		$parts = unpack('n8', $bytes);
		$hex = [];
		foreach ($parts as $p) $hex[] = dechex($p);
		return implode(':', $hex);
	}
}
