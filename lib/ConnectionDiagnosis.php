<?php declare(strict_types = 0);
/*
** MIB Browser module for Zabbix 8.0
** Connection test helpers: diagnosis of SNMP errors returned by Zabbix server and vendor names.
** Pure PHP so it can be unit tested. Error patterns come from Zabbix 8.0 server (net-snmp) messages.
*/

namespace Modules\MibBrowser\Lib;

class ConnectionDiagnosis {

	/** OIDs read by the connection test (SNMPv2-MIB system group). */
	public const TEST_OIDS = [
		'descr' => '1.3.6.1.2.1.1.1.0',
		'object_id' => '1.3.6.1.2.1.1.2.0',
		'uptime' => '1.3.6.1.2.1.1.3.0',
		'contact' => '1.3.6.1.2.1.1.4.0',
		'name' => '1.3.6.1.2.1.1.5.0',
		'location' => '1.3.6.1.2.1.1.6.0'
	];

	/** IANA private enterprise numbers of common network/system vendors. */
	private const VENDORS = [
		2 => 'IBM', 9 => 'Cisco', 11 => 'HP', 43 => '3Com', 171 => 'D-Link', 232 => 'HPE (Compaq)',
		311 => 'Microsoft', 318 => 'APC (Schneider Electric)', 534 => 'Eaton', 674 => 'Dell', 1588 => 'Brocade',
		1916 => 'Extreme Networks', 1991 => 'Brocade (Foundry)', 2011 => 'Huawei', 2620 => 'Check Point',
		2636 => 'Juniper Networks', 3375 => 'F5 Networks', 4413 => 'Broadcom', 4526 => 'Netgear', 5951 => 'Citrix',
		6027 => 'Dell (Force10)', 6486 => 'Alcatel-Lucent Enterprise', 6527 => 'Nokia (Alcatel-Lucent)',
		6574 => 'Synology', 6876 => 'VMware', 8072 => 'Net-SNMP', 8741 => 'SonicWall', 9148 => 'Acme Packet',
		11863 => 'TP-Link', 12356 => 'Fortinet', 14179 => 'Cisco (Airespace)', 14823 => 'Aruba (HPE)',
		14988 => 'MikroTik', 21067 => 'Stormshield', 24681 => 'QNAP', 25461 => 'Palo Alto Networks',
		25506 => 'H3C', 30065 => 'Arista Networks', 41112 => 'Ubiquiti', 47196 => 'HPE Aruba', 50536 => 'Stormshield'
	];

	/**
	 * Vendor from sysObjectID (1.3.6.1.4.1.<enterprise>...).
	 *
	 * @return array|null  ['enterprise' => int, 'vendor' => string|null]
	 */
	public static function vendor(string $object_id): ?array {
		if (!preg_match('/^\.?1\.3\.6\.1\.4\.1\.(\d+)/', $object_id, $m)) {
			return null;
		}

		$enterprise = (int) $m[1];

		return ['enterprise' => $enterprise, 'vendor' => self::VENDORS[$enterprise] ?? null];
	}

	/**
	 * Explain an error.
	 *
	 * @param string $error    Error message from Zabbix server or the module ("secret:...", "policy:...").
	 * @param int    $version  SNMP version of the interface (1, 2, 3).
	 * @param bool   $proxy    Whether the host is monitored by a proxy.
	 *
	 * @return array  ['category', 'title', 'causes' => [...], 'actions' => [...]]
	 */
	public static function diagnose(string $error, int $version, bool $proxy = false): array {
		$poller = $proxy ? I18n::t('the proxy') : I18n::t('Zabbix server');
		$lower = strtolower($error);

		if (strpos($error, 'policy:') === 0) {
			return self::result('policy', I18n::t('Request blocked by the module security policy'), [
				I18n::t('The module requires an encrypted SNMPv3 interface (authPriv) and this interface is not.')
			], [
				I18n::t('Configure SNMPv3 authPriv on the device and on the host interface.'),
				I18n::t('Or ask a Super admin to change the policy in the "SNMP security" tab.')
			]);
		}

		if (strpos($error, 'secret:') === 0) {
			return self::result('secret', I18n::t('Credentials in secret or unresolved macros'), [
				I18n::t('The module cannot read secret macros, nor macros without a value.')
			], [
				I18n::t('Enter the credentials in "Credentials" for this test (they are not saved).'),
				I18n::t('Check that the macros used by the interface have a value on the host, a linked template or globally.')
			]);
		}

		// Errors of the frontend ↔ server connection (CZabbixServer), never produced by SNMP itself.
		if (preg_match('/zabbix server/i', $error)) {
			return self::result('server', I18n::t('The web interface cannot reach Zabbix server'), [
				I18n::t('Zabbix server is stopped, or the web interface cannot reach it (address or port 10051).')
			], [
				I18n::t('Check the service: systemctl status zabbix-server.'),
				I18n::t('Check $ZBX_SERVER and $ZBX_SERVER_PORT in zabbix.conf.php.')
			]);
		}

		// The server checks permissions on its configuration cache: a host created or changed a few seconds ago
		// is not known yet.
		if (stripos($error, 'failed to validate host permissions') !== false) {
			return self::result('sync', I18n::t('Host not yet known by Zabbix server'), [
				I18n::t('The host or its interface was just created or changed: Zabbix server has not reloaded its configuration yet (CacheUpdateFrequency parameter, 10 seconds by default).'),
				I18n::t('More rarely: your account does not have the required permissions on this host.')
			], [
				I18n::t('Run the test again in a few seconds.'),
				I18n::t('To reload immediately: zabbix_server -R config_cache_reload (on Zabbix server).'),
				I18n::t('If the problem persists, check the permissions of your user group on the host group.')
			]);
		}

		if ($proxy && preg_match('/proxy/i', $error)) {
			return self::result('proxy', I18n::t('The host proxy does not respond'), [
				I18n::t('Zabbix server cannot reach the proxy that monitors this host, or the proxy is stopped.')
			], [
				I18n::t('Check the proxy state in Administration → Proxies (last seen).'),
				I18n::t('Check the zabbix-proxy service and the server ↔ proxy connectivity (port 10051).')
			]);
		}

		if (strpos($lower, 'cannot resolve address') !== false) {
			return self::result('dns', I18n::t('DNS name not found'), [
				I18n::s('The interface name cannot be resolved by %1$s.', $poller)
			], [
				I18n::t('Check the DNS name of the interface, or use its IP address.'),
				I18n::s('Test name resolution from %1$s: getent hosts <name>.', $poller)
			]);
		}

		if (strpos($lower, 'unknown user name') !== false) {
			return self::result('v3_user', I18n::t('SNMPv3 user unknown to the device'), [
				I18n::t('The security name of the interface does not exist on the device.')
			], [
				I18n::t('Check the security name of the interface (case sensitive).'),
				I18n::t('Check that the user is created on the device.')
			]);
		}

		if (strpos($lower, 'unsupported security level') !== false) {
			return self::result('v3_level', I18n::t('SNMPv3 security level not accepted'), [
				I18n::t('The SNMPv3 user exists but is not configured for this level (for example authPriv requested, authNoPriv configured on the device).')
			], [
				I18n::t('Align the security level of the interface with the user\'s, preferably authPriv on both sides.')
			]);
		}

		if (strpos($lower, 'authentication failure') !== false || strpos($lower, 'wrong digest') !== false) {
			return $version == 3
				? self::result('v3_auth', I18n::t('SNMPv3 authentication failed'), [
					I18n::t('The authentication passphrase is incorrect.'),
					I18n::t('Or the authentication protocol does not match (MD5, SHA1, SHA256…).')
				], [
					I18n::t('Check the authentication passphrase and protocol of the interface.'),
					I18n::t('If the passphrase comes from a macro, check its value on the host.')
				])
				: self::result('auth', I18n::t('Community rejected'), [I18n::t('The SNMP community is incorrect.')], [
					I18n::t('Check the community of the interface (macro {$SNMP_COMMUNITY} by default).')
				]);
		}

		if (strpos($lower, 'not in time window') !== false || strpos($lower, 'notintimewindow') !== false) {
			return self::result('v3_time', I18n::t('SNMPv3 time window exceeded'), [
				I18n::t('The device restarted or its boot counter changed; the cached SNMPv3 session is no longer valid.')
			], [
				I18n::t('Run the test again in a few seconds.'),
				I18n::t('If the problem persists, check that the engine ID of the device is unique on the network.')
			]);
		}

		if (strpos($lower, 'probe successful') !== false && strpos($lower, 'timed out') !== false) {
			return self::result('v3_priv', I18n::t('SNMPv3 encryption failed'), [
				I18n::t('Authentication succeeded but the device cannot decrypt the request.'),
				I18n::t('The privacy passphrase is incorrect, or the protocol does not match (DES, AES128, AES256…).')
			], [
				I18n::t('Check the privacy passphrase and protocol of the interface.'),
				I18n::t('Check that the device supports the chosen algorithm (AES192/AES256 are not available everywhere).')
			]);
		}

		if (preg_match('/no such (object|instance)/i', $error)) {
			return self::result('view', I18n::t('The device responds but does not give access to these objects'), [
				I18n::t('The SNMP view of the device does not include the system group (SNMPv2-MIB).')
			], [
				I18n::t('Widen the SNMP view of the device (for example .1.3.6.1.2.1).')
			]);
		}

		if (strpos($lower, 'timed out') !== false || strpos($lower, 'timeout') !== false) {
			$causes = [
				I18n::s('The device does not respond to %1$s on the configured UDP port.', $poller)
			];
			$actions = [];

			if ($version != 3) {
				array_unshift($causes,
					I18n::t('Wrong community: with SNMPv1/v2c, the device silently ignores requests with a wrong community.')
				);
				$actions[] = I18n::t('Check the community of the interface and the one configured on the device.');
			}

			$causes[] = I18n::t('The SNMP agent is stopped, or a device ACL does not allow the address of the server or proxy.');
			$causes[] = I18n::t('A firewall blocks UDP port 161 between Zabbix and the device.');
			$causes[] = I18n::t('Wrong IP address, port or SNMP version on the interface.');

			$actions[] = I18n::s('Test from %1$s: snmpget -v2c -c <community> <address> 1.3.6.1.2.1.1.5.0 (or the matching -v3 options).', $poller);
			$actions[] = I18n::t('Check the device SNMP ACLs and the firewall rules (UDP 161).');

			return self::result('timeout', I18n::t('No response from the device'), $causes, $actions);
		}

		return self::result('unknown', I18n::t('SNMP error'), [I18n::t('See the detailed error message.')], [
			I18n::s('Test from %1$s with snmpget to get more details.', $poller)
		]);
	}

	private static function result(string $category, string $title, array $causes, array $actions): array {
		return ['category' => $category, 'title' => $title, 'causes' => $causes, 'actions' => $actions];
	}
}
