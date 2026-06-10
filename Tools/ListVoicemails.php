<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

/**
 * List voicemail messages via the Voicemail BMO. Iterates mailboxes (either
 * one or every mailbox under the context) and pulls per-folder messages
 * through `Voicemail->getMessagesByExtensionFolder`, which already does the
 * sidecar parse, format detection, and (when Scribe is installed/licensed)
 * transcription-URL injection. Pure BMO calls, no direct spool walking.
 *
 * Note on Voicemail's per-instance cache: getMessagesByExtension stores the
 * full result in $this->messageCache and a subsequent call with a different
 * extension returns the same cached payload (the cache key is "did we call
 * this at all", not the extension). Voicemail exposes a public clearCache()
 * for exactly this case — UCP uses it the same way. We call it before each
 * per-mailbox query.
 *
 * v1 is WAV-only for inline playback. gsm + uppercase .WAV are listed with a
 * playable=false flag and a format_note; transcoding for browser playback is
 * a future call.
 */
class ListVoicemails extends AbstractTool {
	const VALID_FOLDERS = ['INBOX', 'Old', 'Urgent', 'Family', 'Friends', 'Work'];

	public function name() { return 'fm_list_voicemails'; }

	public function description() {
		return 'List voicemail messages via Voicemail BMO with auth-gated play URLs. Params: mailbox (extension number; omit for all), folder (INBOX default, Old, Urgent, Family, Friends, Work), context (default "default"), limit (default 50). Each row carries caller-id, original date, duration, play_url for inline audio, and (if Scribe is licensed) a transcript URL. Read-only.';
	}

	public function validate($params) {
		if (!empty($params['folder']) && !in_array($params['folder'], self::VALID_FOLDERS, true)) {
			return 'Parameter "folder" must be one of: ' . implode(', ', self::VALID_FOLDERS);
		}
		if (!empty($params['mailbox']) && !preg_match('/^[a-zA-Z0-9_-]+$/', (string)$params['mailbox'])) {
			return 'Parameter "mailbox" must be alphanumeric (extension number or mailbox name).';
		}
		if (!empty($params['context']) && !preg_match('/^[a-zA-Z0-9_-]+$/', (string)$params['context'])) {
			return 'Parameter "context" must be alphanumeric.';
		}
		if (isset($params['limit']) && (!is_numeric($params['limit']) || (int)$params['limit'] < 1)) {
			return 'Parameter "limit" must be a positive integer.';
		}
		return true;
	}

	public function execute($params, $context) {
		$folder = $params['folder'] ?? 'INBOX';
		$ctx = $params['context'] ?? 'default';
		$mailbox = $params['mailbox'] ?? null;
		$limit = max(1, min(500, (int)($params['limit'] ?? 50)));

		// Mailbox enumeration via Voicemail BMO: getVoicemail() returns the
		// vmconf keyed by context; each context maps mailbox-number => box info.
		// Skip 'general'/'zonemessages'/'pbxaliases' — those are settings, not
		// mailboxes. Same skip list the existing fm_list_voicemail tool uses.
		$mailboxes = [];
		if ($mailbox) {
			$mailboxes[] = $mailbox;
		} else {
			$vmconf = $this->freepbx->Voicemail->getVoicemail();
			$skip = ['general', 'zonemessages', 'pbxaliases', 'device'];
			if (!empty($vmconf[$ctx]) && is_array($vmconf[$ctx])) {
				foreach ($vmconf[$ctx] as $mb => $box) {
					if (in_array($mb, $skip, true)) continue;
					if (!is_array($box)) continue;
					$mailboxes[] = (string)$mb;
				}
			}
		}

		$messages = [];
		foreach ($mailboxes as $mb) {
			// Cache reset before each call — getMessagesByExtension caches its
			// result globally and would otherwise return the previous mailbox's
			// messages on the second iteration.
			$this->freepbx->Voicemail->clearCache();
			$result = $this->freepbx->Voicemail->getMessagesByExtensionFolder(
				$mb,
				$folder,
				'desc',
				'origtime',
				0,
				$limit
			);
			$bmoMessages = is_array($result) && !empty($result['messages']) ? $result['messages'] : [];
			foreach ($bmoMessages as $m) {
				$messages[] = $this->shape($m, $mb, $folder);
			}
		}

		// Final newest-first sort + limit (BMO sorts per-mailbox; we need
		// a global ordering across mailboxes when scanning many).
		usort($messages, function($a, $b) {
			return ($b['origtime'] ?? 0) <=> ($a['origtime'] ?? 0);
		});
		$messages = array_slice($messages, 0, $limit);

		return [
			'count' => count($messages),
			'mailboxes_scanned' => count($mailboxes),
			'folder' => $folder,
			'context' => $ctx,
			'mailbox' => $mailbox,
			'voicemails' => $messages,
		];
	}

	/**
	 * Reshape a Voicemail BMO message hash into our tool's flat output. The
	 * BMO record has the sidecar fields plus `format` (an array of available
	 * audio formats with path + filename + length) and optionally `converttotext`
	 * (Scribe transcription URL — only present when Scribe is licensed).
	 */
	private function shape(array $m, $mb, $folder) {
		// Prefer lowercase .wav for inline playback. gsm / uppercase .WAV
		// drop through to playable=false with a format_note.
		$audioPath = null;
		$chosenExt = null;
		if (!empty($m['format']) && is_array($m['format'])) {
			foreach (['wav', 'WAV', 'gsm'] as $tryExt) {
				if (isset($m['format'][$tryExt]['path'], $m['format'][$tryExt]['filename'])) {
					$audioPath = $m['format'][$tryExt]['path'] . '/' . $m['format'][$tryExt]['filename'];
					$chosenExt = $tryExt;
					break;
				}
			}
		}

		$playUrl = null;
		$note = null;
		if ($audioPath && $chosenExt === 'wav' && is_file($audioPath)) {
			$playUrl = $this->frogman->mintDownload('voicemail', $audioPath, [
				'mime_type' => 'audio/wav',
				'display_name' => "vm-{$mb}-{$folder}-msg" . ($m['fid'] ?? 'unknown') . ".wav",
				'ttl' => 1800,
				'meta' => ['mailbox' => $mb, 'folder' => $folder, 'msg_id' => $m['msg_id'] ?? ''],
			]);
		} elseif ($audioPath) {
			$note = "Format {$chosenExt} is not played inline (v1 is lowercase wav only).";
		} else {
			$note = 'No playable audio file associated with this message.';
		}

		$out = [
			'mailbox' => (string)$mb,
			'context' => $m['context'] ?? 'default',
			'folder' => $folder,
			'msg' => $m['fid'] ?? '',
			'msg_id' => $m['msg_id'] ?? '',
			'callerid' => $m['callerid'] ?? '',
			'origdate' => $m['origdate'] ?? '',
			'origtime' => isset($m['origtime']) ? (int)$m['origtime'] : 0,
			'duration' => isset($m['duration']) ? (int)$m['duration'] : 0,
			'audio_file' => $audioPath,
			'playable' => $playUrl !== null,
			'play_url' => $playUrl,
			'format_note' => $note,
		];

		// Pass through the Scribe transcript URL when Voicemail BMO populated
		// one. We never touch Scribe directly — Voicemail handles the licensed
		// check and URL minting; we only forward the result.
		if (!empty($m['converttotext'])) {
			$out['transcript_url'] = $m['converttotext'];
		}

		return $out;
	}
}
