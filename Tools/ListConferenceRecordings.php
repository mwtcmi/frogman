<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

/**
 * List conference (ConfBridge / Meetme) recordings. Same CDR-backed pattern as
 * fm_list_call_recordings but scopes to rows whose channel/dcontext/recordingfile
 * point at a conference call.
 *
 * ConfBridge detection (any of):
 *   - dcontext LIKE '%confbridge%' or '%meetme%'
 *   - dstchannel LIKE 'ConfBridge/%'
 *   - recordingfile starts with 'confbridge-' or 'meetme-'
 *
 * Mints the same auth-gated stream tokens via $this->frogman->mintDownload so
 * a row's play_url drops into the chat formatter's audio chip with no extra
 * plumbing.
 */
class ListConferenceRecordings extends AbstractTool {
	public function name() { return 'fm_list_conference_recordings'; }

	public function description() {
		return 'List ConfBridge / Meetme conference recordings from CDR with auth-gated play URLs. Filters: caller (substring on src/callerid), date_from / date_to (defaults to today), min_duration (seconds), limit (default 50). Read-only.';
	}

	public function validate($params) {
		if (isset($params['min_duration']) && (!is_numeric($params['min_duration']) || (int)$params['min_duration'] < 0)) {
			return 'Parameter "min_duration" must be a non-negative integer (seconds).';
		}
		if (isset($params['limit']) && (!is_numeric($params['limit']) || (int)$params['limit'] < 1)) {
			return 'Parameter "limit" must be a positive integer.';
		}
		return true;
	}

	public function execute($params, $context) {
		$db = $this->freepbx->Database;
		$params = $this->applyDefaultReportWindow($params);
		$limit = max(1, min(500, (int)($params['limit'] ?? 50)));

		$where = [
			"recordingfile IS NOT NULL", "recordingfile != ''",
			"(dcontext LIKE '%confbridge%' OR dcontext LIKE '%meetme%'
			   OR dstchannel LIKE 'ConfBridge/%' OR channel LIKE 'ConfBridge/%'
			   OR recordingfile LIKE 'confbridge-%' OR recordingfile LIKE 'meetme-%')",
		];
		$binds = [];
		if (!empty($params['date_from'])) { $where[] = 'calldate >= ?'; $binds[] = $params['date_from']; }
		if (!empty($params['date_to']))   { $where[] = 'calldate <= ?'; $binds[] = $params['date_to']; }
		if (!empty($params['caller'])) {
			$like = '%' . $params['caller'] . '%';
			$where[] = '(src LIKE ? OR clid LIKE ?)';
			$binds[] = $like; $binds[] = $like;
		}
		if (!empty($params['min_duration'])) {
			$where[] = 'duration >= ?';
			$binds[] = (int)$params['min_duration'];
		}

		$over = $limit * 3;
		$sql = "SELECT calldate, src, dst, clid, disposition, duration, billsec, channel, dstchannel, dcontext, recordingfile, linkedid
		        FROM asteriskcdrdb.cdr
		        WHERE " . implode(' AND ', $where) . "
		        ORDER BY calldate DESC
		        LIMIT {$over}";
		$sth = $db->prepare($sql);
		$sth->execute($binds);
		$rows = $sth->fetchAll(\PDO::FETCH_ASSOC);
		$rows = $this->dedupeByCall($rows);
		$rows = array_slice($rows, 0, $limit);

		$out = [];
		foreach ($rows as $r) {
			$file = trim((string)$r['recordingfile']);
			if ($file === '') continue;
			$ts = strtotime((string)$r['calldate']);
			$path = ($ts === false) ? null : ('/var/spool/asterisk/monitor/' . date('Y/m/d', $ts) . '/' . $file);
			$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
			$playable = ($ext === 'wav') && $path && is_file($path);
			$playUrl = null;
			$note = null;
			if ($playable) {
				$playUrl = $this->frogman->mintDownload('recording', $path, [
					'mime_type' => 'audio/wav',
					'display_name' => basename($file),
					'ttl' => 1800,
					'meta' => ['linkedid' => $r['linkedid'] ?? '', 'conference' => true],
				]);
			} elseif (!$path || !is_file($path)) {
				$note = 'File not found on disk.';
			} else {
				$note = 'Format ' . $ext . ' is not playable inline (v1 is WAV only).';
			}

			// Best-effort conference identifier — the filename usually carries it
			// (confbridge-<room>-<unique>.wav). Fall back to dst.
			$confId = '';
			if (preg_match('/^(?:confbridge|meetme)-([^-]+)/i', $file, $m)) {
				$confId = $m[1];
			} elseif (!empty($r['dst'])) {
				$confId = $r['dst'];
			}

			$out[] = [
				'calldate' => $r['calldate'],
				'conference' => $confId,
				'caller' => $r['src'],
				'caller_name' => $this->lookupExtensionName($r['src']),
				'callerid' => $r['clid'],
				'disposition' => $r['disposition'],
				'duration' => (int)$r['duration'],
				'linkedid' => $r['linkedid'],
				'recording_file' => $file,
				'file_path' => $path,
				'playable' => $playable,
				'play_url' => $playUrl,
				'format_note' => $note,
			];
		}

		return [
			'count' => count($out),
			'filters' => [
				'caller' => $params['caller'] ?? null,
				'date_from' => $params['date_from'] ?? null,
				'date_to' => $params['date_to'] ?? null,
				'min_duration' => $params['min_duration'] ?? null,
			],
			'recordings' => $out,
		];
	}
}
