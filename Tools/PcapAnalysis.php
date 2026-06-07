<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class PcapAnalysis extends AbstractTool {
	const MAX_FILE_BYTES = 104857600; // 100 MiB
	const MAX_PACKET_BYTES = 262144;
	const MAX_TCP_STREAM_BYTES = 1048576; // 1 MiB per directional stream
	const MAX_TCP_REASSEMBLY_BYTES = 8388608; // 8 MiB total TCP payload retained
	const RTP_STRONG_TIMING_TOLERANCE_SECONDS = 2;
	const RTP_LOOSE_TIMING_TOLERANCE_SECONDS = 6;
	const RTP_ANSWERED_POST_SIGNALLING_TOLERANCE_SECONDS = 60;

	public function name() { return 'fm_analyze_pcap'; }
	public function description() { return 'Read-only SIP decoder for classic .pcap captures. Params: path (required), call_id (optional), max_messages (default 200, max 500), max_calls (default 25, max 100), summary_action/section/item_id optional for follow-up views. Returns SIP ladders, call summaries, and agent-facing analysis grouped by Call-ID.'; }
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
		if (isset($params['summary_action'])) {
			$allowedActions = ['simplify', 'explain', 'evidence', 're_explain', 'show_evidence'];
			$allowedSections = ['response', 'support_summary', 'likely_next_checks', 'confidence_notes', 'diagnostic_hints'];
			if (!in_array($this->normalizeSummaryAction($params['summary_action']), ['simplify', 'explain', 'evidence'], true)) {
				return 'Parameter "summary_action" must be one of: ' . implode(', ', $allowedActions);
			}
			if (empty($params['section']) || !in_array($params['section'], $allowedSections, true)) {
				return 'Parameter "section" must be one of: ' . implode(', ', $allowedSections);
			}
			if (empty($params['item_id']) || !is_string($params['item_id']) || !preg_match('/^[a-z0-9_:-]+$/i', $params['item_id'])) {
				return 'Parameter "item_id" must be a non-empty summary item id';
			}
			if (isset($params['call_index']) && ((int)$params['call_index'] < 0 || (string)(int)$params['call_index'] !== (string)$params['call_index'])) {
				return 'Parameter "call_index" must be a non-negative integer when supplied';
			}
			if (isset($params['call_ref']) && (!is_string($params['call_ref']) || !preg_match('/^[a-f0-9]{12}$/i', $params['call_ref']))) {
				return 'Parameter "call_ref" must be a 12-character call reference when supplied';
			}
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
		$this->attachRtpAnalysis($calls, $decoded);
		$summary = $this->summariseCapture($calls, $decoded);

		if (isset($params['summary_action'])) {
			$params['summary_action'] = $this->normalizeSummaryAction($params['summary_action']);
			return $this->resolveSummaryAction($params, $path, $calls, $summary);
		}

		return [
			'status' => 'ok',
			'path' => $path,
			'file_size_bytes' => strlen($data),
			'linktype' => $decoded['linktype'],
			'packet_count' => $decoded['packet_count'],
			'sip_message_count' => count($decoded['messages']),
			'unparsed_sip_message_count' => (int)($decoded['unparsed_sip_message_count'] ?? 0),
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
		$tcpReassemblyBytes = 0;
		$tcpReassemblyCapHit = false;
		$rtpStreams = [];
		$rtcpStreams = [];
		$unparsedSipMessages = 0;
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
				$payloadLen = strlen($decoded['payload']);
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
				if ($tcpStreams[$key]['bytes'] + $payloadLen > self::MAX_TCP_STREAM_BYTES) {
					$tcpStreams[$key]['bytes'] += $payloadLen;
					$warnings[] = "TCP stream {$key} exceeded reassembly safety cap";
					continue;
				}
				if ($tcpReassemblyBytes + $payloadLen > self::MAX_TCP_REASSEMBLY_BYTES) {
					if (!$tcpReassemblyCapHit) {
						$mb = (int)(self::MAX_TCP_REASSEMBLY_BYTES / 1048576);
						$warnings[] = "TCP reassembly exceeded global {$mb} MiB safety cap; additional TCP payloads skipped";
						$tcpReassemblyCapHit = true;
					}
					continue;
				}
				$tcpStreams[$key]['bytes'] += $payloadLen;
				$tcpReassemblyBytes += $payloadLen;
				$tcpStreams[$key]['segments'][] = [
					'seq' => $decoded['seq'],
					'payload' => $decoded['payload'],
					'time' => date('c', $tsSec),
					't_epoch' => $tEpoch,
				];
				continue;
			}

			$isMediaPacket = $decoded['transport'] === 'UDP' && $this->looksLikeRtpOrRtcp($decoded['payload']);
			if ($isMediaPacket) {
				$this->updateRtpOrRtcpCounters($rtpStreams, $rtcpStreams, $decoded, $tEpoch, $tsSec);
				$parsed = ['messages' => [], 'unparsed' => 0, 'sip_like' => false];
			} else {
				$parsed = $this->parseSipPayloadsWithStats($decoded['payload']);
				$unparsedSipMessages += $parsed['unparsed'];
			}
			if (!empty($parsed['sip_like'])) {
				foreach ($parsed['messages'] as $msg) {
					if ($filterCallId !== null && strcasecmp($msg['call_id'] ?? '', $filterCallId) !== 0) continue;
					$msg['time'] = date('c', $tsSec);
					$msg['t_epoch'] = $tEpoch;
					$msg['src'] = $decoded['src'];
					$msg['dst'] = $decoded['dst'];
					$msg['transport'] = $decoded['transport'];
					$messages[] = $msg;
				}
			}

			if (count($messages) >= $maxMessages) {
				$truncated = true;
				$warnings[] = "SIP message output capped at {$maxMessages}";
				break;
			}
		}

		if (!empty($tcpStreams)) {
			$tcpParsed = $this->parseTcpStreams($tcpStreams, $filterCallId, $warnings);
			$unparsedSipMessages += $tcpParsed['unparsed'];
			foreach ($tcpParsed['messages'] as $msg) {
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
			'unparsed_sip_message_count' => $unparsedSipMessages,
			'rtp_streams' => array_values($rtpStreams),
			'rtcp_streams' => array_values($rtcpStreams),
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
				'src_ip' => $srcIp,
				'dst_ip' => $dstIp,
				'src_port' => $srcPort,
				'dst_port' => $dstPort,
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
				'src_ip' => $srcIp,
				'dst_ip' => $dstIp,
				'src_port' => $srcPort,
				'dst_port' => $dstPort,
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
				'src_ip' => $srcIp,
				'dst_ip' => $dstIp,
				'src_port' => $srcPort,
				'dst_port' => $dstPort,
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
				'src_ip' => $srcIp,
				'dst_ip' => $dstIp,
				'src_port' => $srcPort,
				'dst_port' => $dstPort,
				'stream_key' => "[{$srcIp}]:{$srcPort}>[{$dstIp}]:{$dstPort}",
				'seq' => $this->u32($ip, $offset + 4, false),
				'payload' => $payload,
			];
		}

		return null;
	}

	private function parseTcpStreams($streams, $filterCallId, &$warnings) {
		$messages = [];
		$unparsed = 0;
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
			$parsed = $this->parseSipPayloadsWithStats($payload);
			$unparsed += $parsed['unparsed'];
			foreach ($parsed['messages'] as $msg) {
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
		return ['messages' => $messages, 'unparsed' => $unparsed];
	}

	private function parseSipPayloads($payload) {
		return $this->parseSipPayloadsWithStats($payload)['messages'];
	}

	private function parseSipPayloadsWithStats($payload) {
		if ($payload === '') return ['messages' => [], 'unparsed' => 0, 'sip_like' => false];
		if (!$this->looksLikeSip($payload)) return ['messages' => [], 'unparsed' => 0, 'sip_like' => false];

		$text = preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', '', $payload);
		$chunks = preg_split('/(?=SIP\/2\.0\s+\d{3}\b|(?:INVITE|ACK|BYE|CANCEL|REGISTER|OPTIONS|INFO|UPDATE|PRACK|REFER|NOTIFY|SUBSCRIBE)\s+\S+\s+SIP\/2\.0)/', $text, -1, PREG_SPLIT_NO_EMPTY);
		$messages = [];
		$unparsed = 0;
		foreach ($chunks as $chunk) {
			$msg = $this->parseSipMessageText($chunk);
			if ($msg !== null) {
				$msg['_fingerprint'] = md5($chunk);
				$messages[] = $msg;
			} elseif (trim($chunk) !== '') {
				$unparsed++;
			}
		}
		return ['messages' => $messages, 'unparsed' => $unparsed, 'sip_like' => true];
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
		$callId = $headers['call-id'] ?? null;
		if ($callId === null || trim($callId) === '') return null;

		$msg = [
			'type' => $isRequest ? 'request' : 'response',
			'line' => $first,
			'call_id' => trim($callId),
			'cseq' => isset($headers['cseq']) ? trim($headers['cseq']) : null,
			'from' => isset($headers['from']) ? trim($headers['from']) : null,
			'to' => isset($headers['to']) ? trim($headers['to']) : null,
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
			$name = $this->canonicalSipHeaderName(strtolower($m[1]));
			if (!in_array($name, ['call-id', 'cseq', 'from', 'to', 'reason', 'content-type', 'content-length', 'contact', 'via'], true)) {
				$current = null;
				continue;
			}
			$headers[$name] = trim($m[2]);
			$current = $name;
		}
		return $headers;
	}

	private function canonicalSipHeaderName($name) {
		$map = [
			'i' => 'call-id',
			'f' => 'from',
			't' => 'to',
			'm' => 'contact',
			'v' => 'via',
			'c' => 'content-type',
			'l' => 'content-length',
		];
		return $map[$name] ?? $name;
	}

	private function looksLikeRtpOrRtcp($payload) {
		if (strlen((string)$payload) < 8) return false;
		$version = ord($payload[0]) >> 6;
		if ($version !== 2) return false;
		$secondByte = ord($payload[1]);
		if ($secondByte >= 200 && $secondByte <= 204) return true;
		$payloadType = $secondByte & 0x7f;
		if ($payloadType >= 64 && $payloadType <= 72) return false;
		if (strlen($payload) < 12) return false;
		$cc = ord($payload[0]) & 0x0f;
		$headerLen = 12 + ($cc * 4);
		return strlen($payload) >= $headerLen;
	}

	private function updateRtpOrRtcpCounters(&$rtpStreams, &$rtcpStreams, $decoded, $tEpoch, $tsSec) {
		$payload = $decoded['payload'] ?? '';
		if (!$this->looksLikeRtpOrRtcp($payload)) return;
		$secondByte = ord($payload[1]);
		if ($secondByte >= 200 && $secondByte <= 204) {
			$key = ($decoded['src'] ?? '') . '>' . ($decoded['dst'] ?? '');
			if (!isset($rtcpStreams[$key])) {
				$rtcpStreams[$key] = [
					'src' => $decoded['src'] ?? '',
					'dst' => $decoded['dst'] ?? '',
					'src_ip' => $decoded['src_ip'] ?? '',
					'dst_ip' => $decoded['dst_ip'] ?? '',
					'src_port' => $decoded['src_port'] ?? null,
					'dst_port' => $decoded['dst_port'] ?? null,
					'packet_count' => 0,
					'packet_types' => [],
					'first_time' => date('c', $tsSec),
					'last_time' => date('c', $tsSec),
					'first_epoch' => $tEpoch,
					'last_epoch' => $tEpoch,
				];
			}
			$rtcpStreams[$key]['packet_count']++;
			$rtcpStreams[$key]['packet_types'][$secondByte] = true;
			$rtcpStreams[$key]['last_time'] = date('c', $tsSec);
			$rtcpStreams[$key]['last_epoch'] = $tEpoch;
			return;
		}
		$packetType = $secondByte & 0x7f;
		if ($packetType >= 64 && $packetType <= 72) return;
		if (strlen($payload) < 12) return;
		$cc = ord($payload[0]) & 0x0f;
		$headerLen = 12 + ($cc * 4);
		if (strlen($payload) < $headerLen) return;
		$payloadType = ord($payload[1]) & 0x7f;
		$seq = $this->u16($payload, 2, false);
		$ssrc = sprintf('%u', $this->u32($payload, 8, false));
		$key = ($decoded['src'] ?? '') . '>' . ($decoded['dst'] ?? '');
		if (!isset($rtpStreams[$key])) {
			$rtpStreams[$key] = [
				'src' => $decoded['src'] ?? '',
				'dst' => $decoded['dst'] ?? '',
				'src_ip' => $decoded['src_ip'] ?? '',
				'dst_ip' => $decoded['dst_ip'] ?? '',
				'src_port' => $decoded['src_port'] ?? null,
				'dst_port' => $decoded['dst_port'] ?? null,
				'packet_count' => 0,
				'payload_types' => [],
				'ssrcs' => [],
				'first_time' => date('c', $tsSec),
				'last_time' => date('c', $tsSec),
				'first_epoch' => $tEpoch,
				'last_epoch' => $tEpoch,
				'per_ssrc' => [],
			];
		}
		$stream =& $rtpStreams[$key];
		$stream['packet_count']++;
		$stream['payload_types'][$payloadType] = true;
		$stream['ssrcs'][$ssrc] = true;
		$stream['last_time'] = date('c', $tsSec);
		$stream['last_epoch'] = $tEpoch;
		if (!isset($stream['per_ssrc'][$ssrc])) {
			$stream['per_ssrc'][$ssrc] = [
				'packet_count' => 0,
				'lowest_sequence' => $seq,
				'highest_sequence' => $seq,
				'last_sequence' => $seq,
				'sequence_gaps' => 0,
				'sequence_wrap_seen' => false,
				'sequence_reorder_seen' => false,
			];
		}
		$ssrcStats =& $stream['per_ssrc'][$ssrc];
		$ssrcStats['packet_count']++;
		$lastSeq = (int)$ssrcStats['last_sequence'];
		if ($seq < $lastSeq) {
			if ($lastSeq === 65535 && $seq === 0) {
				$ssrcStats['sequence_wrap_seen'] = true;
			} else {
				$ssrcStats['sequence_reorder_seen'] = true;
			}
		} elseif ($seq > $lastSeq) {
			$gap = $seq - $ssrcStats['last_sequence'] - 1;
			if ($gap > 0 && $gap < 30000) $ssrcStats['sequence_gaps'] += $gap;
		}
		if ($seq < $ssrcStats['lowest_sequence']) $ssrcStats['lowest_sequence'] = $seq;
		if ($seq > $ssrcStats['highest_sequence']) $ssrcStats['highest_sequence'] = $seq;
		$ssrcStats['last_sequence'] = $seq;
		unset($ssrcStats, $stream);
	}

	private function summariseSdp($sdp) {
		if (trim($sdp) === '') return null;
		$out = ['connection' => null, 'media' => [], 'media_details' => [], 'rtpmap' => []];
		$currentMedia = null;
		foreach (preg_split('/\r\n|\n|\r/', $sdp) as $line) {
			$line = trim($line);
			if (strpos($line, 'c=') === 0 && $out['connection'] === null) {
				$out['connection'] = substr($line, 2);
			} elseif (strpos($line, 'm=') === 0) {
				$mline = substr($line, 2);
				$out['media'][] = $mline;
				$parts = preg_split('/\s+/', $mline);
				$currentMedia = count($out['media_details']);
				$out['media_details'][] = [
					'type' => $parts[0] ?? null,
					'port' => isset($parts[1]) ? (int)$parts[1] : null,
					'protocol' => $parts[2] ?? null,
					'payload_types' => array_slice($parts, 3),
					'connection' => $out['connection'],
				];
			} elseif (strpos($line, 'a=rtpmap:') === 0 && preg_match('/^a=rtpmap:(\d+)\s+([^\/\s]+)/i', $line, $m)) {
				$out['rtpmap'][(int)$m[1]] = $m[2];
			} elseif (strpos($line, 'c=') === 0 && $currentMedia !== null) {
				$out['media_details'][$currentMedia]['connection'] = substr($line, 2);
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
			unset($msg['t_epoch'], $msg['call_id'], $msg['_fingerprint']);
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
		$isProvisionalResponse = ($msg['type'] ?? '') === 'response' && isset($msg['status_code']) && (int)$msg['status_code'] < 200;
		$key = implode('|', [
			$msg['src'] ?? '',
			$msg['dst'] ?? '',
			$msg['transport'] ?? '',
			$msg['line'] ?? '',
			$msg['cseq'] ?? '',
			$msg['_fingerprint'] ?? '',
		]);
		if (!$isProvisionalResponse && !empty($msg['_fingerprint']) && isset($call['_seen_messages'][$key])) {
			$call['summary']['retransmissions']++;
		}
		if (!empty($msg['_fingerprint'])) $call['_seen_messages'][$key] = true;

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
		$cleanAnswered = $this->isCleanAnsweredCall($call);
		if ($outcome === 'answered') {
			$hints[] = $this->diagnosticHint('answered_invite', 'INVITE reached a 2xx final response; SIP signalling evidence supports an answered call.', 'high', ['answered_invite']);
		}
		if ($outcome === 'busy') {
			$hints[] = $this->diagnosticHint('busy_response', 'A 486/600 busy response was seen; the destination or downstream path appears busy.', 'high', ['busy_response']);
		}
		if ($outcome === 'cancelled') {
			$hints[] = $this->diagnosticHint('cancelled_before_answer', 'CANCEL/487 was seen before answer; user cancellation or an upstream timeout are possible explanations.', 'medium', ['cancelled_before_answer']);
		}
		if ($outcome === 'failed') {
			$methods = array_flip($call['summary']['methods'] ?? []);
			$isInvite = isset($methods['INVITE']);
			$failText = $isInvite
				? 'INVITE ended in a failure response; the final status and Reason header are the strongest evidence.'
				: 'SIP transaction ended in a failure response; the final status and Reason header are the strongest evidence.';
			$hints[] = $this->diagnosticHint('failed_final_response', $failText, 'high', ['failed_final_response']);
		}
		if (isset($obs['invite_without_final_response_in_capture'])) {
			$hints[] = $this->diagnosticHint('invite_without_final_response_in_capture', 'Capture has an INVITE but no final response; the capture may be one-sided, too short, or missing the answering path.', 'medium', ['invite_without_final_response_in_capture']);
		}
		if (isset($obs['answered_without_ack_seen'])) {
			$hints[] = $this->diagnosticHint('answered_without_ack_seen', '2xx response is present but ACK is missing in the capture; packet loss, asymmetric capture, or ACK routed elsewhere are possible.', 'medium', ['answered_without_ack_seen']);
		}
		if (isset($obs['retransmissions_seen']) && !$cleanAnswered) {
			$confidence = (($call['summary']['retransmissions'] ?? 0) >= 3 && $outcome !== 'answered') ? 'medium' : 'low';
			$hints[] = $this->diagnosticHint('retransmissions_seen', 'Byte-identical non-provisional SIP messages were repeated; packet loss, delayed response, or a peer retry are possible, but this is not proof of the cause.', $confidence, ['retransmissions_seen']);
		}
		if (isset($obs['large_signalling_gap'])) {
			$text = 'A multi-second signalling gap was measured; after provisional response this is commonly ring time or human answer delay, while timer, DNS, auth, or upstream delay remain possible.';
			$confidence = 'low';
			if ($outcome === 'failed' || isset($obs['invite_without_final_response_in_capture'])) {
				$text = 'A multi-second signalling gap occurred before a failure or before a final response was captured; timeout, routing, DNS, auth, or upstream response delay are possible.';
				$confidence = 'medium';
			}
			if (!$cleanAnswered) $hints[] = $this->diagnosticHint('large_signalling_gap', $text, $confidence, ['large_signalling_gap']);
		}
		if (isset($obs['private_sdp_connection_address'])) {
			$hints[] = $this->diagnosticHint('private_sdp_connection_address', 'SDP advertises a private connection address; if either side is remote, NAT or media-address configuration could be relevant.', 'medium', ['private_sdp_connection_address', 'sdp_present']);
		}
		if (isset($obs['rtp_one_direction_only'])) {
			$hints[] = $this->diagnosticHint('rtp_one_direction_only', 'RTP was seen in only one captured direction; media visibility asymmetry is possible, but this does not prove what either endpoint heard.', $call['summary']['rtp']['confidence'] ?? 'medium', ['rtp_one_direction_only']);
		}
		if (isset($obs['rtp_absent_despite_answer'])) {
			$hints[] = $this->diagnosticHint('rtp_absent_despite_answer', 'No RTP seen at this capture point for an answered call that negotiated media; direct endpoint-to-endpoint media bypassing the PBX is a common benign explanation.', 'low', ['rtp_absent_despite_answer', 'sdp_present']);
		}
		if (isset($obs['rtp_sequence_gaps'])) {
			$hints[] = $this->diagnosticHint('rtp_sequence_gaps', 'RTP sequence gaps were estimated from captured packets; possible loss, but capture-point loss and real network loss cannot be distinguished from this tap alone.', 'medium', ['rtp_sequence_gaps']);
		}
		if (isset($obs['codec_mismatch_vs_sdp'])) {
			$hints[] = $this->diagnosticHint('codec_mismatch_vs_sdp', 'Observed RTP payload types differ from SDP expectations; this is low-to-medium confidence because dynamic payload types are scoped by SDP and can be reused benignly.', 'low', ['codec_mismatch_vs_sdp', 'sdp_present']);
		}
		return $this->enrichSummaryLines($hints, [$call], null);
	}

	private function diagnosticHint($id, $text, $confidence, $observations) {
		return [
			'id' => $id,
			'text' => $text,
			'confidence' => $confidence,
			'observations' => array_values($observations),
		];
	}

	private function isCleanAnsweredCall($call) {
		$summary = $call['summary'] ?? [];
		if (($summary['outcome'] ?? null) !== 'answered') return false;
		$methods = array_flip($summary['methods'] ?? []);
		if (!isset($methods['BYE'])) return false;
		$reason = $summary['release_reason'] ?? '';
		if ($reason === '') return true;
		return (bool)preg_match('/(?:cause\s*=\s*16|\b16\b|normal call clearing)/i', $reason);
	}

	private function attachRtpAnalysis(&$calls, $decoded) {
		$rtpStreams = $decoded['rtp_streams'] ?? [];
		$rtcpStreams = $decoded['rtcp_streams'] ?? [];
		foreach ($calls as &$call) {
			$summary =& $call['summary'];
			$sdp = $this->extractSdpMediaPlan($summary['media'] ?? []);
			$matched = [];
			$matchedDirections = [];
			$confidence = 'high';
			foreach ($rtpStreams as $stream) {
				$match = $this->matchRtpStreamToCall($stream, $call, $sdp);
				if ($match === null) continue;
				if ($match['confidence'] !== 'high') $confidence = 'medium';
				$matchedDirections[] = [
					'src_ip' => $stream['src_ip'] ?? '',
					'dst_ip' => $stream['dst_ip'] ?? '',
				];
				$matched[] = $this->summariseRtpStream($stream, $sdp, $match);
			}
			$status = 'rtp_not_negotiated';
			if (!empty($matched)) {
				$status = $this->hasReciprocalRtpDirections($matchedDirections) ? 'rtp_both_directions' : 'rtp_one_direction_only';
			} elseif (!empty($sdp['ports'])) {
				$status = 'rtp_not_seen_at_capture_point';
				if (($summary['outcome'] ?? null) === 'answered') {
					$status = 'rtp_absent_despite_answer';
				} elseif (($summary['outcome'] ?? null) === 'cancelled') {
					$status = 'rtp_not_seen_before_cancellation';
				}
				$confidence = 'low';
			}
			$rtcpSeen = $this->rtcpSeenForCall($rtcpStreams, $call, $sdp);
			$sequenceGapPercent = $this->maxRtpLossEstimate($matched);
			$sequenceNotes = $this->rtpSequenceNotes($matched);
			$codecMismatch = $this->hasCodecMismatch($matched, $sdp);
			$summary['rtp'] = [
				'status' => $status,
				'confidence' => $confidence,
				'description' => $this->rtpDescription($status),
				'negotiated_media_ports' => array_values($sdp['ports']),
				'streams' => $matched,
				'rtcp_seen' => $rtcpSeen,
				'sequence_gap_estimate_percent' => $sequenceGapPercent,
				'sequence_notes' => $sequenceNotes,
				'codec_mismatch_vs_sdp' => $codecMismatch,
			];
			if ($status === 'rtp_both_directions' || $status === 'rtp_one_direction_only' || $status === 'rtp_absent_despite_answer') {
				$summary['observations'][] = $status;
			}
			if ($rtcpSeen) $summary['observations'][] = 'rtcp_seen';
			if ($sequenceGapPercent !== null && $sequenceGapPercent > 0) $summary['observations'][] = 'rtp_sequence_gaps';
			if ($codecMismatch) $summary['observations'][] = 'codec_mismatch_vs_sdp';
			$summary['observations'] = array_values(array_unique($summary['observations']));
			$summary['diagnostic_hints'] = $this->deriveDiagnosticHints($call);
			unset($summary);
		}
		unset($call);
	}

	private function extractSdpMediaPlan($mediaBlocks) {
		$out = ['ports' => [], 'payload_types' => [], 'rtpmap' => [], 'connection_ips' => []];
		foreach ($mediaBlocks as $media) {
			$this->addSdpConnectionIp($out, $media['connection'] ?? null);
			foreach ($media['media_details'] ?? [] as $detail) {
				if (($detail['type'] ?? null) !== 'audio') continue;
				$this->addSdpConnectionIp($out, $detail['connection'] ?? null);
				$port = (int)($detail['port'] ?? 0);
				if ($port > 0) $out['ports'][$port] = $port;
				foreach ($detail['payload_types'] ?? [] as $pt) {
					if (is_numeric($pt)) $out['payload_types'][(int)$pt] = true;
				}
			}
			foreach ($media['rtpmap'] ?? [] as $pt => $codec) {
				$out['rtpmap'][(int)$pt] = $codec;
			}
		}
		return $out;
	}

	private function addSdpConnectionIp(&$sdp, $connection) {
		if (!is_string($connection) || $connection === '') return;
		if (!preg_match('/\bIP[46]\s+([^\s]+)/i', $connection, $m)) return;
		$ip = trim($m[1], '[]');
		if ($ip !== '') $sdp['connection_ips'][$ip] = true;
	}

	private function matchRtpStreamToCall($stream, $call, $sdp) {
		$timing = $this->rtpTimingCorrelation($stream, $call);
		if ($timing === null) return null;

		$srcPort = (int)($stream['src_port'] ?? 0);
		$dstPort = (int)($stream['dst_port'] ?? 0);
		$ipCompatible = $this->rtpIpCompatibleWithCall($stream, $call, $sdp);
		if (isset($sdp['ports'][$srcPort]) || isset($sdp['ports'][$dstPort])) {
			if (!$ipCompatible) return null;
			return [
				'confidence' => $this->combineCorrelationConfidence('high', $timing['confidence']),
				'basis' => 'sdp_media_port+' . $timing['basis'],
				'timing' => $timing,
			];
		}
		if (empty($sdp['ports'])) return null;
		if (!$ipCompatible) return null;
		foreach ($sdp['ports'] as $port) {
			if (abs($srcPort - $port) <= 10 || abs($dstPort - $port) <= 10) {
				return [
					'confidence' => $this->combineCorrelationConfidence('medium', $timing['confidence']),
					'basis' => 'near_sdp_media_port+' . $timing['basis'],
					'timing' => $timing,
				];
			}
		}
		return null;
	}

	private function rtpIpCompatibleWithCall($stream, $call, $sdp) {
		$srcIp = $stream['src_ip'] ?? '';
		$dstIp = $stream['dst_ip'] ?? '';
		$endpointIps = $this->callEndpointIps($call);
		if ($srcIp !== '' && isset($endpointIps[$srcIp])) return true;
		if ($dstIp !== '' && isset($endpointIps[$dstIp])) return true;
		$connectionIps = $sdp['connection_ips'] ?? [];
		if ($srcIp !== '' && isset($connectionIps[$srcIp])) return true;
		if ($dstIp !== '' && isset($connectionIps[$dstIp])) return true;
		return false;
	}

	private function hasReciprocalRtpDirections($streams) {
		$seen = [];
		foreach ($streams as $stream) {
			$srcIp = $stream['src_ip'] ?? '';
			$dstIp = $stream['dst_ip'] ?? '';
			if ($srcIp === '' || $dstIp === '') continue;
			if (isset($seen[$dstIp . '>' . $srcIp])) return true;
			$seen[$srcIp . '>' . $dstIp] = true;
		}
		return false;
	}

	private function rtpTimingCorrelation($stream, $call) {
		$streamStart = $this->streamTimeEpoch($stream, 'first');
		$streamEnd = $this->streamTimeEpoch($stream, 'last');
		$callStart = $this->timeStringEpoch($call['first_time'] ?? null);
		$callEnd = $this->timeStringEpoch($call['last_time'] ?? null);
		if ($streamStart === null || $streamEnd === null || $callStart === null || $callEnd === null) {
			return ['confidence' => 'medium', 'basis' => 'timing_unavailable'];
		}
		if ($streamEnd < $streamStart) {
			$tmp = $streamStart;
			$streamStart = $streamEnd;
			$streamEnd = $tmp;
		}
		if ($callEnd < $callStart) {
			$tmp = $callStart;
			$callStart = $callEnd;
			$callEnd = $tmp;
		}

		$strict = self::RTP_STRONG_TIMING_TOLERANCE_SECONDS;
		if ($streamEnd >= ($callStart - $strict) && $streamStart <= ($callEnd + $strict)) {
			return [
				'confidence' => 'high',
				'basis' => 'time_overlap',
				'call_window' => ['first_time' => $call['first_time'] ?? null, 'last_time' => $call['last_time'] ?? null],
				'stream_window' => ['first_time' => $stream['first_time'] ?? null, 'last_time' => $stream['last_time'] ?? null],
				'tolerance_seconds' => $strict,
			];
		}

		$loosePre = self::RTP_LOOSE_TIMING_TOLERANCE_SECONDS;
		$loosePost = (($call['summary']['outcome'] ?? null) === 'answered')
			? self::RTP_ANSWERED_POST_SIGNALLING_TOLERANCE_SECONDS
			: self::RTP_LOOSE_TIMING_TOLERANCE_SECONDS;
		if ($streamEnd >= ($callStart - $loosePre) && $streamStart <= ($callEnd + $loosePost)) {
			return [
				'confidence' => 'medium',
				'basis' => 'loose_time_compatible',
				'call_window' => ['first_time' => $call['first_time'] ?? null, 'last_time' => $call['last_time'] ?? null],
				'stream_window' => ['first_time' => $stream['first_time'] ?? null, 'last_time' => $stream['last_time'] ?? null],
				'tolerance_seconds' => max($loosePre, $loosePost),
			];
		}

		return null;
	}

	private function combineCorrelationConfidence($a, $b) {
		$rank = ['low' => 0, 'medium' => 1, 'high' => 2];
		$ar = $rank[$a] ?? 1;
		$br = $rank[$b] ?? 1;
		return ($ar <= $br) ? $a : $b;
	}

	private function streamTimeEpoch($stream, $which) {
		$key = $which === 'last' ? 'last_epoch' : 'first_epoch';
		if (isset($stream[$key]) && is_numeric($stream[$key])) return (float)$stream[$key];
		$timeKey = $which === 'last' ? 'last_time' : 'first_time';
		return $this->timeStringEpoch($stream[$timeKey] ?? null);
	}

	private function timeStringEpoch($time) {
		if ($time === null || $time === '') return null;
		$epoch = strtotime((string)$time);
		return $epoch === false ? null : (float)$epoch;
	}

	private function callEndpointIps($call) {
		$out = [];
		foreach ($call['summary']['endpoints'] ?? [] as $endpoint) {
			$addr = $endpoint['address'] ?? '';
			if (preg_match('/^\[([^\]]+)\]:\d+$/', $addr, $m) || preg_match('/^([^:]+):\d+$/', $addr, $m)) {
				$out[$m[1]] = true;
			}
		}
		return $out;
	}

	private function summariseRtpStream($stream, $sdp, $match) {
		$payloadTypes = array_map('intval', array_keys($stream['payload_types'] ?? []));
		sort($payloadTypes);
		$ssrcs = array_keys($stream['ssrcs'] ?? []);
		$sequenceGaps = 0;
		$expected = 0;
		$lowest = null;
		$highest = null;
		$lossEstimateReliable = true;
		$sequenceNotes = [];
		foreach ($stream['per_ssrc'] ?? [] as $stats) {
			if (!empty($stats['sequence_wrap_seen'])) {
				$lossEstimateReliable = false;
				$sequenceNotes['sequence_wrap_seen'] = 'sequence wrap/reorder seen, loss not estimated';
			}
			if (!empty($stats['sequence_reorder_seen'])) {
				$lossEstimateReliable = false;
				$sequenceNotes['sequence_reorder_seen'] = 'sequence wrap/reorder seen, loss not estimated';
			}
			$sequenceGaps += (int)($stats['sequence_gaps'] ?? 0);
			$count = (int)($stats['packet_count'] ?? 0);
			$expected += $count + (int)($stats['sequence_gaps'] ?? 0);
			$low = (int)($stats['lowest_sequence'] ?? 0);
			$high = (int)($stats['highest_sequence'] ?? 0);
			$lowest = $lowest === null ? $low : min($lowest, $low);
			$highest = $highest === null ? $high : max($highest, $high);
		}
		$lossPercent = ($lossEstimateReliable && $expected > 0) ? round(($sequenceGaps / $expected) * 100, 2) : null;
		if (!$lossEstimateReliable && empty($sequenceNotes)) {
			$sequenceNotes['loss_not_estimated'] = 'sequence wrap/reorder seen, loss not estimated';
		}
		return [
			'src' => $stream['src'],
			'dst' => $stream['dst'],
			'packet_count' => (int)$stream['packet_count'],
			'ssrcs' => $ssrcs,
			'payload_types' => $payloadTypes,
			'codecs' => $this->codecNames($payloadTypes, $sdp),
			'lowest_sequence' => $lowest,
			'highest_sequence' => $highest,
			'sequence_gaps' => $sequenceGaps,
			'loss_estimate_percent' => $lossPercent,
			'loss_estimate_reliable' => $lossEstimateReliable,
			'sequence_notes' => array_values($sequenceNotes),
			'first_time' => $stream['first_time'],
			'last_time' => $stream['last_time'],
			'confidence' => $match['confidence'],
			'correlation_basis' => $match['basis'],
			'timing_correlation' => $match['timing'] ?? null,
		];
	}

	private function codecNames($payloadTypes, $sdp) {
		$static = [0 => 'PCMU', 3 => 'GSM', 4 => 'G723', 8 => 'PCMA', 9 => 'G722', 18 => 'G729'];
		$out = [];
		foreach ($payloadTypes as $pt) {
			if (isset($sdp['rtpmap'][$pt])) $out[$pt] = $sdp['rtpmap'][$pt];
			elseif (isset($static[$pt])) $out[$pt] = $static[$pt];
			else $out[$pt] = ($pt >= 96 && $pt <= 127) ? 'dynamic' : 'unknown';
		}
		return $out;
	}

	private function rtcpSeenForCall($rtcpStreams, $call, $sdp) {
		$endpointIps = $this->callEndpointIps($call);
		foreach ($rtcpStreams as $stream) {
			if ($this->rtpTimingCorrelation($stream, $call) === null) continue;
			if (!isset($endpointIps[$stream['src_ip'] ?? '']) && !isset($endpointIps[$stream['dst_ip'] ?? ''])) continue;
			foreach ($sdp['ports'] as $port) {
				if ((int)($stream['src_port'] ?? 0) === $port + 1 || (int)($stream['dst_port'] ?? 0) === $port + 1) return true;
			}
		}
		return false;
	}

	private function maxRtpLossEstimate($streams) {
		$max = null;
		foreach ($streams as $stream) {
			if (($stream['loss_estimate_percent'] ?? null) === null) continue;
			$max = $max === null ? $stream['loss_estimate_percent'] : max($max, $stream['loss_estimate_percent']);
		}
		return $max;
	}

	private function rtpSequenceNotes($streams) {
		$notes = [];
		foreach ($streams as $stream) {
			foreach ($stream['sequence_notes'] ?? [] as $note) {
				if ($note === 'sequence wrap/reorder seen, loss not estimated') {
					$note = 'sequence wrap/reorder seen, loss not estimated for affected streams';
				}
				$notes[$note] = $note;
			}
		}
		return array_values($notes);
	}

	private function hasCodecMismatch($streams, $sdp) {
		if (empty($sdp['payload_types'])) return false;
		foreach ($streams as $stream) {
			foreach ($stream['payload_types'] ?? [] as $pt) {
				if (!isset($sdp['payload_types'][(int)$pt])) return true;
			}
		}
		return false;
	}

	private function rtpDescription($status) {
		if ($status === 'rtp_both_directions') return 'RTP seen in both captured directions.';
		if ($status === 'rtp_one_direction_only') return 'RTP seen in only one captured direction.';
		if ($status === 'rtp_absent_despite_answer') return 'No RTP seen at this capture point; direct media can bypass this capture point.';
		if ($status === 'rtp_not_seen_before_cancellation') return 'No RTP seen at this capture point before cancellation.';
		if ($status === 'rtp_not_seen_at_capture_point') return 'No RTP seen at this capture point despite negotiated media.';
		return 'No negotiated RTP media was identified from SDP.';
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
				'is_invite_call_flow' => in_array('INVITE', $call['summary']['methods'] ?? [], true),
				'primary_method' => $this->primarySipMethod($call['summary']['methods'] ?? []),
				'final_status' => $call['summary']['invite_final_status'] ?: $call['summary']['final_status'],
				'observations' => $call['summary']['observations'],
			];
		}
		usort($topCalls, function($a, $b) {
			$rank = ['failed' => 6, 'busy' => 5, 'cancelled' => 4, 'incomplete_capture' => 3, 'answered' => 2];
			$ai = !empty($a['is_invite_call_flow']) ? 1 : 0;
			$bi = !empty($b['is_invite_call_flow']) ? 1 : 0;
			if ($ai !== $bi) return $bi <=> $ai;
			$ar = $rank[$a['outcome']] ?? 1;
			$br = $rank[$b['outcome']] ?? 1;
			if ($ar === $br) {
				if ($a['message_count'] === $b['message_count']) return $b['duration_ms'] <=> $a['duration_ms'];
				return $b['message_count'] <=> $a['message_count'];
			}
			return $br <=> $ar;
		});
		$finalStatusCounts = array_values($statuses);
		$focus = $this->deriveFocusCall($calls);
		$inviteOutcomes = $this->countInviteOutcomes($calls);
		$rtpSummary = $this->summariseRtpEvidence($calls);
		return [
			'final_status_counts' => $finalStatusCounts,
			'observation_counts' => $observations,
			'outcome_counts' => $outcomes,
			'transport_counts' => $transports,
			'invite_call_flow_count' => (int)($inviteOutcomes['total'] ?? 0),
			'sip_transaction_count' => count($calls),
			'top_calls' => array_slice($topCalls, 0, 10),
			'reader_summary' => $this->deriveReaderSummary($calls, $decoded, $outcomes, $observations),
			'support_summary' => $this->deriveSupportSummary($calls, $decoded, $outcomes, $observations, $inviteOutcomes, $rtpSummary),
			'likely_next_checks' => $this->deriveLikelyNextChecks($calls, $observations, $rtpSummary),
			'confidence_notes' => $this->deriveConfidenceNotes($calls, $decoded, $observations, $rtpSummary),
			'evidence_highlights' => $this->deriveEvidenceHighlights($calls, $decoded, $inviteOutcomes, $finalStatusCounts, $observations, $rtpSummary, $focus),
			'focus' => $focus,
			'packet_count' => $decoded['packet_count'],
		];
	}

	private function deriveSupportSummary($calls, $decoded, $outcomes, $observations, $inviteStats, $rtpSummary) {
		$lines = [];
		$totalInvites = (int)($inviteStats['total'] ?? 0);
		$answered = (int)($inviteStats['answered'] ?? 0);
		$busy = (int)($inviteStats['busy'] ?? 0);
		$failed = (int)($inviteStats['failed'] ?? 0);
		$cancelled = (int)($inviteStats['cancelled'] ?? 0);
		$incomplete = (int)($inviteStats['incomplete_capture'] ?? 0);

		if ($totalInvites === 0) {
			$lines[] = $this->supportLine(
				'no_invite_calls',
				'The capture does not include a decoded INVITE call flow, so it does not provide enough evidence to determine call setup behaviour.',
				'medium',
				['sip_message_count', 'invite_outcomes']
			);
		}
		if ($answered > 0) {
			$lines[] = $this->supportLine(
				'answered_invites',
				"{$answered} captured INVITE " . $this->pluralWord($answered, 'call flow') . " reached a 2xx final response at SIP signalling level.",
				'high',
				['invite_outcomes.answered', 'final_status_counts']
			);
		}
		if ($busy > 0) {
			$lines[] = $this->supportLine(
				'busy_invites',
				"{$busy} captured INVITE " . $this->pluralWord($busy, 'call flow') . ($busy === 1 ? " includes" : " include") . " a busy response.",
				'high',
				['invite_outcomes.busy', 'observation_counts.busy_response']
			);
		}
		if ($failed > 0) {
			$lines[] = $this->supportLine(
				'failed_invites',
				"{$failed} captured INVITE " . $this->pluralWord($failed, 'call flow') . " ended in failure responses; the strongest signalling evidence is the final SIP response and any Reason header.",
				'high',
				['invite_outcomes.failed', 'final_status_counts']
			);
		}
		if ($cancelled > 0) {
			$lines[] = $this->supportLine(
				'cancelled_invites',
				"{$cancelled} captured INVITE " . $this->pluralWord($cancelled, 'call flow') . " ended with cancellation evidence before answer.",
				'medium',
				['invite_outcomes.cancelled', 'observation_counts.cancelled_before_answer']
			);
		}
		if ($incomplete > 0) {
			$lines[] = $this->supportLine(
				'incomplete_invites',
				"{$incomplete} captured INVITE " . $this->pluralWord($incomplete, 'call flow') . " did not include a final response, so the capture may be incomplete, asymmetric, or too short for that flow.",
				'medium',
				['invite_outcomes.incomplete_capture']
			);
		}

		$answeredRtp = $rtpSummary['answered_status_counts'] ?? [];
		if (!empty($answeredRtp['rtp_both_directions'])) {
			$lines[] = $this->supportLine(
				'answered_rtp_both_directions',
				"For {$answeredRtp['rtp_both_directions']} answered " . $this->pluralWord($answeredRtp['rtp_both_directions'], 'call flow') . ", RTP was captured in both directions at this capture point.",
				$this->rtpStatusConfidence($rtpSummary, 'rtp_both_directions'),
				['rtp_summary.answered_status_counts.rtp_both_directions']
			);
		}
		if (!empty($answeredRtp['rtp_one_direction_only'])) {
			$lines[] = $this->supportLine(
				'answered_rtp_one_direction_only',
				"For {$answeredRtp['rtp_one_direction_only']} answered " . $this->pluralWord($answeredRtp['rtp_one_direction_only'], 'call flow') . ", RTP was captured in one direction only. This is consistent with possible media visibility asymmetry, but does not prove what either endpoint heard.",
				$this->rtpStatusConfidence($rtpSummary, 'rtp_one_direction_only'),
				['rtp_summary.answered_status_counts.rtp_one_direction_only', 'observation_counts.rtp_one_direction_only']
			);
		}
		if (!empty($answeredRtp['rtp_absent_despite_answer'])) {
			$lines[] = $this->supportLine(
				'answered_rtp_absent',
				"For {$answeredRtp['rtp_absent_despite_answer']} answered " . $this->pluralWord($answeredRtp['rtp_absent_despite_answer'], 'call flow') . ", no RTP seen at this capture point despite successful signalling and negotiated media. This does not prove that no media existed; direct media may have bypassed the PBX or capture location.",
				'low',
				['rtp_summary.answered_status_counts.rtp_absent_despite_answer', 'observation_counts.rtp_absent_despite_answer']
			);
		}
		$rtpStatusCounts = $rtpSummary['status_counts'] ?? [];
		if (!empty($rtpStatusCounts['rtp_not_seen_before_cancellation'])) {
			$lines[] = $this->supportLine(
				'rtp_not_seen_before_cancellation',
				"For {$rtpStatusCounts['rtp_not_seen_before_cancellation']} cancelled " . $this->pluralWord($rtpStatusCounts['rtp_not_seen_before_cancellation'], 'call flow') . ", SDP media was negotiated but no matching RTP was seen at this capture point before cancellation.",
				$this->rtpStatusConfidence($rtpSummary, 'rtp_not_seen_before_cancellation'),
				['rtp_summary.status_counts.rtp_not_seen_before_cancellation']
			);
		}
		if (!empty($rtpStatusCounts['rtp_not_seen_at_capture_point'])) {
			$lines[] = $this->supportLine(
				'rtp_not_seen_at_capture_point',
				"For {$rtpStatusCounts['rtp_not_seen_at_capture_point']} " . $this->pluralWord($rtpStatusCounts['rtp_not_seen_at_capture_point'], 'call flow') . ", SDP media was negotiated but no matching RTP was seen at this capture point.",
				$this->rtpStatusConfidence($rtpSummary, 'rtp_not_seen_at_capture_point'),
				['rtp_summary.status_counts.rtp_not_seen_at_capture_point']
			);
		}
		if (!empty($observations['private_sdp_connection_address'])) {
			$lines[] = $this->supportLine(
				'private_sdp_connection_address',
				'SDP advertised a private media address in at least one decoded call flow.',
				'medium',
				['observation_counts.private_sdp_connection_address']
			);
		}
		if (empty($lines)) {
			$lines[] = $this->supportLine(
				'insufficient_evidence',
				'The capture does not provide enough evidence to determine a specific cause.',
				'low',
				['sip_message_count', 'call_count']
			);
		}
		return $this->enrichSummaryLines($lines, $calls, $rtpSummary);
	}

	private function deriveLikelyNextChecks($calls, $observations, $rtpSummary) {
		$checks = [];
		if (!empty($observations['private_sdp_connection_address'])) {
			$checks[] = $this->supportLine(
				'check_nat_media_addresses',
				'Check External Address, Local Networks, and NAT/media-address configuration; confirm whether the advertised private SDP address is expected for this topology.',
				'medium',
				['observation_counts.private_sdp_connection_address']
			);
		}
		if (!empty($observations['rtp_one_direction_only'])) {
			$checks[] = $this->supportLine(
				'check_one_direction_rtp_visibility',
				'Compare SDP media addresses with observed RTP addresses, check whether direct media is enabled, and confirm whether this capture point should see both RTP directions.',
				'medium',
				['observation_counts.rtp_one_direction_only', 'rtp_summary.status_counts.rtp_one_direction_only']
			);
		}
		if (!empty($observations['rtp_absent_despite_answer'])) {
			$checks[] = $this->supportLine(
				'check_capture_location_for_absent_rtp',
				'Confirm capture location and whether RTP is expected to traverse this host; capture at the endpoint or media relay if needed.',
				'low',
				['observation_counts.rtp_absent_despite_answer']
			);
		}
		if (!empty($observations['retransmissions_seen'])) {
			$checks[] = $this->supportLine(
				'check_retransmission_context',
				'Compare repeated SIP messages with surrounding responses and review packet-loss or capture-loss indicators before assuming network loss.',
				'low',
				['observation_counts.retransmissions_seen']
			);
		}
		if (!empty($observations['large_signalling_gap'])) {
			$checks[] = $this->supportLine(
				'check_signalling_gap_context',
				'If a multi-second gap follows 180/183 responses, normal ring time is often the first explanation; if it occurs before provisional responses or before failure, review routing, DNS, authentication, and upstream response time.',
				'low',
				['observation_counts.large_signalling_gap']
			);
		}
		if (!empty($observations['answered_without_ack_seen'])) {
			$checks[] = $this->supportLine(
				'check_missing_ack_visibility',
				'For answered calls with no ACK in the capture, check capture asymmetry and routing before treating the ACK as truly absent.',
				'medium',
				['observation_counts.answered_without_ack_seen']
			);
		}
		if (empty($checks)) {
			$checks[] = $this->supportLine(
				'preserve_scope_or_capture_more',
				'No specific next check is strongly supported by decoded observations; narrow to a Call-ID or capture closer to the endpoint/media path if more detail is needed.',
				'low',
				['observation_counts', 'focus_call']
			);
		}
		return $this->enrichSummaryLines($checks, $calls, $rtpSummary);
	}

	private function deriveConfidenceNotes($calls, $decoded, $observations, $rtpSummary) {
		$notes = [];
		if (!empty($rtpSummary['status_counts'])) {
			$notes[] = $this->supportLine(
				'rtp_capture_point_scope',
				'RTP findings are based only on packets visible at this capture point.',
				'medium',
				['rtp_summary.status_counts']
			);
		}
		if (!empty($observations['rtp_absent_despite_answer'])) {
			$notes[] = $this->supportLine(
				'rtp_absence_not_proof',
				'Absence of RTP in this capture is not proof that no media existed.',
				'low',
				['observation_counts.rtp_absent_despite_answer']
			);
		}
		if (!empty($observations['rtp_sequence_gaps'])) {
			$notes[] = $this->supportLine(
				'rtp_sequence_gap_estimate_scope',
				'RTP sequence gaps are estimates from captured packets and cannot distinguish network loss from capture-point loss.',
				'medium',
				['observation_counts.rtp_sequence_gaps']
			);
		}
		if ($this->hasReassembledMessages($calls)) {
			$notes[] = $this->supportLine(
				'tcp_reassembly_scope',
				'TCP SIP messages recovered through simple reassembly carry lower confidence than complete packet-level SIP messages.',
				'medium',
				['transport_counts.TCP', 'messages.reassembled']
			);
		}
		if ((int)($decoded['unparsed_sip_message_count'] ?? 0) > 0) {
			$notes[] = $this->supportLine(
				'unparsed_sip_scope',
				'Unparsed SIP-like messages mean message and SIP transaction totals may be incomplete.',
				'medium',
				['unparsed_sip_message_count']
			);
		}
		if (!empty($observations['large_signalling_gap']) && $this->hasCleanAnsweredCalls($calls)) {
			$notes[] = $this->supportLine(
				'clean_answered_gap_scope',
				'For clean answered calls, a multi-second signalling gap can simply reflect ring time or human answer delay.',
				'low',
				['observation_counts.large_signalling_gap', 'outcome_counts.answered']
			);
		}
		if (empty($notes)) {
			$notes[] = $this->supportLine(
				'evidence_scope',
				'Interpretation is limited to decoded packets in this capture; the capture may not include every signalling or media path.',
				'low',
				['packet_count', 'sip_message_count']
			);
		}
		return $this->enrichSummaryLines($notes, $calls, $rtpSummary);
	}

	private function deriveEvidenceHighlights($calls, $decoded, $inviteOutcomes, $finalStatusCounts, $observations, $rtpSummary, $focus) {
		return [
			'sip_message_count' => count($decoded['messages'] ?? []),
			'call_count' => count($calls),
			'sip_transaction_count' => count($calls),
			'invite_call_flow_count' => (int)($inviteOutcomes['total'] ?? 0),
			'unparsed_message_count' => (int)($decoded['unparsed_sip_message_count'] ?? 0),
			'invite_outcomes' => $inviteOutcomes,
			'final_status_counts' => $finalStatusCounts,
			'rtp_summary' => $rtpSummary,
			'key_observations' => $this->explainObservationCounts($observations),
			'focus_call' => $focus,
		];
	}

	private function supportLine($id, $text, $confidence, $evidence) {
		return [
			'id' => $id,
			'text' => $text,
			'confidence' => $confidence,
			'evidence' => array_values($evidence),
		];
	}

	private function normalizeSummaryAction($action) {
		$action = strtolower(str_replace('-', '_', (string)$action));
		if ($action === 're_explain') return 'explain';
		if ($action === 'show_evidence') return 'evidence';
		return $action;
	}

	private function resolveSummaryAction($params, $path, $calls, $analysis) {
		if ((string)$params['item_id'] === 'block') {
			$items = $this->findSummaryActionBlock($calls, $analysis, $params['section'], $params['call_index'] ?? null, $params['call_ref'] ?? null);
			if (empty($items)) {
				return [
					'status' => 'not_found',
					'mode' => 'summary_action',
					'summary_action' => $params['summary_action'],
					'section' => $params['section'],
					'item_id' => $params['item_id'],
					'message' => 'That PCAP summary block was not found in the analysis result.',
				];
			}
			return [
				'status' => 'ok',
				'mode' => 'summary_action',
				'path' => $path,
				'summary_action' => $params['summary_action'],
				'section' => $params['section'],
				'item_id' => 'block',
				'block' => true,
				'call_id' => $params['call_id'] ?? null,
				'call_index' => isset($params['call_index']) ? (int)$params['call_index'] : null,
				'call_ref' => $params['call_ref'] ?? null,
				'focus_context' => $this->summaryActionFocusContext($params, $calls),
				'result' => $this->summaryBlockActionResult($params['summary_action'], $params['section'], $items, $analysis, $calls),
				'available_actions' => $this->summaryBlockActionAvailability($params['summary_action']),
			];
		}

		$item = $this->findSummaryActionItem($calls, $analysis, $params['section'], $params['item_id'], $params['call_index'] ?? null, $params['call_ref'] ?? null);
		if ($item === null) {
			return [
				'status' => 'not_found',
				'mode' => 'summary_action',
				'summary_action' => $params['summary_action'],
				'section' => $params['section'],
				'item_id' => $params['item_id'],
				'message' => 'That PCAP summary item was not found in the analysis result.',
			];
		}
		return [
			'status' => 'ok',
			'mode' => 'summary_action',
			'path' => $path,
			'summary_action' => $params['summary_action'],
			'section' => $params['section'],
			'item_id' => $params['item_id'],
			'call_id' => $params['call_id'] ?? null,
			'call_index' => isset($params['call_index']) ? (int)$params['call_index'] : null,
			'call_ref' => $params['call_ref'] ?? null,
			'focus_context' => $this->summaryActionFocusContext($params, $calls),
			'confidence' => $item['confidence'] ?? null,
			'text' => $item['text'] ?? null,
			'observations' => $item['observations'] ?? [],
			'evidence_refs' => $item['evidence_refs'] ?? ($item['evidence'] ?? []),
			'result' => $this->summaryActionResult($params['summary_action'], $item),
			'available_actions' => $this->summaryActionAvailability($item, $params['summary_action']),
		];
	}

	private function summaryActionFocusContext($params, $calls) {
		$call = $this->summaryActionFocusedCall($params, $calls);
		$callId = isset($params['call_id']) ? trim((string)$params['call_id']) : '';
		if ($callId === '' && is_array($call) && !empty($call['call_id'])) {
			$callId = (string)$call['call_id'];
		}
		if ($callId === '') return null;

		$context = ['call_id' => $callId];
		if (is_array($call)) {
			$label = $this->summaryActionFocusLabel($call);
			if ($label !== '') $context['label'] = $label;
		}
		return $context;
	}

	private function summaryActionFocusedCall($params, $calls) {
		if (!is_array($calls) || empty($calls)) return null;
		if (isset($params['call_id'])) {
			$callId = trim((string)$params['call_id']);
			if ($callId !== '') {
				foreach ($calls as $call) {
					if (strcasecmp((string)($call['call_id'] ?? ''), $callId) === 0) return $call;
				}
			}
		}
		if (isset($params['call_ref']) && $params['call_ref'] !== null) {
			$callRef = strtolower((string)$params['call_ref']);
			foreach ($calls as $call) {
				if ($this->callRef($call['call_id'] ?? '') === $callRef) return $call;
			}
		}
		if (isset($params['call_index']) && $params['call_index'] !== null) {
			$idx = (int)$params['call_index'];
			if (isset($calls[$idx]) && is_array($calls[$idx])) return $calls[$idx];
		}
		return null;
	}

	private function summaryActionFocusLabel($call) {
		$summary = is_array($call['summary'] ?? null) ? $call['summary'] : [];
		$outcome = trim((string)($summary['outcome'] ?? ''));
		$outcomeLabel = $outcome !== '' ? ucwords(str_replace('_', ' ', $outcome)) : 'SIP transaction';
		if ($outcome === 'cancelled'
			&& !empty($summary['observations'])
			&& is_array($summary['observations'])
			&& in_array('cancelled_before_answer', $summary['observations'], true)
		) {
			$outcomeLabel = 'Cancelled before answer';
		}

		$methods = is_array($summary['methods'] ?? null) ? $summary['methods'] : [];
		$method = $this->primarySipMethod($methods);
		$typeLabel = in_array('INVITE', $methods, true) ? '' : $method;
		$prefix = trim($outcomeLabel . ' ' . $typeLabel);
		if ($prefix === '') $prefix = 'SIP transaction';

		$messageCount = (int)($call['message_count'] ?? 0);
		$messageLabel = $messageCount === 1 ? 'message' : 'messages';
		$label = $prefix . ' — ' . $this->formatSummaryActionFocusDuration((int)($call['duration_ms'] ?? 0)) . ', ' . $messageCount . ' ' . $messageLabel;

		$final = ($summary['invite_final_status'] ?? null) ?: ($summary['final_status'] ?? null);
		if (is_array($final) && isset($final['code'])) {
			$finalText = trim((int)$final['code'] . ' ' . (string)($final['reason'] ?? ''));
			if ($finalText !== '') $label .= ', final ' . $finalText;
		}

		return $label;
	}

	private function formatSummaryActionFocusDuration($durationMs) {
		$durationMs = max(0, (int)$durationMs);
		if ($durationMs < 1000) return $durationMs . 'ms';
		$seconds = $durationMs / 1000;
		if ($seconds < 10) return rtrim(rtrim(number_format($seconds, 2, '.', ''), '0'), '.') . 's';
		return rtrim(rtrim(number_format($seconds, 1, '.', ''), '0'), '.') . 's';
	}

	private function findSummaryActionItem($calls, $analysis, $section, $itemId, $callIndex, $callRef) {
		if ($section === 'diagnostic_hints') {
			if ($callRef !== null) {
				$callRef = strtolower((string)$callRef);
				foreach ($calls as $call) {
					if ($this->callRef($call['call_id'] ?? '') !== $callRef) continue;
					return $this->findSummaryItemById($call['summary']['diagnostic_hints'] ?? [], $itemId);
				}
				return null;
			}
			if ($callIndex !== null) {
				$idx = (int)$callIndex;
				if (!isset($calls[$idx])) return null;
				return $this->findSummaryItemById($calls[$idx]['summary']['diagnostic_hints'] ?? [], $itemId);
			}
			foreach ($calls as $call) {
				$item = $this->findSummaryItemById($call['summary']['diagnostic_hints'] ?? [], $itemId);
				if ($item !== null) return $item;
			}
			return null;
		}
		return $this->findSummaryItemById($analysis[$section] ?? [], $itemId);
	}

	private function findSummaryActionBlock($calls, $analysis, $section, $callIndex, $callRef) {
		if ($section === 'response') {
			return $this->responseSummaryActionItems($calls, $analysis);
		}
		if ($section === 'diagnostic_hints') {
			if ($callRef !== null) {
				$callRef = strtolower((string)$callRef);
				foreach ($calls as $call) {
					if ($this->callRef($call['call_id'] ?? '') !== $callRef) continue;
					return array_values($call['summary']['diagnostic_hints'] ?? []);
				}
				return [];
			}
			if ($callIndex !== null) {
				$idx = (int)$callIndex;
				return isset($calls[$idx]) ? array_values($calls[$idx]['summary']['diagnostic_hints'] ?? []) : [];
			}
			return [];
		}
		return array_values($analysis[$section] ?? []);
	}

	private function findSummaryItemById($items, $itemId) {
		if (!is_array($items)) return null;
		foreach ($items as $item) {
			if (is_array($item) && (string)($item['id'] ?? '') === (string)$itemId) return $item;
		}
		return null;
	}

	private function summaryActionResult($action, $item) {
		if ($action === 'simplify') {
			return [
				'kind' => 'text',
				'title' => 'Simplify',
				'text' => $item['simplified'] ?? ($item['text'] ?? ''),
			];
		}
		if ($action === 'explain' || $action === 're_explain') {
			return [
				'kind' => 'text',
				'title' => 'Explain',
				'text' => $item['re_explained'] ?? ($item['text'] ?? ''),
			];
		}
		return [
			'kind' => 'evidence',
			'title' => 'Evidence',
			'items' => array_values($item['evidence_text'] ?? []),
			'refs' => array_values($item['evidence_refs'] ?? ($item['evidence'] ?? [])),
		];
	}

	private function summaryBlockActionResult($action, $section, $items, $analysis = null, $calls = []) {
		if ($action === 'simplify') {
			return [
				'kind' => 'text',
				'title' => 'Simplify',
				'text' => $this->summaryBlockSimplifyText($section, $items, $analysis, $calls),
			];
		}
		if ($action === 'explain') {
			return [
				'kind' => 'text',
				'title' => 'Explain',
				'text' => $this->summaryBlockExplainText($section, $items, $analysis, $calls),
			];
		}

		$evidence = [];
		$refs = [];
		foreach ($items as $item) {
			if (!is_array($item)) continue;
			foreach ($item['evidence_text'] ?? [] as $line) {
				if ($line !== '') $evidence[] = $line;
			}
			foreach (($item['evidence_refs'] ?? ($item['evidence'] ?? [])) as $ref) {
				if ($ref !== '') $refs[] = $ref;
			}
		}
		return [
			'kind' => 'evidence',
			'title' => 'Evidence',
			'items' => array_values(array_unique($evidence)),
			'refs' => array_values(array_unique($refs)),
		];
	}

	private function summaryBlockSimplifyText($section, $items, $analysis = null, $calls = []) {
		if ($section === 'response' && is_array($analysis)) {
			return $this->responseSimplifyNarrative($analysis);
		}
		$lines = [];
		foreach ($items as $item) {
			if (!is_array($item)) continue;
			$text = $item['simplified'] ?? ($item['text'] ?? '');
			if ($text !== '') $lines[] = '- ' . $text;
		}
		return !empty($lines) ? implode("\n", $lines) : 'No simplified text is available for this block.';
	}

	private function summaryBlockExplainText($section, $items, $analysis = null, $calls = []) {
		if ($section === 'response' && is_array($analysis)) {
			return $this->responseExplainNarrative($analysis);
		}
		$intro = [
			'response' => 'This explains the whole PCAP response that was displayed: aggregate counts, displayed SIP ladders, diagnostic hints, support summary, likely checks, and confidence limits. Omitted ladders are not treated as individually reviewed here.',
			'diagnostic_hints' => 'These hints describe the notable observations for this call. They are confidence-scoped and should be read together, not as independent proof of a single cause.',
			'support_summary' => 'This support summary combines the strongest decoded signalling and media observations. It preserves confidence levels because the capture point may not see every path.',
			'likely_next_checks' => 'These checks are practical follow-ups suggested by the decoded evidence. They are prioritised by what the capture actually supports, while avoiding claims beyond this PCAP.',
			'confidence_notes' => 'These notes describe the limits of the analysis. They explain why absence, timing, reassembly, and capture placement can reduce certainty.',
		];
		$lines = [$intro[$section] ?? 'This block explains the decoded PCAP observations with confidence and scope preserved.'];
		foreach ($items as $item) {
			if (!is_array($item)) continue;
			$text = $item['re_explained'] ?? ($item['text'] ?? '');
			if ($text === '') continue;
			$confidence = $item['confidence'] ?? null;
			$prefix = $confidence ? '- Confidence ' . $confidence . ': ' : '- ';
			$lines[] = $prefix . $text;
		}
		return implode("\n", $lines);
	}

	private function summaryActionAvailability($item, $currentAction) {
		$actions = [];
		if (!empty($item['simplified'])) $actions['simplify'] = 'Simplify';
		if (!empty($item['re_explained'])) $actions['explain'] = 'Explain';
		if (!empty($item['evidence_text']) && is_array($item['evidence_text'])) $actions['evidence'] = 'Evidence';
		unset($actions[$currentAction]);
		return $actions;
	}

	private function summaryBlockActionAvailability($currentAction) {
		$actions = [
			'simplify' => 'Simplify',
			'explain' => 'Explain',
			'evidence' => 'Evidence',
		];
		unset($actions[$currentAction]);
		return $actions;
	}

	private function responseSimplifyNarrative($analysis) {
		$facts = $this->responseNarrativeFacts($analysis);
		$body = [];
		$body[] = $this->responseTotalSentence($facts);
		$unparsed = $this->responseUnparsedSentence($facts);
		if ($unparsed !== '') $body[] = $unparsed;
		$salient = $this->responseSalientFindingSentence($facts);
		if ($salient !== '') {
			$body[] = $salient;
		} else {
			$outcome = $this->responseOutcomeSentence($facts);
			if ($outcome !== '') $body[] = $outcome;
		}
		$caveat = $this->responseCaveatSentence($facts);
		$sentences = array_slice(array_filter($body), 0, 3);
		if ($caveat !== '') $sentences[] = $caveat;
		return implode(' ', $sentences);
	}

	private function responseExplainNarrative($analysis) {
		$facts = $this->responseNarrativeFacts($analysis);
		$body = [];
		$body[] = $this->responseTotalSentence($facts);
		$body[] = $this->responseDisplayedScopeSentence($facts);
		$unparsed = $this->responseUnparsedSentence($facts);
		if ($unparsed !== '') $body[] = $unparsed;
		$salient = $this->responseSalientFindingSentence($facts);
		if ($salient !== '') {
			$body[] = $salient;
		} else {
			$outcome = $this->responseOutcomeSentence($facts);
			if ($outcome !== '') $body[] = $outcome;
		}
		$covered = $this->responseSalientCoveredKeys($facts);
		$observations = $this->responseObservationSentence($facts, $covered['observations']);
		if ($observations !== '') $body[] = $observations;
		$rtp = $this->responseRtpSentence($facts, $covered['rtp_statuses']);
		if ($rtp !== '') $body[] = $rtp;
		$next = $this->responseNextCheckSentence($facts);
		if ($next !== '') $body[] = $next;
		$caveat = $this->responseCaveatSentence($facts);
		$sentences = array_slice(array_filter($body), 0, 6);
		if ($caveat !== '') $sentences[] = $caveat;
		return implode(' ', $sentences);
	}

	private function responseNarrativeFacts($analysis) {
		$highlights = $analysis['evidence_highlights'] ?? [];
		return [
			'sip_message_count' => (int)($highlights['sip_message_count'] ?? 0),
			'call_count' => (int)($highlights['call_count'] ?? 0),
			'sip_transaction_count' => (int)($highlights['sip_transaction_count'] ?? $highlights['call_count'] ?? 0),
			'invite_call_flow_count' => (int)($highlights['invite_call_flow_count'] ?? ($highlights['invite_outcomes']['total'] ?? 0)),
			'unparsed_message_count' => (int)($highlights['unparsed_message_count'] ?? 0),
			'invite_outcomes' => is_array($highlights['invite_outcomes'] ?? null) ? $highlights['invite_outcomes'] : [],
			'outcome_counts' => is_array($analysis['outcome_counts'] ?? null) ? $analysis['outcome_counts'] : [],
			'final_status_counts' => is_array($analysis['final_status_counts'] ?? null) ? $analysis['final_status_counts'] : [],
			'observation_counts' => is_array($analysis['observation_counts'] ?? null) ? $analysis['observation_counts'] : [],
			'rtp_summary' => is_array($highlights['rtp_summary'] ?? null) ? $highlights['rtp_summary'] : [],
			'support_ids' => $this->summaryItemIds($analysis['support_summary'] ?? []),
			'next_check_ids' => $this->summaryItemIds($analysis['likely_next_checks'] ?? []),
			'confidence_ids' => $this->summaryItemIds($analysis['confidence_notes'] ?? []),
		];
	}

	private function responseTotalSentence($facts) {
		$sip = (int)($facts['sip_message_count'] ?? 0);
		$transactions = (int)($facts['sip_transaction_count'] ?? $facts['call_count'] ?? 0);
		$inviteFlows = (int)($facts['invite_call_flow_count'] ?? ($facts['invite_outcomes']['total'] ?? 0));
		if ($sip > 0 && $transactions > 0) {
			return 'This capture contains ' . $sip . ' decoded SIP ' . $this->pluralWord($sip, 'message') . ' grouped into ' . $transactions . ' SIP ' . $this->pluralWord($transactions, 'transaction') . ', including ' . $inviteFlows . ' INVITE ' . $this->pluralWord($inviteFlows, 'call flow') . '.';
		}
		if ($sip > 0) {
			return 'This capture contains ' . $sip . ' decoded SIP ' . $this->pluralWord($sip, 'message') . ', but no grouped SIP transaction count is exposed in the response fields.';
		}
		return 'This response summarizes the decoded PCAP analysis available from the capture.';
	}

	private function responseDisplayedScopeSentence($facts) {
		$total = (int)($facts['call_count'] ?? 0);
		$shown = min(5, $total);
		if ($total > $shown) {
			return 'This response shows ' . $shown . ' of the ' . $total . ' decoded SIP ' . $this->pluralWord($total, 'transaction') . ' in detail, while the counts and observations are calculated across the entire capture.';
		}
		if ($total > 0) {
			return 'This response shows the decoded SIP ' . $this->pluralWord($total, 'transaction') . ' available in this result, while counts and observations remain limited to what this capture point saw.';
		}
		return 'No detailed SIP ladder is available in the displayed response, so the interpretation rests on aggregate decoded fields only.';
	}

	private function responseOutcomeSentence($facts) {
		$invite = $facts['invite_outcomes'] ?? [];
		$totalInvites = (int)($invite['total'] ?? 0);
		if ($totalInvites > 0) {
			$parts = [];
			foreach (['answered' => 'answered', 'busy' => 'busy', 'failed' => 'failed', 'cancelled' => 'cancelled before answer', 'incomplete_capture' => 'incomplete in the capture'] as $key => $label) {
				$count = (int)($invite[$key] ?? 0);
				if ($count > 0) $parts[] = $count . ' ' . $label;
			}
			if (!empty($parts)) {
				return 'Among ' . $totalInvites . ' decoded INVITE ' . $this->pluralWord($totalInvites, 'call flow') . ', ' . $this->joinPhraseList($parts) . '.';
			}
			return 'The decoded INVITE call-flow count is ' . $totalInvites . ', but no stronger INVITE outcome mix is exposed in the aggregate fields.';
		}

		$outcomes = [];
		foreach (($facts['outcome_counts'] ?? []) as $outcome => $count) {
			$count = (int)$count;
			if ($count > 0) $outcomes[] = $count . ' ' . str_replace('_', ' ', (string)$outcome);
		}
		if (!empty($outcomes)) {
			return 'The aggregate outcome mix is ' . $this->joinPhraseList($outcomes) . '.';
		}
		return '';
	}

	private function responseUnparsedSentence($facts) {
		$unparsed = (int)($facts['unparsed_message_count'] ?? 0);
		if ($unparsed <= 0) return '';
		return $unparsed . ' SIP-like ' . $this->pluralWord($unparsed, 'payload') . ' could not be parsed into grouped SIP messages, so decoded totals may be incomplete.';
	}

	private function responseSalientFindingSentence($facts) {
		$invite = $facts['invite_outcomes'] ?? [];
		$obs = $facts['observation_counts'] ?? [];
		$rtpStatus = $facts['rtp_summary']['status_counts'] ?? [];
		if (!empty($invite['failed'])) {
			$status = $this->dominantFailureStatusText($facts['final_status_counts'] ?? []);
			$statusText = $status !== '' ? '; the most common >=400 final status is ' . $status : '';
			return (int)$invite['failed'] . ' INVITE ' . $this->pluralWord((int)$invite['failed'], 'call flow') . ' ended in failure response evidence' . $statusText . ', so the final SIP status counts are the strongest signalling clue.';
		}
		if (!empty($invite['cancelled'])) {
			$count = (int)$invite['cancelled'];
			$total = (int)($invite['total'] ?? 0);
			$cancelledText = $this->responseInviteOutcomePhrase($count, $total, 'cancelled before answer');
			if (!empty($rtpStatus['rtp_not_seen_before_cancellation'])) {
				$rtpCount = (int)$rtpStatus['rtp_not_seen_before_cancellation'];
				return $cancelledText . ', and ' . $rtpCount . ' cancelled ' . $this->pluralWord($rtpCount, 'flow') . ' had negotiated media without matching RTP at this capture point before cancellation.';
			}
			return $cancelledText . '.';
		}
		if (!empty($rtpStatus['rtp_one_direction_only'])) {
			$count = (int)$rtpStatus['rtp_one_direction_only'];
			return 'RTP was visible in only one captured direction for ' . $count . ' ' . $this->pluralWord($count, 'flow') . ', which makes capture-point media visibility a central finding.';
		}
		if (!empty($rtpStatus['rtp_absent_despite_answer'])) {
			$count = (int)$rtpStatus['rtp_absent_despite_answer'];
			return $count . ' answered ' . $this->pluralWord($count, 'flow') . ' had negotiated media but no matching RTP at this capture point.';
		}
		if (!empty($obs['large_signalling_gap'])) {
			$count = (int)$obs['large_signalling_gap'];
			return $count . ' ' . $this->pluralWord($count, 'flow') . ' had a multi-second signalling gap flagged by the aggregate observations.';
		}
		if (!empty($invite['answered'])) {
			$count = (int)$invite['answered'];
			return $count . ' INVITE ' . $this->pluralWord($count, 'call flow') . ' reached a 2xx final response at SIP signalling level.';
		}
		return '';
	}

	private function responseInviteOutcomePhrase($count, $total, $outcomeText) {
		$count = (int)$count;
		$total = (int)$total;
		$verb = $count === 1 ? 'was' : 'were';
		if ($total > 0 && $count === $total) {
			if ($count === 1) return 'The decoded INVITE call flow was ' . $outcomeText;
			if ($count === 2) return 'Both decoded INVITE call flows were ' . $outcomeText;
			return 'All ' . $count . ' decoded INVITE call ' . $this->pluralWord($count, 'flow') . ' were ' . $outcomeText;
		}
		if ($total > 0) {
			return $count . ' of ' . $total . ' decoded INVITE call ' . $this->pluralWord($total, 'flow') . ' ' . $verb . ' ' . $outcomeText;
		}
		return $count . ' INVITE ' . $this->pluralWord($count, 'call flow') . ' ' . $verb . ' ' . $outcomeText;
	}

	private function responseSalientCoveredKeys($facts) {
		$invite = $facts['invite_outcomes'] ?? [];
		$obs = $facts['observation_counts'] ?? [];
		$rtpStatus = $facts['rtp_summary']['status_counts'] ?? [];
		$covered = ['observations' => [], 'rtp_statuses' => []];
		if (!empty($invite['failed'])) {
			$covered['observations'][] = 'failed_final_response';
			return $covered;
		}
		if (!empty($invite['cancelled'])) {
			$covered['observations'][] = 'cancelled_before_answer';
			if (!empty($rtpStatus['rtp_not_seen_before_cancellation'])) {
				$covered['rtp_statuses'][] = 'rtp_not_seen_before_cancellation';
			}
			return $covered;
		}
		if (!empty($rtpStatus['rtp_one_direction_only'])) {
			$covered['observations'][] = 'rtp_one_direction_only';
			$covered['rtp_statuses'][] = 'rtp_one_direction_only';
			return $covered;
		}
		if (!empty($rtpStatus['rtp_absent_despite_answer'])) {
			$covered['observations'][] = 'rtp_absent_despite_answer';
			$covered['rtp_statuses'][] = 'rtp_absent_despite_answer';
			return $covered;
		}
		if (!empty($obs['large_signalling_gap'])) {
			$covered['observations'][] = 'large_signalling_gap';
			return $covered;
		}
		return $covered;
	}

	private function dominantFailureStatusText($statuses) {
		if (!is_array($statuses) || empty($statuses)) return '';
		$best = null;
		foreach ($statuses as $status) {
			if (!is_array($status)) continue;
			$code = (int)($status['code'] ?? 0);
			if ($code < 400) continue;
			if ($best === null || (int)($status['count'] ?? 0) > (int)($best['count'] ?? 0)) {
				$best = $status;
			}
		}
		if ($best === null) return '';
		$code = (int)($best['code'] ?? 0);
		$reason = trim((string)($best['reason'] ?? ''));
		return trim($code . ' ' . $reason);
	}

	private function responseRtpSentence($facts, $omitStatuses = []) {
		$rtp = $facts['rtp_summary'] ?? [];
		$statusCounts = $rtp['status_counts'] ?? [];
		if (empty($statusCounts)) return '';
		$omit = array_flip($omitStatuses);
		$parts = [];
		foreach ([
			'rtp_both_directions' => ['flow with RTP in both directions', 'flows with RTP in both directions'],
			'rtp_one_direction_only' => ['flow with RTP in one direction only', 'flows with RTP in one direction only'],
			'rtp_absent_despite_answer' => ['answered flow without RTP at this capture point', 'answered flows without RTP at this capture point'],
			'rtp_not_seen_before_cancellation' => ['cancelled flow without RTP before cancellation', 'cancelled flows without RTP before cancellation'],
			'rtp_not_seen_at_capture_point' => ['flow without RTP at this capture point', 'flows without RTP at this capture point'],
		] as $key => $labels) {
			if (isset($omit[$key])) continue;
			$count = (int)($statusCounts[$key] ?? 0);
			if ($count > 0) $parts[] = $count . ' ' . $this->pluralWord($count, $labels[0], $labels[1]);
		}
		if (empty($parts)) return '';
		return 'The RTP summary is capture-point scoped: ' . $this->joinPhraseList($parts) . '.';
	}

	private function responseObservationSentence($facts, $omitObservations = []) {
		$obs = $facts['observation_counts'] ?? [];
		if (empty($obs)) return '';
		$omit = array_flip($omitObservations);
		$parts = [];
		foreach ([
			'cancelled_before_answer' => ['cancellation before answer', 'cancellations before answer'],
			'failed_final_response' => ['failure final response', 'failure final responses'],
			'large_signalling_gap' => ['multi-second signalling gap', 'multi-second signalling gaps'],
			'retransmissions_seen' => ['repeated SIP message pattern', 'repeated SIP message patterns'],
			'answered_without_ack_seen' => ['answered call without ACK visibility', 'answered calls without ACK visibility'],
			'private_sdp_connection_address' => ['private SDP media address', 'private SDP media addresses'],
			'rtp_one_direction_only' => ['one-way RTP visibility finding', 'one-way RTP visibility findings'],
			'rtp_absent_despite_answer' => ['answered call without RTP at this capture point', 'answered calls without RTP at this capture point'],
		] as $key => $labels) {
			if (isset($omit[$key])) continue;
			$count = (int)($obs[$key] ?? 0);
			if ($count > 0) $parts[] = $count . ' ' . $this->pluralWord($count, $labels[0], $labels[1]);
		}
		if (empty($parts)) return '';
		return 'The observation counts specifically flag ' . $this->joinPhraseList(array_slice($parts, 0, 3)) . '.';
	}

	private function responseNextCheckSentence($facts) {
		$ids = array_flip($facts['next_check_ids'] ?? []);
		$checks = [];
		if (isset($ids['check_nat_media_addresses'])) $checks[] = 'media-address/NAT configuration';
		if (isset($ids['check_one_direction_rtp_visibility'])) $checks[] = 'whether this capture point should see both RTP directions';
		if (isset($ids['check_capture_location_for_absent_rtp'])) $checks[] = 'capture placement for RTP visibility';
		if (isset($ids['check_retransmission_context'])) $checks[] = 'the retransmission context around repeated SIP messages';
		if (isset($ids['check_signalling_gap_context'])) $checks[] = 'what happened around the signalling gap';
		if (isset($ids['check_missing_ack_visibility'])) $checks[] = 'ACK routing or capture asymmetry';
		if (empty($checks)) return '';
		return 'The next check should focus on ' . $this->joinPhraseList(array_slice($checks, 0, 3)) . ' before assuming a routing, timer, or media fault.';
	}

	private function responseCaveatSentence($facts) {
		$confidenceIds = array_flip($facts['confidence_ids'] ?? []);
		$supportIds = array_flip($facts['support_ids'] ?? []);
		$invite = $facts['invite_outcomes'] ?? [];
		$rtpStatus = $facts['rtp_summary']['status_counts'] ?? [];
		$caveats = [];
		if (!empty($rtpStatus) || isset($confidenceIds['rtp_capture_point_scope'])) {
			$caveats[] = 'RTP findings only describe packets visible at this capture point';
		}
		if (!empty($rtpStatus['rtp_absent_despite_answer']) || isset($confidenceIds['rtp_absence_not_proof'])) {
			$caveats[] = 'absence of RTP here is not proof that media did not exist elsewhere';
		}
		if (!empty($invite['cancelled']) || isset($supportIds['cancelled_invites'])) {
			$caveats[] = 'cancellation evidence does not prove the human or upstream reason for cancellation';
		}
		if (!empty($facts['unparsed_message_count']) || isset($confidenceIds['unparsed_sip_scope'])) {
			$caveats[] = 'unparsed SIP-like payloads may make totals incomplete';
		}
		if (empty($caveats)) {
			$caveats[] = 'the interpretation remains limited to decoded packets in this capture';
		}
		return 'Caveat: ' . $this->joinPhraseList(array_slice(array_values(array_unique($caveats)), 0, 3)) . '.';
	}

	private function summaryItemIds($items) {
		$ids = [];
		if (!is_array($items)) return $ids;
		foreach ($items as $item) {
			if (is_array($item) && !empty($item['id'])) $ids[] = (string)$item['id'];
		}
		return array_values(array_unique($ids));
	}

	private function pluralWord($count, $singular, $plural = null) {
		return ((int)$count === 1) ? $singular : ($plural ?? $singular . 's');
	}

	private function joinPhraseList($parts) {
		$parts = array_values(array_filter($parts, function($part) {
			return (string)$part !== '';
		}));
		$count = count($parts);
		if ($count === 0) return '';
		if ($count === 1) return $parts[0];
		if ($count === 2) return $parts[0] . ' and ' . $parts[1];
		$last = array_pop($parts);
		return implode(', ', $parts) . ', and ' . $last;
	}

	private function responseSummaryActionItems($calls, $analysis) {
		$items = [];
		$totalCalls = count($calls);
		$displayedCalls = array_slice($calls, 0, 5);
		$displayedCount = count($displayedCalls);
		$outcomes = $this->formatCountMap($analysis['outcome_counts'] ?? []);
		$observations = $this->formatCountMap($analysis['observation_counts'] ?? []);
		$finalStatuses = $this->formatFinalStatusCounts($analysis['final_status_counts'] ?? []);
		$transports = $this->formatCountMap($analysis['transport_counts'] ?? []);

		$aggregateEvidence = [];
		if ($outcomes !== '') $aggregateEvidence[] = 'Aggregate decoded outcomes: ' . $outcomes . '.';
		if ($finalStatuses !== '') $aggregateEvidence[] = 'Final SIP status counts: ' . $finalStatuses . '.';
		if ($observations !== '') $aggregateEvidence[] = 'Observation counts: ' . $observations . '.';
		if ($transports !== '') $aggregateEvidence[] = 'Transport counts: ' . $transports . '.';
		if (!empty($analysis['packet_count'])) $aggregateEvidence[] = 'Packet count decoded from this PCAP: ' . (int)$analysis['packet_count'] . '.';

		$items[] = [
			'id' => 'response_aggregate',
			'text' => 'The response combines aggregate capture counts with the SIP ladders and summary sections shown below.',
			'confidence' => 'medium',
			'simplified' => 'Read the aggregate counts as capture-wide context, then use the displayed ladders for transaction-level detail.',
			're_explained' => 'The aggregate counts describe the decoded capture available to this run. The detailed ladder discussion is scoped to the SIP transactions displayed in the response, so omitted ladders are not individually interpreted here.',
			'evidence_text' => $aggregateEvidence,
			'evidence_refs' => ['outcome_counts', 'final_status_counts', 'observation_counts', 'transport_counts', 'packet_count'],
		];

		$displayedEvidence = [];
		foreach ($displayedCalls as $call) {
			$displayedEvidence[] = $this->formatDisplayedCallEvidence($call);
		}
		$items[] = [
			'id' => 'response_displayed_calls',
			'text' => "The formatted response displays {$displayedCount} of {$totalCalls} decoded SIP " . $this->pluralWord($totalCalls, 'ladder') . ".",
			'confidence' => 'medium',
			'simplified' => $totalCalls > $displayedCount
				? "This view shows {$displayedCount} of {$totalCalls} decoded SIP " . $this->pluralWord($totalCalls, 'ladder') . "; focus a Call-ID to inspect omitted ladders."
				: "This view shows the decoded SIP " . $this->pluralWord($totalCalls, 'ladder') . " available in this response.",
			're_explained' => $totalCalls > $displayedCount
				? "Only {$displayedCount} of {$totalCalls} decoded SIP " . $this->pluralWord($totalCalls, 'ladder') . " " . $this->pluralWord($displayedCount, 'is', 'are') . " shown in detail. The action response discusses those displayed ladders plus aggregate counts, without pretending to review hidden omitted ladders individually."
				: "The displayed SIP ladder section covers the decoded SIP " . $this->pluralWord($totalCalls, 'transaction') . " available in this response. Each ladder-level statement remains limited to the SIP and RTP packets visible at this capture point.",
			'evidence_text' => $displayedEvidence,
			'evidence_refs' => ['displayed_calls', 'top_calls'],
		];

		$hintEvidence = [];
		$hintCount = 0;
		foreach ($displayedCalls as $call) {
			$callLabel = $this->shortCallLabel($call['call_id'] ?? '');
			foreach (array_slice($call['summary']['diagnostic_hints'] ?? [], 0, 3) as $hint) {
				if (!is_array($hint)) continue;
				$hintCount++;
				$confidence = $hint['confidence'] ?? 'low';
				$text = $hint['text'] ?? '';
				if ($text !== '') $hintEvidence[] = 'Call ' . $callLabel . ' hint (' . $confidence . '): ' . $text;
				foreach ($hint['evidence_text'] ?? [] as $line) {
					if ($line !== '') $hintEvidence[] = 'Call ' . $callLabel . ' evidence: ' . $line;
				}
			}
		}
		if ($hintCount > 0) {
			$items[] = [
				'id' => 'response_diagnostic_hints',
				'text' => "Displayed calls include {$hintCount} diagnostic " . $this->pluralWord($hintCount, 'hint') . ".",
				'confidence' => 'medium',
				'simplified' => 'The call hints are clues, not proof of a single cause.',
				're_explained' => 'The diagnostic hints point to notable SIP or RTP observations in the displayed calls. Their confidence labels matter because the capture may be partial, asymmetric, or missing media/signalling paths.',
				'evidence_text' => array_values(array_unique($hintEvidence)),
				'evidence_refs' => ['diagnostic_hints', 'displayed_calls'],
			];
		}

		foreach (['support_summary', 'likely_next_checks', 'confidence_notes'] as $section) {
			foreach (($analysis[$section] ?? []) as $item) {
				if (is_array($item)) $items[] = $item;
			}
		}

		return $items;
	}

	private function formatDisplayedCallEvidence($call) {
		$label = $this->shortCallLabel($call['call_id'] ?? '');
		$summary = $call['summary'] ?? [];
		$outcome = $summary['outcome'] ?? 'unknown';
		$messageCount = (int)($call['message_count'] ?? 0);
		$duration = (int)($call['duration_ms'] ?? 0);
		$final = ($summary['invite_final_status'] ?? null) ?: ($summary['final_status'] ?? null);
		$finalText = '';
		if ($final !== null) {
			$finalText = ', final SIP status ' . (int)($final['code'] ?? 0);
			if (!empty($final['reason'])) $finalText .= ' ' . $final['reason'];
		}
		$rtp = '';
		if (!empty($summary['rtp']['status'])) {
			$rtp = ', RTP ' . $summary['rtp']['status'] . ' confidence ' . ($summary['rtp']['confidence'] ?? 'unknown');
		}
		return "Displayed call {$label}: outcome {$outcome}, {$messageCount} " . $this->pluralWord($messageCount, 'message') . ", duration {$duration}ms{$finalText}{$rtp}.";
	}

	private function shortCallLabel($callId) {
		$callId = (string)$callId;
		if ($callId === '') return 'unknown';
		return substr($callId, 0, 12);
	}

	private function formatCountMap($counts, $limit = 8) {
		if (!is_array($counts) || empty($counts)) return '';
		$parts = [];
		foreach (array_slice($counts, 0, $limit, true) as $key => $count) {
			$parts[] = (string)$key . ': ' . (int)$count;
		}
		if (count($counts) > $limit) $parts[] = 'and ' . (count($counts) - $limit) . ' more';
		return implode('; ', $parts);
	}

	private function formatFinalStatusCounts($statuses, $limit = 8) {
		if (!is_array($statuses) || empty($statuses)) return '';
		$parts = [];
		foreach (array_slice($statuses, 0, $limit) as $status) {
			if (!is_array($status)) continue;
			$code = (int)($status['code'] ?? 0);
			$reason = trim((string)($status['reason'] ?? ''));
			$count = (int)($status['count'] ?? 0);
			$parts[] = trim($code . ' ' . $reason) . ': ' . $count;
		}
		if (count($statuses) > $limit) $parts[] = 'and ' . (count($statuses) - $limit) . ' more';
		return implode('; ', $parts);
	}

	private function callRef($callId) {
		$callId = (string)$callId;
		return $callId === '' ? null : substr(sha1($callId), 0, 12);
	}

	private function enrichSummaryLines($lines, $calls = [], $rtpSummary = null) {
		$out = [];
		foreach ($lines as $line) {
			if (!is_array($line)) {
				$out[] = $line;
				continue;
			}
			$out[] = $this->enrichSummaryLine($line, $calls, $rtpSummary);
		}
		return $out;
	}

	private function enrichSummaryLine($line, $calls = [], $rtpSummary = null) {
		$id = $line['id'] ?? null;
		if (!is_string($id) || $id === '') return $line;
		$template = $this->postSummaryTemplate($id);
		if (empty($template)) return $line;
		$line['simplified'] = $template['simplified'];
		$line['re_explained'] = $template['re_explained'];
		$line['evidence_refs'] = array_values(array_unique(array_merge($line['evidence'] ?? [], $line['observations'] ?? [])));
		$evidenceText = $this->postSummaryEvidenceText($id, $line, $calls, $rtpSummary);
		if (!empty($evidenceText)) $line['evidence_text'] = $evidenceText;
		return $line;
	}

	private function postSummaryTemplate($id) {
		$map = [
			'no_invite_calls' => [
				'simplified' => 'No call setup attempt was decoded in this capture.',
				're_explained' => 'No decoded INVITE flow is present, so the capture does not contain enough SIP setup evidence to determine call setup behaviour.',
			],
			'answered_invites' => [
				'simplified' => 'One or more calls were answered at the signalling level.',
				're_explained' => 'The INVITE transaction reached a 2xx final response. This supports an answered call at SIP signalling level.',
			],
			'answered_invite' => [
				'simplified' => 'The call was answered at the signalling level.',
				're_explained' => 'The INVITE reached a 2xx final response. This supports an answered SIP dialog, subject to capture scope.',
			],
			'busy_invites' => [
				'simplified' => 'The destination appears to have been busy.',
				're_explained' => 'The captured final SIP response was 486 or 600, which supports a busy destination or downstream busy condition.',
			],
			'busy_response' => [
				'simplified' => 'The destination appears to have been busy.',
				're_explained' => 'The captured final SIP response was 486 or 600, which supports a busy destination or downstream busy condition.',
			],
			'failed_invites' => [
				'simplified' => 'One or more calls ended with a failure response.',
				're_explained' => 'The INVITE ended in a SIP failure response. The final status and Reason header are the strongest captured signalling evidence.',
			],
			'failed_final_response' => [
				'simplified' => 'The transaction ended with a failure response.',
				're_explained' => 'The SIP transaction ended in a failure response. The final status and Reason header are the strongest captured signalling evidence.',
			],
			'cancelled_invites' => [
				'simplified' => 'The call was cancelled before it was answered.',
				're_explained' => 'The INVITE was terminated before a 2xx answer. The CANCEL and 487 response are consistent with a normal SIP cancellation flow.',
			],
			'cancelled_before_answer' => [
				'simplified' => 'The call was cancelled before it was answered.',
				're_explained' => 'The INVITE was terminated before a 2xx answer. The CANCEL and 487 response are consistent with a normal SIP cancellation flow.',
			],
			'incomplete_invites' => [
				'simplified' => 'The capture did not show the end of one or more call setup attempts.',
				're_explained' => 'An INVITE was decoded without a final response. The capture may be incomplete, one-sided, or too short for that flow.',
			],
			'invite_without_final_response_in_capture' => [
				'simplified' => 'The capture did not show the final answer or failure for this call.',
				're_explained' => 'An INVITE was decoded without a final response. The capture may be incomplete, one-sided, or too short for that flow.',
			],
			'answered_without_ack_seen' => [
				'simplified' => 'The answer was captured, but the follow-up ACK was not seen here.',
				're_explained' => 'A 2xx INVITE response is present, but the matching ACK is not decoded in this capture. This may reflect capture asymmetry or routing outside this capture point.',
			],
			'answered_rtp_both_directions' => [
				'simplified' => 'Media packets were seen travelling both ways at this capture point.',
				're_explained' => 'RTP was matched in both captured directions at this capture point. This supports bidirectional media visibility in the capture.',
			],
			'rtp_both_directions' => [
				'simplified' => 'Media packets were seen travelling both ways at this capture point.',
				're_explained' => 'RTP was matched in both captured directions at this capture point. This supports bidirectional media visibility in the capture.',
			],
			'answered_rtp_one_direction_only' => [
				'simplified' => 'Media packets were only seen travelling one way in this capture.',
				're_explained' => 'RTP was matched in only one captured direction. This is consistent with media visibility asymmetry, but does not prove what either endpoint heard.',
			],
			'rtp_one_direction_only' => [
				'simplified' => 'Media packets were only seen travelling one way in this capture.',
				're_explained' => 'RTP was matched in only one captured direction. This is consistent with media visibility asymmetry, but does not prove what either endpoint heard.',
			],
			'answered_rtp_absent' => [
				'simplified' => 'The call was answered, but this capture did not show RTP media packets at this capture point.',
				're_explained' => 'The INVITE reached a 2xx final response and SDP media was negotiated, but no matching RTP stream was observed at this capture point. Direct media or capture placement may explain this.',
			],
			'rtp_absent_despite_answer' => [
				'simplified' => 'The call was answered, but this capture did not show RTP media packets at this capture point.',
				're_explained' => 'The INVITE reached a 2xx final response and SDP media was negotiated, but no matching RTP stream was observed at this capture point. Direct media or capture placement may explain this.',
			],
			'rtp_not_seen_before_cancellation' => [
				'simplified' => 'No media packets were captured at this capture point before the call was cancelled.',
				're_explained' => 'SDP media was negotiated, but no matching RTP stream was observed at this capture point before cancellation. This does not prove that media was impossible or absent elsewhere.',
			],
			'rtp_not_seen_at_capture_point' => [
				'simplified' => 'Media was negotiated, but no media packets were captured at this capture point.',
				're_explained' => 'SDP media was negotiated, but no matching RTP stream was observed at this capture point. This does not prove that media was absent elsewhere.',
			],
			'rtp_sequence_gaps' => [
				'simplified' => 'Some RTP packet sequence numbers had gaps in this capture.',
				're_explained' => 'RTP sequence gaps were estimated from captured packets. They may indicate loss, but this capture cannot distinguish network loss from capture-point loss.',
			],
			'codec_mismatch_vs_sdp' => [
				'simplified' => 'Captured RTP payload types did not fully match the decoded media offer.',
				're_explained' => 'Observed RTP payload types differed from decoded SDP payload types. Confidence is limited because dynamic payload types are scoped by SDP and can be reused benignly.',
			],
			'private_sdp_connection_address' => [
				'simplified' => 'The call advertised a private media address.',
				're_explained' => 'SDP advertised a private connection address. If either side is remote, NAT or media-address configuration could be relevant, but this does not prove misconfiguration.',
			],
			'large_signalling_gap' => [
				'simplified' => 'There was a long pause in the call signalling.',
				're_explained' => 'A multi-second gap was measured between SIP messages. If this follows 180 or 183, normal ringing is often the first explanation.',
			],
			'retransmissions_seen' => [
				'simplified' => 'Some SIP messages were repeated.',
				're_explained' => 'Byte-identical non-provisional SIP messages repeated in the decoded capture. This may reflect retry behaviour or capture conditions, but it does not prove a loss cause.',
			],
			'authentication_challenge' => [
				'simplified' => 'The server asked for SIP authentication.',
				're_explained' => 'A 401 or 407 SIP response was decoded. That is often normal digest authentication unless the flow stops there.',
			],
			'check_nat_media_addresses' => [
				'simplified' => 'Check whether the private media address is expected for this network.',
				're_explained' => 'Review media-address and local-network settings against the decoded SDP address. This is a configuration check, not proof of a NAT fault.',
			],
			'check_one_direction_rtp_visibility' => [
				'simplified' => 'Check whether this capture point should see media in both directions.',
				're_explained' => 'Compare SDP media addresses with observed RTP directions and capture placement. One-way visibility at this capture point does not prove what either endpoint heard.',
			],
			'check_capture_location_for_absent_rtp' => [
				'simplified' => 'Check whether RTP should pass through this capture point.',
				're_explained' => 'Confirm capture placement and whether media is expected to traverse this host. Absence of RTP at this capture point is not proof of absent media elsewhere.',
			],
			'check_retransmission_context' => [
				'simplified' => 'Compare repeated SIP messages with nearby responses before deciding what they mean.',
				're_explained' => 'Repeated SIP messages need context from surrounding responses and capture scope before treating them as network loss.',
			],
			'check_signalling_gap_context' => [
				'simplified' => 'Check what happened around the long pause.',
				're_explained' => 'A signalling gap after 180 or 183 often reflects ringing. A gap before provisional response or before failure may support reviewing routing, DNS, authentication, or upstream timing.',
			],
			'check_missing_ack_visibility' => [
				'simplified' => 'Check whether the ACK travelled somewhere this capture could not see.',
				're_explained' => 'For answered calls without a decoded ACK, capture asymmetry or routing outside this capture point should be checked before treating the ACK as truly absent.',
			],
			'preserve_scope_or_capture_more' => [
				'simplified' => 'Narrow the capture or collect closer to the endpoint if more detail is needed.',
				're_explained' => 'The decoded observations do not strongly support a specific next check. Focusing one Call-ID or capturing closer to the signalling or media path may provide better evidence.',
			],
			'rtp_capture_point_scope' => [
				'simplified' => 'RTP findings only describe what this capture point saw.',
				're_explained' => 'RTP conclusions are scoped to packets visible at this capture point and do not prove media behaviour elsewhere.',
			],
			'rtp_absence_not_proof' => [
				'simplified' => 'Not seeing RTP here does not prove there was no media anywhere.',
				're_explained' => 'Absence of RTP in this capture is limited by capture placement and does not prove media was absent on another path.',
			],
			'rtp_sequence_gap_estimate_scope' => [
				'simplified' => 'RTP gap estimates only describe captured packets.',
				're_explained' => 'RTP sequence gaps are estimates from captured packets and cannot distinguish network loss from capture-point loss.',
			],
			'tcp_reassembly_scope' => [
				'simplified' => 'Some SIP messages were rebuilt from TCP pieces, so read those with extra caution.',
				're_explained' => 'TCP SIP messages recovered through simple reassembly carry lower confidence than complete packet-level SIP messages.',
			],
			'unparsed_sip_scope' => [
				'simplified' => 'Some SIP-like data could not be fully read.',
				're_explained' => 'Unparsed SIP-like messages mean decoded message and SIP transaction totals may be incomplete.',
			],
			'clean_answered_gap_scope' => [
				'simplified' => 'For answered calls, a long pause can simply be ringing time.',
				're_explained' => 'For clean answered calls, a multi-second signalling gap can reflect ring time or human answer delay.',
			],
			'evidence_scope' => [
				'simplified' => 'This result only covers packets that were captured.',
				're_explained' => 'Interpretation is limited to decoded packets in this capture. The capture may not include every signalling or media path.',
			],
			'insufficient_evidence' => [
				'simplified' => 'There is not enough captured evidence to name a specific cause.',
				're_explained' => 'The decoded capture does not provide enough evidence to determine a specific cause.',
			],
		];
		return $map[$id] ?? null;
	}

	private function postSummaryEvidenceText($id, $line, $calls = [], $rtpSummary = null) {
		$scopeEvidence = $this->postSummaryScopeEvidenceText($id);
		if (!empty($scopeEvidence)) return $scopeEvidence;

		$text = [];
		$call = $this->representativeCallForSummaryId($id, $calls);
		if ($call !== null) {
			foreach ($this->compactCallEvidence($call, $id) as $evidence) {
				$text[] = $evidence;
			}
		}
		if (strpos($id, 'rtp_') !== false || strpos($id, '_rtp_') !== false || strpos($id, 'capture_location') !== false || strpos($id, 'one_direction') !== false) {
			if ($call !== null) {
				foreach ($this->compactRtpEvidence($call) as $evidence) {
					$text[] = $evidence;
				}
			} elseif (is_array($rtpSummary) && !empty($rtpSummary['status_counts'])) {
				$text[] = 'RTP status counts: ' . json_encode($rtpSummary['status_counts']);
			}
		}
		return array_values(array_unique($text));
	}

	private function postSummaryScopeEvidenceText($id) {
		$map = [
			'rtp_capture_point_scope' => [
				'RTP status counts were derived only from RTP or RTCP packets visible in this capture.',
				'Off-capture media paths, including direct media, cannot be observed from this PCAP.',
				'RTP conclusions are therefore limited to packets present at this capture point.',
			],
			'rtp_absence_not_proof' => [
				'No matching RTP in this PCAP only means this capture point did not observe it.',
				'Media can traverse another path, including direct media between endpoints.',
				'Absence of RTP here is scope evidence, not proof that media never existed.',
			],
			'rtp_sequence_gap_estimate_scope' => [
				'RTP sequence gaps are estimated only from packet sequence numbers visible in this capture.',
				'The estimate cannot distinguish network packet loss from capture-point loss.',
				'Interpret RTP gap conclusions as capture-scoped, not path-wide proof.',
			],
			'tcp_reassembly_scope' => [
				'Some SIP over TCP content was recovered by simple stream reassembly.',
				'Reassembled SIP messages are useful context, but carry lower confidence than complete packet-level messages.',
				'Packet ordering, missing segments, or partial captures can limit this evidence.',
			],
			'unparsed_sip_scope' => [
				'At least one SIP-like payload could not be parsed into a complete SIP message.',
				'Decoded message counts and call grouping may therefore be incomplete.',
				'The missing parse detail limits conclusions drawn from this capture.',
			],
			'evidence_scope' => [
				'The analysis only uses packets decoded from this PCAP.',
				'Signalling or media that bypassed this capture point cannot be observed here.',
				'Conclusions are limited to the visible packet evidence.',
			],
			'insufficient_evidence' => [
				'The decoded capture did not contain enough directly supporting observations to name a specific cause.',
				'Missing signalling, missing media, or capture placement may be limiting the analysis.',
				'Narrowing to a Call-ID or capturing closer to the endpoint/media path may provide stronger evidence.',
			],
		];
		if (empty($map[$id])) return [];
		return $map[$id];
	}

	private function representativeCallForSummaryId($id, $calls) {
		if (!is_array($calls)) return null;
		$statusById = [
			'answered_rtp_both_directions' => 'rtp_both_directions',
			'answered_rtp_one_direction_only' => 'rtp_one_direction_only',
			'answered_rtp_absent' => 'rtp_absent_despite_answer',
			'check_one_direction_rtp_visibility' => 'rtp_one_direction_only',
			'check_capture_location_for_absent_rtp' => 'rtp_absent_despite_answer',
		];
		$outcomeById = [
			'answered_invites' => 'answered',
			'answered_invite' => 'answered',
			'busy_invites' => 'busy',
			'busy_response' => 'busy',
			'failed_invites' => 'failed',
			'failed_final_response' => 'failed',
			'cancelled_invites' => 'cancelled',
			'cancelled_before_answer' => 'cancelled',
			'incomplete_invites' => 'incomplete_capture',
			'invite_without_final_response_in_capture' => 'incomplete_capture',
			'answered_without_ack_seen' => 'answered',
		];
		$obsById = [
			'private_sdp_connection_address' => 'private_sdp_connection_address',
			'check_nat_media_addresses' => 'private_sdp_connection_address',
			'large_signalling_gap' => 'large_signalling_gap',
			'check_signalling_gap_context' => 'large_signalling_gap',
			'retransmissions_seen' => 'retransmissions_seen',
			'check_retransmission_context' => 'retransmissions_seen',
			'rtp_sequence_gaps' => 'rtp_sequence_gaps',
			'codec_mismatch_vs_sdp' => 'codec_mismatch_vs_sdp',
			'tcp_reassembly_scope' => 'reassembled',
			'rtp_absence_not_proof' => 'rtp_absent_despite_answer',
			'rtp_sequence_gap_estimate_scope' => 'rtp_sequence_gaps',
		];
		foreach ($calls as $call) {
			$status = $call['summary']['rtp']['status'] ?? null;
			if (isset($statusById[$id]) && $status === $statusById[$id]) return $call;
			if (strpos($id, 'rtp_') === 0 && $status === $id) return $call;
			if (isset($outcomeById[$id]) && ($call['summary']['outcome'] ?? null) === $outcomeById[$id]) return $call;
			if (isset($obsById[$id])) {
				if ($obsById[$id] === 'reassembled' && $this->hasReassembledMessages([$call])) return $call;
				if (in_array($obsById[$id], $call['summary']['observations'] ?? [], true)) return $call;
			}
		}
		return !empty($calls) ? $calls[0] : null;
	}

	private function compactCallEvidence($call, $id) {
		$messages = $call['messages'] ?? [];
		if (empty($messages)) return [];
		if ($id === 'large_signalling_gap' || $id === 'check_signalling_gap_context' || $id === 'clean_answered_gap_scope') {
			return $this->largestGapEvidence($messages);
		}
		if ($id === 'private_sdp_connection_address' || $id === 'check_nat_media_addresses') {
			return $this->sdpEvidence($call);
		}
		$wanted = $this->wantedSipEvidence($id);
		$out = [];
		foreach ($messages as $msg) {
			if (!$this->messageMatchesEvidenceRequest($msg, $wanted)) continue;
			$out[] = $this->formatMessageEvidence($msg);
			if (count($out) >= 6) break;
		}
		if (empty($out)) {
			foreach (array_slice($messages, 0, 4) as $msg) $out[] = $this->formatMessageEvidence($msg);
		}
		if ($this->hasReassembledMessages([$call])) $out[] = 'TCP reassembled SIP messages present in this ladder.';
		return $out;
	}

	private function wantedSipEvidence($id) {
		$map = [
			'answered_invites' => ['methods' => ['INVITE', 'ACK', 'BYE'], 'statuses' => [200]],
			'answered_invite' => ['methods' => ['INVITE', 'ACK', 'BYE'], 'statuses' => [200]],
			'answered_rtp_absent' => ['methods' => ['INVITE', 'ACK'], 'statuses' => [200]],
			'rtp_absent_despite_answer' => ['methods' => ['INVITE', 'ACK'], 'statuses' => [200]],
			'busy_invites' => ['methods' => ['INVITE'], 'statuses' => [486, 600]],
			'busy_response' => ['methods' => ['INVITE'], 'statuses' => [486, 600]],
			'failed_invites' => ['methods' => ['INVITE'], 'status_min' => 400],
			'failed_final_response' => ['status_min' => 400],
			'cancelled_invites' => ['methods' => ['INVITE', 'CANCEL'], 'statuses' => [200, 487]],
			'cancelled_before_answer' => ['methods' => ['INVITE', 'CANCEL'], 'statuses' => [200, 487]],
			'rtp_not_seen_before_cancellation' => ['methods' => ['INVITE', 'CANCEL'], 'statuses' => [200, 487]],
			'incomplete_invites' => ['methods' => ['INVITE']],
			'invite_without_final_response_in_capture' => ['methods' => ['INVITE']],
			'authentication_challenge' => ['methods' => ['REGISTER', 'INVITE'], 'statuses' => [401, 407]],
			'answered_without_ack_seen' => ['methods' => ['INVITE'], 'statuses' => [200]],
		];
		return $map[$id] ?? ['methods' => ['INVITE', 'CANCEL', 'ACK', 'BYE'], 'status_min' => 180];
	}

	private function messageMatchesEvidenceRequest($msg, $wanted) {
		$method = $msg['method'] ?? null;
		if ($method === null && !empty($msg['line']) && preg_match('/^([A-Z][A-Z0-9_-]+)\s+/', $msg['line'], $m)) $method = $m[1];
		if ($method === null && !empty($msg['cseq']) && preg_match('/\b([A-Z][A-Z0-9_-]+)\s*$/', $msg['cseq'], $m)) $method = $m[1];
		$status = isset($msg['status_code']) ? (int)$msg['status_code'] : null;
		if ($status !== null) {
			if (!empty($wanted['statuses']) && in_array($status, $wanted['statuses'], true)) return true;
			if (isset($wanted['status_min']) && $status >= (int)$wanted['status_min']) return true;
		}
		return $method !== null && in_array($method, $wanted['methods'] ?? [], true);
	}

	private function formatMessageEvidence($msg) {
		$t = isset($msg['t_ms']) ? '+' . (int)$msg['t_ms'] . 'ms' : 'time unavailable';
		$line = $msg['line'] ?? '';
		$cseq = !empty($msg['cseq']) ? ' CSeq ' . $msg['cseq'] : '';
		return trim("SIP {$t}: {$line}{$cseq}");
	}

	private function largestGapEvidence($messages) {
		$bestGap = 0;
		$bestPrev = null;
		$bestNext = null;
		$prev = null;
		foreach ($messages as $msg) {
			if ($prev !== null && isset($prev['t_ms'], $msg['t_ms'])) {
				$gap = (int)$msg['t_ms'] - (int)$prev['t_ms'];
				if ($gap > $bestGap) {
					$bestGap = $gap;
					$bestPrev = $prev;
					$bestNext = $msg;
				}
			}
			$prev = $msg;
		}
		if ($bestPrev === null || $bestNext === null) return [];
		return [
			'Largest signalling gap: ' . $bestGap . 'ms.',
			'Before gap: ' . $this->formatMessageEvidence($bestPrev),
			'After gap: ' . $this->formatMessageEvidence($bestNext),
		];
	}

	private function sdpEvidence($call) {
		$out = [];
		foreach (array_slice($call['summary']['media'] ?? [], 0, 2) as $media) {
			if (!empty($media['connection'])) $out[] = 'SDP connection: ' . $media['connection'];
			foreach (array_slice($media['media'] ?? [], 0, 2) as $mline) $out[] = 'SDP media: ' . $mline;
		}
		return $out;
	}

	private function compactRtpEvidence($call) {
		$rtp = $call['summary']['rtp'] ?? null;
		if (empty($rtp)) return [];
		$out = [];
		$ports = !empty($rtp['negotiated_media_ports']) ? implode(', ', array_map('strval', $rtp['negotiated_media_ports'])) : 'none';
		$out[] = 'RTP status: ' . ($rtp['status'] ?? 'unknown') . ', confidence ' . ($rtp['confidence'] ?? 'unknown') . ', matched streams ' . count($rtp['streams'] ?? []) . ', negotiated media ports ' . $ports . '.';
		foreach (array_slice($rtp['streams'] ?? [], 0, 2) as $stream) {
			$timing = $stream['timing_correlation']['basis'] ?? 'timing unavailable';
			$out[] = 'RTP stream: ' . ($stream['src'] ?? '') . ' -> ' . ($stream['dst'] ?? '') . ', basis ' . ($stream['correlation_basis'] ?? 'unknown') . ', timing ' . $timing . ', confidence ' . ($stream['confidence'] ?? 'unknown') . '.';
		}
		if (!empty($rtp['sequence_gap_estimate_percent'])) $out[] = 'RTP sequence gap estimate: ' . $rtp['sequence_gap_estimate_percent'] . '%.';
		return $out;
	}

	private function explainObservationCounts($observations) {
		$out = [];
		foreach ($observations as $id => $count) {
			$out[] = [
				'id' => $id,
				'count' => (int)$count,
				'explanation' => $this->explainObservation($id),
			];
		}
		return $out;
	}

	private function explainObservation($id) {
		$map = [
			'answered_invite' => 'The INVITE reached a successful 2xx final response.',
			'busy_response' => 'The captured signalling includes a busy response.',
			'rtp_both_directions' => 'RTP was captured in both directions at this capture point.',
			'rtp_one_direction_only' => 'RTP was captured in one direction only.',
			'rtp_absent_despite_answer' => 'No RTP seen at this capture point despite successful signalling and negotiated media.',
			'private_sdp_connection_address' => 'SDP advertised a private media address.',
			'large_signalling_gap' => 'A multi-second signalling gap was observed.',
			'retransmissions_seen' => 'Repeated byte-identical non-provisional SIP messages were observed.',
			'answered_without_ack_seen' => 'A successful INVITE response was captured without a matching ACK in the decoded packets.',
			'rtp_sequence_gaps' => 'RTP sequence gaps were estimated from captured packets.',
			'codec_mismatch_vs_sdp' => 'Observed RTP payload types differed from decoded SDP payload types.',
			'invite_without_final_response_in_capture' => 'An INVITE was captured without a final response in the decoded packets.',
			'cancelled_before_answer' => 'Cancellation evidence was captured before answer.',
			'normal_dialog_teardown_seen' => 'A BYE was captured in the dialog.',
			'sdp_present' => 'SDP media negotiation data was decoded.',
		];
		return $map[$id] ?? 'Decoded evidence produced this observation.';
	}

	private function summariseRtpEvidence($calls) {
		$statusCounts = [];
		$answeredStatusCounts = [];
		$confidenceCounts = [];
		$totalStreams = 0;
		$rtcpSeenCalls = 0;
		$sequenceGapCalls = 0;
		foreach ($calls as $call) {
			$rtp = $call['summary']['rtp'] ?? null;
			if (empty($rtp)) continue;
			$status = $rtp['status'] ?? 'unknown';
			$statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;
			if (($call['summary']['outcome'] ?? null) === 'answered') {
				$answeredStatusCounts[$status] = ($answeredStatusCounts[$status] ?? 0) + 1;
			}
			$confidence = $rtp['confidence'] ?? 'unknown';
			$confidenceCounts[$confidence] = ($confidenceCounts[$confidence] ?? 0) + 1;
			$totalStreams += count($rtp['streams'] ?? []);
			if (!empty($rtp['rtcp_seen'])) $rtcpSeenCalls++;
			if (isset($rtp['sequence_gap_estimate_percent']) && $rtp['sequence_gap_estimate_percent'] !== null && $rtp['sequence_gap_estimate_percent'] > 0) $sequenceGapCalls++;
		}
		return [
			'status_counts' => $statusCounts,
			'answered_status_counts' => $answeredStatusCounts,
			'confidence_counts' => $confidenceCounts,
			'total_streams_attached' => $totalStreams,
			'rtcp_seen_call_count' => $rtcpSeenCalls,
			'sequence_gap_call_count' => $sequenceGapCalls,
		];
	}

	private function rtpStatusConfidence($rtpSummary, $status) {
		if ($status === 'rtp_absent_despite_answer') return 'low';
		$confidenceCounts = $rtpSummary['confidence_counts'] ?? [];
		if (!empty($confidenceCounts['low'])) return 'low';
		if (!empty($confidenceCounts['medium'])) return 'medium';
		return 'high';
	}

	private function deriveReaderSummary($calls, $decoded, $outcomes, $observations) {
		$transactionCount = count($calls);
		$sipCount = count($decoded['messages'] ?? []);
		$unparsed = (int)($decoded['unparsed_sip_message_count'] ?? 0);
		$inviteStats = $this->countInviteOutcomes($calls);
		$nonInviteFailures = $this->countNonInviteFailures($calls);
		$hasReassembled = $this->hasReassembledMessages($calls);
		$lines = [];
		$inviteCount = (int)($inviteStats['total'] ?? 0);
		$lines[] = "This capture contains {$sipCount} SIP " . $this->pluralWord($sipCount, 'message') . " grouped into {$transactionCount} SIP " . $this->pluralWord($transactionCount, 'transaction') . ", including {$inviteCount} INVITE " . $this->pluralWord($inviteCount, 'call flow') . ".";
		if ($unparsed > 0) {
			$lines[] = "{$unparsed} SIP-like " . $this->pluralWord($unparsed, 'message') . " could not be parsed cleanly, so message and SIP transaction totals may be incomplete.";
		}
		if ($hasReassembled) {
			$lines[] = "Some SIP messages were recovered from simple TCP stream reassembly; conclusions based on those ladders should be treated with lower confidence.";
		}

		$answered = (int)($inviteStats['answered'] ?? 0);
		$completed = (int)($outcomes['completed'] ?? 0);
		$failed = (int)($inviteStats['failed'] ?? 0);
		$busy = (int)($inviteStats['busy'] ?? 0);
		$cancelled = (int)($inviteStats['cancelled'] ?? 0);
		$incomplete = (int)($inviteStats['incomplete_capture'] ?? 0);
		$authOnly = (int)($outcomes['auth_challenge'] ?? 0);

		if ($inviteStats['total'] > 0 && ($failed || $busy || $cancelled || $incomplete)) {
			if ($cancelled && !$failed && !$busy && !$incomplete) {
				$verb = $cancelled === 1 ? 'was' : 'were';
				$lines[] = "Attention: {$cancelled} INVITE " . $this->pluralWord($cancelled, 'call flow') . " {$verb} cancelled before answer.";
			} else {
				$issues = [];
				if ($failed) $issues[] = "{$failed} failed";
				if ($busy) $issues[] = "{$busy} busy";
				if ($cancelled) $issues[] = "{$cancelled} cancelled";
				if ($incomplete) $issues[] = "{$incomplete} incomplete";
				$issueCount = $failed + $busy + $cancelled + $incomplete;
				$lines[] = "Attention: " . implode(', ', $issues) . " INVITE " . $this->pluralWord($issueCount, 'call flow') . ($issueCount === 1 ? " needs" : " need") . " review.";
			}
		} elseif ($inviteStats['total'] > 0 && $answered) {
			$lines[] = "Main result: {$answered} INVITE " . $this->pluralWord($answered, 'call flow') . " reached 200 OK, so the captured INVITE " . $this->pluralWord($answered, 'call flow') . ($answered === 1 ? " looks" : " look") . " successful at SIP signalling level.";
		} elseif ($completed) {
			$ok = [];
			if ($completed) $ok[] = "{$completed} completed non-INVITE " . $this->pluralWord($completed, 'transaction');
			$lines[] = "Main result: " . implode(', ', $ok) . " reached successful SIP responses.";
		} elseif ($authOnly) {
			$lines[] = "Main result: authentication challenges were seen; these are often normal for SIP digest auth unless the flow stops there.";
		} else {
			$lines[] = "Main result: SIP signalling was decoded, but no clear completed call outcome was identified.";
		}

		if ($nonInviteFailures > 0 && $inviteStats['total'] > 0 && !$failed && !$busy && !$cancelled && !$incomplete) {
			$lines[] = "{$nonInviteFailures} non-INVITE SIP " . $this->pluralWord($nonInviteFailures, 'transaction') . ", such as OPTIONS/qualify checks, returned failure responses; that does not necessarily mean the captured INVITE " . $this->pluralWord((int)$inviteStats['total'], 'call flow') . " failed.";
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

		if ($transactionCount > 1) {
			$lines[] = "Best next step: re-run with a specific call_id to narrow the output to one ladder.";
		}

		return $lines;
	}

	private function primarySipMethod($methods) {
		if (!is_array($methods) || empty($methods)) return 'SIP';
		foreach (['INVITE', 'OPTIONS', 'REGISTER', 'SUBSCRIBE', 'NOTIFY', 'MESSAGE', 'BYE', 'CANCEL', 'ACK'] as $method) {
			if (in_array($method, $methods, true)) return $method;
		}
		return (string)reset($methods);
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

	private function hasReassembledMessages($calls) {
		foreach ($calls as $call) {
			foreach ($call['messages'] ?? [] as $msg) {
				if (!empty($msg['reassembled'])) return true;
			}
		}
		return false;
	}

	private function hasCleanAnsweredCalls($calls) {
		foreach ($calls as $call) {
			if ($this->isCleanAnsweredCall($call)) return true;
		}
		return false;
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
			if (isset($methods['INVITE']) && $outcome === 'failed') { $score += 1000; $reason = 'failed INVITE call flow'; }
			elseif (isset($methods['INVITE']) && ($outcome === 'busy' || $outcome === 'cancelled')) { $score += 900; $reason = "{$outcome} INVITE call flow"; }
			elseif (isset($methods['INVITE']) && $outcome === 'incomplete_capture') { $score += 800; $reason = 'incomplete INVITE call flow'; }
			elseif (isset($methods['INVITE']) && $outcome === 'answered') { $score += 100; $reason = 'answered INVITE call flow with the most signalling detail'; }
			elseif ($outcome === 'failed') { $score += 35; $reason = 'failed SIP transaction'; }
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
