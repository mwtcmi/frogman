<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

/**
 * List call recordings linked to CDR rows. The audio file is MixMonitor output
 * under /var/spool/asterisk/monitor/YYYY/MM/DD/<file>; CDR carries the bare
 * filename in recordingfile and calldate gives the date directory.
 *
 * Each returned row carries a play_url (auth-gated stream token) ready for the
 * chat formatter to embed as an <audio controls> chip and for MCP clients to
 * render as a link the user clicks.
 *
 * v1 is WAV-only: gsm/sln/sln16 files are listed with a played=false flag and a
 * format note rather than minted. Adding an ffmpeg pass for those is a v2 call.
 *
 * Reuses AbstractTool helpers: applyDefaultReportWindow (today 00:00 to now
 * default so an unbounded scan can't ever happen), isRealCall (drops
 * paging/lockdown/echo-test non-call rows), dedupeByCall (collapses Local/X;1+;2
 * fan-out so one call counts once).
 */
class ListCallRecordings extends AbstractTool {
	public function name() { return 'fm_list_call_recordings'; }

	public function description() {
		return 'List call recordings from CDR with auth-gated play URLs. Filters: caller (substring on src or callerid), callee (substring on dst), name (resolves via users.name then matches either leg), ext (matches either src or dst), disposition (ANSWERED, NO ANSWER, BUSY, FAILED), min_duration (seconds), date_from / date_to (defaults to today). Pair with show / play <recording_id> for inline playback. Read-only.';
	}

	public function validate($params) {
		if (isset($params['min_duration']) && (!is_numeric($params['min_duration']) || (int)$params['min_duration'] < 0)) {
			return 'Parameter "min_duration" must be a non-negative integer (seconds).';
		}
		if (!empty($params['disposition'])) {
			$ok = ['ANSWERED','NO ANSWER','BUSY','FAILED'];
			if (!in_array(strtoupper($params['disposition']), $ok, true)) {
				return 'Parameter "disposition" must be one of: ' . implode(', ', $ok);
			}
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

		$where = ["recordingfile IS NOT NULL", "recordingfile != ''"];
		$binds = [];

		if (!empty($params['date_from'])) { $where[] = 'calldate >= ?'; $binds[] = $params['date_from']; }
		if (!empty($params['date_to']))   { $where[] = 'calldate <= ?'; $binds[] = $params['date_to']; }

		if (!empty($params['caller'])) {
			$where[] = '(src LIKE ? OR clid LIKE ? OR cnum LIKE ?)';
			$like = '%' . $params['caller'] . '%';
			$binds[] = $like; $binds[] = $like; $binds[] = $like;
		}
		if (!empty($params['callee'])) {
			$where[] = '(dst LIKE ? OR cnam LIKE ?)';
			$like = '%' . $params['callee'] . '%';
			$binds[] = $like; $binds[] = $like;
		}
		if (!empty($params['ext'])) {
			$ext = preg_replace('/[^0-9]/', '', (string)$params['ext']);
			if ($ext !== '') {
				$where[] = '(src = ? OR dst = ?)';
				$binds[] = $ext; $binds[] = $ext;
			}
		}
		// "name" resolves to extension(s) via the users table first; the resulting
		// list is OR'd into src/dst/clid so "from tom" finds Tom regardless of leg.
		if (!empty($params['name'])) {
			$sth = $db->prepare("SELECT extension FROM users WHERE name LIKE ?");
			$sth->execute(['%' . $params['name'] . '%']);
			$matched = $sth->fetchAll(\PDO::FETCH_COLUMN);
			if (!empty($matched)) {
				$qs = implode(',', array_fill(0, count($matched), '?'));
				$where[] = "(src IN ({$qs}) OR dst IN ({$qs}) OR clid LIKE ?)";
				foreach ($matched as $e) $binds[] = $e;
				foreach ($matched as $e) $binds[] = $e;
				$binds[] = '%' . $params['name'] . '%';
			} else {
				$where[] = 'clid LIKE ?';
				$binds[] = '%' . $params['name'] . '%';
			}
		}
		if (!empty($params['disposition'])) {
			$where[] = 'disposition = ?';
			$binds[] = strtoupper($params['disposition']);
		}
		if (!empty($params['min_duration'])) {
			$where[] = 'duration >= ?';
			$binds[] = (int)$params['min_duration'];
		}

		// Overfetch so dedupeByCall can collapse multi-leg fan-out without
		// undercutting the requested limit.
		$over = $limit * 3;
		$sql = "SELECT calldate, src, dst, clid, cnum, disposition, duration, billsec,
		               channel, dstchannel, dcontext, recordingfile, linkedid, uniqueid
		        FROM asteriskcdrdb.cdr
		        WHERE " . implode(' AND ', $where) . "
		        ORDER BY calldate DESC
		        LIMIT {$over}";
		$sth = $db->prepare($sql);
		$sth->execute($binds);
		$rows = $sth->fetchAll(\PDO::FETCH_ASSOC);

		// Drop paging multicast, lockdown beacons, echo tests etc. — same filter
		// the v2.3.0 reporting tools use so the recording list matches the call
		// list a user would expect.
		$rows = array_values(array_filter($rows, function($r) { return $this->isRealCall($r); }));
		$rows = $this->dedupeByCall($rows);
		$rows = array_slice($rows, 0, $limit);

		$out = [];
		foreach ($rows as $r) {
			$file = trim((string)$r['recordingfile']);
			if ($file === '') continue;
			$path = $this->resolveRecordingPath($file, $r['calldate']);
			$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
			$playable = ($ext === 'wav') && $path && is_file($path);
			$playUrl = null;
			$formatNote = null;
			if ($playable) {
				$display = basename($file);
				$playUrl = $this->frogman->mintDownload('recording', $path, [
					'mime_type' => 'audio/wav',
					'display_name' => $display,
					'ttl' => 1800,
					'meta' => ['linkedid' => $r['linkedid'] ?? '', 'src' => $r['src'], 'dst' => $r['dst']],
				]);
				if ($playUrl === null) {
					$formatNote = 'File exists but failed whitelist check.';
				}
			} elseif (!$path || !is_file((string)$path)) {
				$formatNote = 'File not found on disk.';
			} else {
				$formatNote = 'Format ' . $ext . ' is not playable inline (v1 is WAV only).';
			}

			$out[] = [
				'calldate' => $r['calldate'],
				'caller' => $r['src'],
				'caller_name' => $this->lookupExtensionName($r['src']),
				'callee' => $r['dst'],
				'callee_name' => $this->lookupExtensionName($r['dst']),
				'callerid' => $r['clid'],
				'disposition' => $r['disposition'],
				'duration' => (int)$r['duration'],
				'billsec' => (int)$r['billsec'],
				'linkedid' => $r['linkedid'],
				'recording_file' => $file,
				'file_path' => $path,
				'playable' => $playable,
				'play_url' => $playUrl,
				'format_note' => $formatNote,
			];
		}

		return [
			'count' => count($out),
			'filters' => [
				'caller' => $params['caller'] ?? null,
				'callee' => $params['callee'] ?? null,
				'name' => $params['name'] ?? null,
				'ext' => $params['ext'] ?? null,
				'disposition' => $params['disposition'] ?? null,
				'date_from' => $params['date_from'] ?? null,
				'date_to' => $params['date_to'] ?? null,
				'min_duration' => $params['min_duration'] ?? null,
			],
			'recordings' => $out,
		];
	}

	/**
	 * Map (recordingfile, calldate) to the on-disk MixMonitor path.
	 * /var/spool/asterisk/monitor/YYYY/MM/DD/<recordingfile>
	 * Returns null if calldate is malformed.
	 */
	private function resolveRecordingPath($file, $calldate) {
		$ts = strtotime((string)$calldate);
		if ($ts === false) return null;
		$dir = '/var/spool/asterisk/monitor/' . date('Y/m/d', $ts);
		return $dir . '/' . $file;
	}
}
