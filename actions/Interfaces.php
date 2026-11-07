<?php declare(strict_types = 0);
/*
** MIB Browser module for Zabbix 8.0
** SNMP interfaces of a host.
*/

namespace Modules\MibBrowser\Actions;

use API,
	Modules\MibBrowser\Lib\ZabbixSnmp;

class Interfaces extends BaseJson {

	protected function checkInput(): bool {
		return $this->validate(['hostid' => 'required|db hosts.hostid']);
	}

	protected function doAction(): void {
		$hosts = API::Host()->get([
			'output' => ['hostid', 'name', 'monitored_by', 'proxyid', 'assigned_proxyid'],
			'hostids' => [$this->getInput('hostid')]
		]);

		if (!$hosts) {
			$this->respondError(_('No permissions to referred object or it does not exist!'));

			return;
		}

		$host = $hosts[0];
		$proxy = '';

		$proxyid = $host['monitored_by'] == ZBX_MONITORED_BY_PROXY
			? $host['proxyid']
			: ($host['monitored_by'] == ZBX_MONITORED_BY_PROXY_GROUP ? $host['assigned_proxyid'] : 0);

		if ($proxyid != 0) {
			$proxies = API::Proxy()->get(['output' => ['name'], 'proxyids' => [$proxyid]]);
			$proxy = $proxies ? $proxies[0]['name'] : '';
		}

		$this->respond([
			'host' => ['hostid' => $host['hostid'], 'name' => $host['name'], 'proxy' => $proxy],
			'interfaces' => ZabbixSnmp::getInterfaces($host['hostid']),
			'policy' => ZabbixSnmp::policy()
		]);
	}
}
