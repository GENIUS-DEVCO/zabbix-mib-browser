<?php declare(strict_types = 0);
/*
** MIB Browser module for Zabbix 8.0
** Security assessment of SNMP interfaces: is the traffic encrypted (SNMPv3 authPriv) and with which algorithms.
** Pure PHP (Zabbix numeric codes are used directly) so it can be unit tested.
*/

namespace Modules\MibBrowser\Lib;

class SnmpSecurity {

	/** Encrypted with strong algorithms (SNMPv3 authPriv, SHA-2 / AES). */
	public const LEVEL_ENCRYPTED = 'encrypted';
	/** Encrypted, but with legacy algorithms (MD5 or DES) or SHA-1. */
	public const LEVEL_WEAK = 'weak';
	/** SNMPv3 authNoPriv: authenticated, values travel in clear text. */
	public const LEVEL_AUTH_ONLY = 'auth_only';
	/** SNMPv1/v2c (community in clear text) or SNMPv3 noAuthNoPriv. */
	public const LEVEL_NONE = 'none';

	/** Order used to prefer the most secure interface. */
	public const RANK = [
		self::LEVEL_ENCRYPTED => 0,
		self::LEVEL_WEAK => 1,
		self::LEVEL_AUTH_ONLY => 2,
		self::LEVEL_NONE => 3
	];

	// Zabbix codes (include/defines.inc.php).
	private const VERSION_NAMES = [1 => 'SNMPv1', 2 => 'SNMPv2c', 3 => 'SNMPv3'];
	private const SECURITY_LEVEL_NAMES = [0 => 'noAuthNoPriv', 1 => 'authNoPriv', 2 => 'authPriv'];
	private const AUTH_NAMES = [0 => 'MD5', 1 => 'SHA1', 2 => 'SHA224', 3 => 'SHA256', 4 => 'SHA384', 5 => 'SHA512'];
	private const PRIV_NAMES = [0 => 'DES', 1 => 'AES128', 2 => 'AES192', 3 => 'AES256', 4 => 'AES192C', 5 => 'AES256C'];

	private const AUTH_MD5 = 0;
	private const AUTH_SHA1 = 1;
	private const PRIV_DES = 0;

	/**
	 * Assess interface details (as returned by hostinterface.get "details").
	 *
	 * @return array  ['level', 'encrypted' (bool), 'summary' (short text), 'issues' (list of texts)]
	 */
	public static function assess(array $details): array {
		$version = (int) ($details['version'] ?? 2);
		$name = self::VERSION_NAMES[$version] ?? 'SNMPv'.$version;

		if ($version != 3) {
			return [
				'level' => self::LEVEL_NONE,
				'encrypted' => false,
				'summary' => $name,
				'issues' => [I18n::t('The community and values travel in clear text on the network.')]
			];
		}

		$security_level = (int) ($details['securitylevel'] ?? 0);
		$summary = $name.' '.(self::SECURITY_LEVEL_NAMES[$security_level] ?? $security_level);

		if ($security_level == 0) {
			return [
				'level' => self::LEVEL_NONE,
				'encrypted' => false,
				'summary' => $summary,
				'issues' => [I18n::t('No authentication nor encryption (noAuthNoPriv).')]
			];
		}

		$auth = (int) ($details['authprotocol'] ?? self::AUTH_MD5);
		$auth_name = self::AUTH_NAMES[$auth] ?? (string) $auth;

		if ($security_level == 1) {
			return [
				'level' => self::LEVEL_AUTH_ONLY,
				'encrypted' => false,
				'summary' => $summary.' ('.$auth_name.')',
				'issues' => [I18n::t('Authenticated but not encrypted (authNoPriv): values travel in clear text.')]
			];
		}

		$priv = (int) ($details['privprotocol'] ?? self::PRIV_DES);
		$priv_name = self::PRIV_NAMES[$priv] ?? (string) $priv;
		$issues = [];

		if ($auth == self::AUTH_MD5) {
			$issues[] = I18n::t('Obsolete MD5 authentication: prefer SHA-256 or higher.');
		}
		elseif ($auth == self::AUTH_SHA1) {
			$issues[] = I18n::t('Ageing SHA-1 authentication: prefer SHA-256 or higher.');
		}

		if ($priv == self::PRIV_DES) {
			$issues[] = I18n::t('Obsolete DES encryption: prefer AES-128 or higher.');
		}

		return [
			'level' => $issues ? self::LEVEL_WEAK : self::LEVEL_ENCRYPTED,
			'encrypted' => true,
			'summary' => $summary.' ('.$auth_name.'/'.$priv_name.')',
			'issues' => $issues
		];
	}

	/**
	 * Whether a query is allowed for the given level and policy.
	 *
	 * @param string $policy  "warn" (default, always allowed) or "require_encryption".
	 */
	public static function isAllowed(string $level, string $policy): bool {
		if ($policy !== 'require_encryption') {
			return true;
		}

		return in_array($level, [self::LEVEL_ENCRYPTED, self::LEVEL_WEAK], true);
	}

	public static function normalizePolicy($policy): string {
		return $policy === 'require_encryption' ? 'require_encryption' : 'warn';
	}
}
