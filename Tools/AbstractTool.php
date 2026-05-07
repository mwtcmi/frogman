<?php
namespace FreePBX\modules\Frogman\Tools;

abstract class AbstractTool {

	protected $freepbx;
	protected $frogman;

	// Permission levels
	const PERM_READ = 'read';       // List/show/get operations
	const PERM_WRITE = 'write';     // Create/update/delete PBX objects
	const PERM_ADMIN = 'admin';     // Module management, firewall, advanced settings, fwconsole

	public function __construct($freepbx, $frogman) {
		$this->freepbx = $freepbx;
		$this->frogman = $frogman;
	}

	abstract public function name();
	abstract public function description();
	abstract public function validate($params);
	abstract public function execute($params, $context);

	/**
	 * Permission level required: 'read', 'write', or 'admin'.
	 * Override in subclass. Default is 'read'.
	 */
	public function permissionLevel() {
		return self::PERM_READ;
	}

	/**
	 * Legacy method — still used by some tools.
	 * Returns null (no specific FreePBX permission needed beyond the level).
	 */
	public function requiredPermission() {
		return null;
	}

	/**
	 * Check if sudo is available for fwconsole.
	 * Returns true if available, false if not.
	 */
	protected function canSudo() {
		exec('sudo -n /usr/sbin/fwconsole --version 2>&1', $out, $ec);
		return $ec === 0;
	}

	/**
	 * Filter for Asterisk internal channels that aren't real phone calls.
	 * Message/* = SIP MESSAGE / SMS queue. AsyncGoto/* = dialplan jumps.
	 * Both show up in `core show channels` but have blank caller IDs and no human on them.
	 * Used by every tool that counts/lists active channels.
	 */
	protected function isAsteriskInternalChannel($lineOrName) {
		return (bool) preg_match('#^(Message|AsyncGoto)/#i', $lineOrName);
	}

	/**
	 * Describe a FreePBX dialplan destination string ("ivr-8,s,1") with a structured
	 * label for human display. Returns ['type', 'label', 'key'] — type is the destination
	 * category (extension/ringgroup/queue/ivr/etc.), label is the display string,
	 * key is a stable dedup key (type-prefixed).
	 *
	 * Note: this is the OPPOSITE direction from AddInboundRoute::resolveDestination(),
	 * which parses friendly shorthand ("rg 600") INTO a dialplan string. Keep the
	 * verb difference (describe vs resolve) to avoid the collision.
	 */
	protected function describeDestination($dest, $db = null) {
		if ($db === null) $db = $this->freepbx->Database;
		if (empty($dest)) return ['type' => 'unknown', 'label' => '⚠️ Not Configured', 'key' => 'noconfig'];
		$parts = explode(',', $dest);
		$context = $parts[0] ?? '';
		$exten = $parts[1] ?? '';

		if (strpos($context, 'from-did-direct') !== false || strpos($context, 'ext-local') !== false) {
			$sth = $db->prepare("SELECT name FROM users WHERE extension = ?");
			$sth->execute([$exten]);
			$name = $sth->fetchColumn() ?: '';
			return ['type' => 'extension', 'label' => $name ? "{$exten} ({$name})" : "Ext {$exten}", 'key' => "ext:{$exten}"];
		}
		if (strpos($context, 'ext-group') !== false) {
			$sth = $db->prepare("SELECT description FROM ringgroups WHERE grpnum = ?");
			$sth->execute([$exten]);
			$name = $sth->fetchColumn() ?: '';
			return ['type' => 'ringgroup', 'label' => $name ? "Ring Group {$exten}: {$name}" : "Ring Group {$exten}", 'key' => "rg:{$exten}"];
		}
		if (strpos($context, 'ext-queues') !== false) {
			$sth = $db->prepare("SELECT data FROM queues_config WHERE extension = ? AND keyword = 'descr' LIMIT 1");
			$sth->execute([$exten]);
			$name = $sth->fetchColumn() ?: '';
			return ['type' => 'queue', 'label' => $name ? "Queue {$exten}: {$name}" : "Queue {$exten}", 'key' => "q:{$exten}"];
		}
		if (strpos($context, 'ivr-') !== false) {
			$id = preg_replace('/[^0-9]/', '', $context);
			$sth = $db->prepare("SELECT name FROM ivr_details WHERE id = ?");
			$sth->execute([$id]);
			$name = $sth->fetchColumn() ?: '';
			return ['type' => 'ivr', 'label' => $name ? "IVR: {$name}" : "IVR {$id}", 'key' => "ivr:{$id}"];
		}
		if (strpos($context, 'timeconditions') !== false) {
			$id = preg_replace('/[^0-9]/', '', $exten);
			$sth = $db->prepare("SELECT displayname FROM timeconditions WHERE timeconditions_id = ?");
			$sth->execute([$id]);
			$name = $sth->fetchColumn() ?: '';
			return ['type' => 'timecondition', 'label' => $name ? "Time: {$name}" : "Time Cond {$id}", 'key' => "tc:{$id}"];
		}
		if (strpos($dest, 'vmu') !== false || strpos($context, 'app-vmmain') !== false) {
			$ext = preg_replace('/[^0-9]/', '', $exten);
			return ['type' => 'voicemail', 'label' => "Voicemail {$ext}", 'key' => "vm:{$ext}"];
		}
		if (strpos($context, 'app-announcement') !== false) {
			$sth = $db->prepare("SELECT description FROM announcement WHERE announcement_id = ?");
			$sth->execute([$exten]);
			$name = $sth->fetchColumn() ?: '';
			return ['type' => 'announcement', 'label' => $name ? "Announcement: {$name}" : "Announcement {$exten}", 'key' => "ann:{$exten}"];
		}
		if (strpos($context, 'app-blackhole') !== false) {
			$labels = ['hangup'=>'Hangup', 'congestion'=>'Congestion', 'busy'=>'Busy', 'zapateller'=>'Zapateller', 'musiconhold'=>'Music on Hold', 'ring'=>'Ring Forever'];
			return ['type' => 'terminate', 'label' => $labels[$exten] ?? "Terminate: {$exten}", 'key' => "term:{$exten}"];
		}
		return ['type' => 'unknown', 'label' => trim($dest), 'key' => 'raw:' . md5($dest)];
	}

	/**
	 * Run a fwconsole command with sudo if available.
	 * Returns ['output' => string, 'exit_code' => int] or the root-required message.
	 */
	protected function runAsRoot($cmd, $confirm = true) {
		if (!$confirm) {
			return null; // dry-run, don't check yet
		}
		if (!$this->canSudo()) {
			return [
				'needs_root' => true,
				'message' => "This command requires root access.",
			];
		}
		$output = [];
		$ec = 0;
		exec("sudo /usr/sbin/fwconsole {$cmd} 2>&1", $output, $ec);
		$raw = implode("\n", $output);
		// Strip ANSI codes
		$raw = preg_replace('/\x1B\[[0-9;]*[a-zA-Z]/', '', $raw);
		return ['output' => trim($raw), 'exit_code' => $ec];
	}
}
