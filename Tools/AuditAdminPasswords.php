<?php
namespace FreePBX\modules\Frogman\Tools;

require_once __DIR__ . '/AbstractTool.php';

class AuditAdminPasswords extends AbstractTool {

	public function name() {
		return 'fm_audit_admin_passwords';
	}

	public function description() {
		return 'Audit FreePBX GUI admin accounts (ampusers) for weak passwords. Hashes a list of common passwords with SHA1 (the algorithm FreePBX uses for ampusers.password_sha1) and compares to stored hashes; also flags username==password matches. Stored hashes are never echoed — findings name only the username + reason. Read-only.';
	}

	public function validate($params) {
		return true;
	}

	public function requiredPermission() {
		return null;
	}

	// Direct credential-weakness findings on admin accounts that can pivot to
	// full PBX control. Admin-only, no exceptions.
	public function permissionLevel() { return self::PERM_ADMIN; }

	public function execute($params, $context) {
		$findings = [];
		$counts = ['critical' => 0, 'high' => 0];

		// No list-all BMO for ampusers — ampuser.class.php constructs per-user
		// via username. Direct read is justified under DEVELOPMENT.md DB rules
		// (reads of other modules' tables OK when no BMO method exposes the
		// data efficiently). Read-only, never writes.
		$rows = [];
		try {
			$sth = \FreePBX::Database()->prepare('SELECT username, password_sha1 FROM ampusers');
			$sth->execute();
			$rows = $sth->fetchAll(\PDO::FETCH_ASSOC);
		} catch (\Throwable $e) {
			return [
				'count' => 0,
				'severity_counts' => $counts,
				'findings' => [],
				'summary' => 'Could not read ampusers table: ' . $e->getMessage(),
			];
		}

		$weakHashes = $this->buildWeakHashIndex();

		foreach ($rows as $row) {
			$user = (string)($row['username'] ?? '');
			$hash = strtolower(trim((string)($row['password_sha1'] ?? '')));
			if ($user === '' || $hash === '') continue;

			// username == password is the worst case (admin/admin pattern)
			$userHash = sha1($user);
			if (hash_equals($userHash, $hash)) {
				$findings[] = [
					'username' => $user,
					'severity' => 'critical',
					'issue' => 'Password equals username',
					'recommendation' => 'Reset this admin password to a strong unique value via Admin → Administrators in the FreePBX GUI.',
				];
				$counts['critical']++;
				continue;
			}

			if (isset($weakHashes[$hash])) {
				$matched = $weakHashes[$hash];
				$findings[] = [
					'username' => $user,
					'severity' => 'critical',
					'issue' => 'Password matches a known-weak password (' . $matched['category'] . ')',
					'recommendation' => 'Reset this admin password to a strong unique value via Admin → Administrators in the FreePBX GUI.',
				];
				$counts['critical']++;
				continue;
			}

			// Stale/legacy markers: SHA1 is always 40 hex chars. Anything
			// shorter likely means an unmigrated or malformed row.
			if (strlen($hash) !== 40 || !ctype_xdigit($hash)) {
				$findings[] = [
					'username' => $user,
					'severity' => 'high',
					'issue' => 'Password hash is not a 40-char hex SHA1 — unmigrated or corrupted row',
					'recommendation' => 'Reset this admin password to force a fresh hash.',
				];
				$counts['high']++;
			}
		}

		usort($findings, function ($a, $b) {
			$order = ['critical' => 0, 'high' => 1];
			$sevDiff = $order[$a['severity']] - $order[$b['severity']];
			if ($sevDiff !== 0) return $sevDiff;
			return strnatcmp($a['username'], $b['username']);
		});

		return [
			'count' => count($findings),
			'severity_counts' => $counts,
			'findings' => $findings,
			'summary' => $this->summary($findings, $counts),
		];
	}

	/**
	 * Map of sha1(password) → ['password' => ..., 'category' => ...]. The
	 * password itself is kept ONLY so we can recover the category label;
	 * findings never echo the password. Built at execute() time — small list,
	 * not worth caching.
	 */
	private function buildWeakHashIndex() {
		$lists = [
			'common defaults' => [
				'password', 'admin', 'root', 'changeme', 'default',
				'welcome', 'letmein', 'secret', 'guest', 'test',
			],
			'vendor defaults' => [
				'freepbx', 'asterisk', 'sangoma', 'pbx', 'voip', 'sip', 'dial',
			],
			'numeric defaults' => [
				'1234', '12345', '123456', '1234567', '12345678', '123456789',
				'0000', '00000', '000000', '111111', '222222', '666666',
				'1111', '4321', '54321', '654321', '987654321',
			],
			'keyboard walks' => [
				'qwerty', 'qwertyuiop', 'asdfgh', 'zxcvbn', 'qwerty123',
			],
			'common weak' => [
				'iloveyou', 'monkey', 'dragon', 'abc123', 'password1',
				'admin123', 'root123', 'login', 'master',
			],
		];

		$index = [];
		foreach ($lists as $category => $passwords) {
			foreach ($passwords as $pw) {
				$index[sha1($pw)] = ['password' => $pw, 'category' => $category];
			}
		}
		return $index;
	}

	private function summary($findings, $counts) {
		if (count($findings) === 0) {
			return 'No weak admin passwords found.';
		}
		$parts = [];
		foreach (['critical', 'high'] as $sev) {
			if ($counts[$sev] > 0) $parts[] = "{$counts[$sev]} {$sev}";
		}
		return count($findings) . ' weak admin password(s) found: ' . implode(', ', $parts) . '.';
	}
}
