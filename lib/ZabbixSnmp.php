<?php declare(strict_types = 0);
/*
** MIB Browser module for Zabbix 8.0
** Executes SNMP requests through Zabbix server (or the host's proxy) using the "item.test" request,
** the same mechanism as the "Test" button of the item form. Credentials come from the host SNMP interface.
*/

namespace Modules\MibBrowser\Lib;

use API,
	CMacrosResolverHelper,
	CSessionHelper,
	CSettingsHelper,
	CZabbixServer;

class ZabbixSnmp {

	public const OP_GET = 'get';
	public const OP_WALK = 'walk';

	/** Detail fields that may contain user macros. */
	private const MACRO_FIELDS = ['community', 'securityname', 'authpassphrase', 'privpassphrase', 'contextname'];

	private string $error = '';
	private bool $truncated = false;
	private ?array $security = null;

	public function getError(): string {
		return $this->error;
	}

	public function isTruncated(): bool {
		return $this->truncated;
	}

	/**
	 * Security assessment of the interface used by the last request.
	 */
	public function getSecurity(): ?array {
		return $this->security;
	}

	/**
	 * Module policy: "warn" (default) or "require_encryption" (only SNMPv3 authPriv interfaces can be queried).
	 */
	public static function policy(): string {
		return SnmpSecurity::normalizePolicy(Storage::option('snmp_security', 'warn'));
	}

	/**
	 * SNMP interfaces of a host with readable description.
	 */
	public static function getInterfaces(string $hostid): array {
		$interfaces = API::HostInterface()->get([
			'output' => ['interfaceid', 'hostid', 'main', 'type', 'useip', 'ip', 'dns', 'port', 'details'],
			'hostids' => [$hostid],
			'filter' => ['type' => INTERFACE_TYPE_SNMP]
		]);

		$result = [];

		foreach ($interfaces as $interface) {
			$security = SnmpSecurity::assess($interface['details']);
			$result[] = [
				'interfaceid' => $interface['interfaceid'],
				'main' => (int) $interface['main'],
				'label' => ($interface['useip'] == INTERFACE_USE_IP ? $interface['ip'] : $interface['dns'])
					.':'.$interface['port'].' — '.$security['summary'],
				'version' => (int) $interface['details']['version'],
				'security' => $security
			];
		}

		// Most secure interface first (SNMPv3 authPriv), then the main interface.
		usort($result, static fn($a, $b) =>
			[SnmpSecurity::RANK[$a['security']['level']], -$a['main']]
				<=> [SnmpSecurity::RANK[$b['security']['level']], -$b['main']]
		);

		return $result;
	}

	/**
	 * Run an SNMP request.
	 *
	 * @param string $hostid
	 * @param string $interfaceid
	 * @param string $operation   get|walk
	 * @param array  $oids        Numeric OIDs.
	 * @param array  $overrides   Optional credentials typed by the user: community, securityname, authpassphrase,
	 *                            privpassphrase, contextname. Used when the interface uses secret macros.
	 *
	 * @return string|null  Raw value returned by Zabbix, null on error.
	 */
	public function request(string $hostid, string $interfaceid, string $operation, array $oids,
			array $overrides = []): ?string {
		global $ZBX_SERVER, $ZBX_SERVER_PORT;

		$this->error = '';
		$this->truncated = false;
		$this->security = null;

		$hosts = API::Host()->get([
			'output' => ['hostid', 'host', 'status', 'monitored_by', 'proxyid', 'assigned_proxyid', 'proxy_groupid'],
			'hostids' => [$hostid]
		]);

		if (!$hosts) {
			$this->error = _('No permissions to referred object or it does not exist!');

			return null;
		}

		$host = $hosts[0];

		$interfaces = API::HostInterface()->get([
			'output' => ['interfaceid', 'hostid', 'main', 'type', 'useip', 'ip', 'dns', 'port', 'details'],
			'hostids' => [$hostid],
			'interfaceids' => [$interfaceid],
			'filter' => ['type' => INTERFACE_TYPE_SNMP]
		]);

		if (!$interfaces) {
			$this->error = _('Cet hôte n\'a pas d\'interface SNMP.');

			return null;
		}

		$this->security = SnmpSecurity::assess($interfaces[0]['details']);

		if (!SnmpSecurity::isAllowed($this->security['level'], self::policy())) {
			$this->error = 'policy:'.$this->security['summary'];

			return null;
		}

		$interface = $this->resolveInterface($interfaces[0], $overrides);

		if ($interface === null) {
			return null;
		}

		$proxyid = '0';

		if ($host['monitored_by'] == ZBX_MONITORED_BY_PROXY) {
			$proxyid = $host['proxyid'];
		}
		elseif ($host['monitored_by'] == ZBX_MONITORED_BY_PROXY_GROUP) {
			$proxyid = $host['assigned_proxyid'];
		}

		// Zabbix server rejects item tests without a timeout ("Unsupported timeout value").
		$timeouts = getInheritedTimeouts((string) $proxyid);
		$timeout = (string) ($timeouts['timeouts'][ITEM_TYPE_SNMP] ?? '');

		if ($timeout === '' || timeUnitToSeconds($timeout) === null) {
			$timeout = '10s';
		}

		$item = [
			'value_type' => ITEM_VALUE_TYPE_TEXT,
			'type' => ITEM_TYPE_SNMP,
			'flags' => ZBX_FLAG_DISCOVERY_NORMAL,
			'snmp_oid' => $operation.'['.implode(',', $oids).']',
			'timeout' => $timeout
		];

		$data = [
			'item' => $item,
			'host' => [
				'hostid' => $host['hostid'],
				'host' => $host['host'],
				'interface' => $interface,
				'proxyid' => $proxyid
			]
		];

		$server = new CZabbixServer($ZBX_SERVER, $ZBX_SERVER_PORT,
			timeUnitToSeconds(CSettingsHelper::get(CSettingsHelper::CONNECT_TIMEOUT)),
			timeUnitToSeconds(CSettingsHelper::get(CSettingsHelper::ITEM_TEST_TIMEOUT)), ZBX_SOCKET_BYTES_LIMIT
		);

		$result = $server->testItem($data, CSessionHelper::getId());

		if ($result === false) {
			$this->error = $server->getError();

			return null;
		}

		if (array_key_exists('error', $result)) {
			$this->error = $result['error'];

			return null;
		}

		if (array_key_exists('error', $result['item'])) {
			$this->error = $result['item']['error'];

			return null;
		}

		$this->truncated = !empty($result['item']['truncated']);

		return (string) $result['item']['result'];
	}

	/**
	 * Build interface data for the server, with user macros resolved.
	 */
	private function resolveInterface(array $interface, array $overrides): ?array {
		$details = $interface['details'];
		$columns = [
			'ip' => $interface['ip'],
			'dns' => $interface['dns'],
			'port' => $interface['port']
		];

		foreach (self::MACRO_FIELDS as $field) {
			if (array_key_exists($field, $details)) {
				$columns[$field] = $details[$field];
			}
		}

		$resolved = CMacrosResolverHelper::resolveHostMacros($columns, [$interface['hostid']]);

		foreach ($resolved as $column => $values) {
			$columns[$column] = $values[$interface['hostid']];
		}

		foreach ($overrides as $field => $value) {
			if (in_array($field, self::MACRO_FIELDS, true) && $value !== '') {
				$columns[$field] = $value;
			}
		}

		$version = (int) $details['version'];
		$relevant = $version == SNMP_V3
			? ['securityname', 'contextname']
			: ['community'];

		if ($version == SNMP_V3) {
			if ($details['securitylevel'] != ITEM_SNMPV3_SECURITYLEVEL_NOAUTHNOPRIV) {
				$relevant[] = 'authpassphrase';
			}

			if ($details['securitylevel'] == ITEM_SNMPV3_SECURITYLEVEL_AUTHPRIV) {
				$relevant[] = 'privpassphrase';
			}
		}

		$unresolved = [];

		foreach (array_merge(['ip', 'dns', 'port'], $relevant) as $field) {
			$value = (string) ($columns[$field] ?? '');

			if (strpos($value, ZBX_SECRET_MASK) !== false || preg_match('/\{\$[^}]+\}/', $value)
					|| $value === UNRESOLVED_MACRO_STRING) {
				$unresolved[] = $field;
			}
		}

		// The address not in use may stay unresolved.
		$address_field = $interface['useip'] == INTERFACE_USE_IP ? 'ip' : 'dns';
		$unresolved = array_values(array_diff($unresolved, [$address_field === 'ip' ? 'dns' : 'ip']));

		if ($unresolved) {
			$this->error = 'secret:'.implode(',', $unresolved);

			return null;
		}

		$out_details = [
			'version' => $version,
			'max_repetitions' => $details['max_repetitions'] ?? '10',
			'retries' => $details['retries'] ?? '1'
		];

		if (array_key_exists('bulk', $details)) {
			$out_details['bulk'] = $details['bulk'];
		}

		if ($version == SNMP_V3) {
			$out_details['securityname'] = $columns['securityname'] ?? '';
			$out_details['securitylevel'] = $details['securitylevel'];
			$out_details['contextname'] = $columns['contextname'] ?? '';

			if ($details['securitylevel'] != ITEM_SNMPV3_SECURITYLEVEL_NOAUTHNOPRIV) {
				$out_details['authprotocol'] = $details['authprotocol'];
				$out_details['authpassphrase'] = $columns['authpassphrase'] ?? '';
			}

			if ($details['securitylevel'] == ITEM_SNMPV3_SECURITYLEVEL_AUTHPRIV) {
				$out_details['privprotocol'] = $details['privprotocol'];
				$out_details['privpassphrase'] = $columns['privpassphrase'] ?? '';
			}
		}
		else {
			$out_details['community'] = $columns['community'] ?? '';
		}

		return [
			'interfaceid' => $interface['interfaceid'],
			'address' => $interface['useip'] == INTERFACE_USE_IP ? $columns['ip'] : $columns['dns'],
			'port' => $columns['port'],
			'useip' => $interface['useip'],
			'details' => $out_details
		];
	}
}
