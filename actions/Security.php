<?php declare(strict_types = 0);
/*
** MIB Browser module for Zabbix 8.0
** SNMP security overview: encryption level of every SNMP interface the user can see, and module policy.
*/

namespace Modules\MibBrowser\Actions;

use API,
	CWebUser,
	Modules\MibBrowser\Lib\SnmpSecurity,
	Modules\MibBrowser\Lib\ZabbixSnmp;

class Security extends BaseJson {

	private const LIMIT = 5000;

	protected function checkInput(): bool {
		return $this->validate([]);
	}

	protected function doAction(): void {
		$interfaces = API::HostInterface()->get([
			'output' => ['interfaceid', 'hostid', 'main', 'useip', 'ip', 'dns', 'port', 'details'],
			'filter' => ['type' => INTERFACE_TYPE_SNMP],
			'limit' => self::LIMIT
		]);

		$hosts = $interfaces
			? API::Host()->get([
				'output' => ['hostid', 'name', 'status'],
				'hostids' => array_values(array_unique(array_column($interfaces, 'hostid'))),
				'preservekeys' => true
			])
			: [];

		$counts = array_fill_keys(array_keys(SnmpSecurity::RANK), 0);
		$rows = [];

		foreach ($interfaces as $interface) {
			if (!array_key_exists($interface['hostid'], $hosts)) {
				continue;
			}

			$security = SnmpSecurity::assess($interface['details']);
			$counts[$security['level']]++;

			$rows[] = [
				'hostid' => $interface['hostid'],
				'host' => $hosts[$interface['hostid']]['name'],
				'enabled' => $hosts[$interface['hostid']]['status'] == HOST_STATUS_MONITORED,
				'address' => ($interface['useip'] == INTERFACE_USE_IP ? $interface['ip'] : $interface['dns'])
					.':'.$interface['port'],
				'level' => $security['level'],
				'encrypted' => $security['encrypted'],
				'summary' => $security['summary'],
				'issues' => $security['issues']
			];
		}

		// Least secure first: these are the ones to fix.
		usort($rows, static fn($a, $b) =>
			[-SnmpSecurity::RANK[$a['level']], $a['host']] <=> [-SnmpSecurity::RANK[$b['level']], $b['host']]
		);

		$this->respond([
			'policy' => ZabbixSnmp::policy(),
			'can_edit' => CWebUser::getType() == USER_TYPE_SUPER_ADMIN,
			'counts' => $counts,
			'total' => count($rows),
			'limited' => count($interfaces) >= self::LIMIT,
			'rows' => $rows
		]);
	}
}
