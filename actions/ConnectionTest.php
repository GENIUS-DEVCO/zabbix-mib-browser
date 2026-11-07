<?php declare(strict_types = 0);
/*
** MIB Browser module for Zabbix 8.0
** "Test connection": reads the system group of the device through Zabbix server (or proxy) and explains failures.
*/

namespace Modules\MibBrowser\Actions;

use API,
	Modules\MibBrowser\Lib\ConnectionDiagnosis,
	Modules\MibBrowser\Lib\SnmpResult,
	Modules\MibBrowser\Lib\Storage,
	Modules\MibBrowser\Lib\ZabbixSnmp;

class ConnectionTest extends BaseJson {

	protected function checkInput(): bool {
		return $this->validate([
			'hostid' => 'required|db hosts.hostid',
			'interfaceid' => 'required|db interface.interfaceid',
			'overrides' => 'array'
		]);
	}

	protected function doAction(): void {
		$overrides = array_map('strval', array_intersect_key($this->getInput('overrides', []),
			array_flip(['community', 'securityname', 'authpassphrase', 'privpassphrase', 'contextname'])
		));

		$snmp = new ZabbixSnmp();
		$started = microtime(true);
		$result = $snmp->request($this->getInput('hostid'), $this->getInput('interfaceid'), ZabbixSnmp::OP_WALK,
			array_values(ConnectionDiagnosis::TEST_OIDS), $overrides
		);
		$duration = round(microtime(true) - $started, 2);

		$proxy = '';

		if ($snmp->getProxyId() !== '0') {
			$proxies = API::Proxy()->get(['output' => ['name'], 'proxyids' => [$snmp->getProxyId()]]);
			$proxy = $proxies ? $proxies[0]['name'] : '';
		}

		$base = [
			'duration' => $duration,
			'security' => $snmp->getSecurity(),
			'proxy' => $proxy
		];

		if ($result === null) {
			$error = $snmp->getError();

			$this->respond($base + [
				'ok' => false,
				'error' => preg_replace('/^(secret|policy):/', '', $error),
				'need_credentials' => strpos($error, 'secret:') === 0,
				'diagnosis' => ConnectionDiagnosis::diagnose($error, $snmp->getVersion(), $proxy !== '')
			]);

			return;
		}

		$values = [];

		foreach (SnmpResult::parseWalk($result) as $record) {
			$values[$record['oid']] = $record;
		}

		$info = [];
		$repository = Storage::repository();

		foreach (ConnectionDiagnosis::TEST_OIDS as $field => $oid) {
			if (!array_key_exists($oid, $values) || $values[$oid]['type'] === 'ERROR') {
				continue;
			}

			$record = $values[$oid];
			$value = $record['raw'];

			if ($field === 'uptime' && ctype_digit($value)) {
				$value = SnmpResult::formatTicks((int) $value);
			}
			elseif ($record['type'] === 'Hex-STRING') {
				$bin = SnmpResult::hexToBinary($value);
				$value = SnmpResult::isPrintable($bin) ? $bin : $value;
			}

			$info[$field] = mb_substr($value, 0, 1024);
		}

		if (array_key_exists('object_id', $info)) {
			$vendor = ConnectionDiagnosis::vendor($info['object_id']);
			$match = $repository->translate($info['object_id']);

			$info['object_label'] = $match['oid'] !== null && $match['suffix'] === '' ? $match['label'] : '';

			if ($vendor !== null) {
				$info['vendor'] = $vendor['vendor'] ?? _s('Entreprise n° %1$s', $vendor['enterprise']);
				$info['enterprise'] = $vendor['enterprise'];
			}
		}

		if (!$info) {
			// The device answered but returned nothing usable for the system group.
			$this->respond($base + [
				'ok' => false,
				'error' => trim($result),
				'diagnosis' => ConnectionDiagnosis::diagnose('No Such Object', $snmp->getVersion(), $proxy !== '')
			]);

			return;
		}

		$this->respond($base + [
			'ok' => true,
			'info' => $info,
			'truncated' => $snmp->isTruncated()
		]);
	}
}
