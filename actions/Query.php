<?php declare(strict_types = 0);
/*
** MIB Browser module for Zabbix 8.0
** SNMP get/walk on a host through Zabbix server (or proxy).
*/

namespace Modules\MibBrowser\Actions;

use Modules\MibBrowser\Lib\I18n;

use Modules\MibBrowser\Lib\SnmpResult,
	Modules\MibBrowser\Lib\Storage,
	Modules\MibBrowser\Lib\ZabbixSnmp;

class Query extends BaseJson {

	protected function checkInput(): bool {
		return $this->validate([
			'hostid' => 'required|db hosts.hostid',
			'interfaceid' => 'required|db interface.interfaceid',
			'oid' => 'required|string|not_empty',
			'operation' => 'required|in '.ZabbixSnmp::OP_GET.','.ZabbixSnmp::OP_WALK,
			'overrides' => 'array'
		]);
	}

	protected function doAction(): void {
		$repository = Storage::repository();
		$input = trim($this->getInput('oid'));
		$operation = $this->getInput('operation');
		$oid = $repository->resolveSymbolic($input);

		if ($oid === null) {
			$this->respondError(I18n::s('Unknown OID or MIB name: %1$s', $input));

			return;
		}

		// "get" on a scalar object without instance: use ".0".
		$node = $repository->getNode($oid);

		if ($operation === ZabbixSnmp::OP_GET && $node !== null && $node['kind'] === 'scalar') {
			$oid .= '.0';
		}

		$overrides = array_map('strval', array_intersect_key($this->getInput('overrides', []),
			array_flip(['community', 'securityname', 'authpassphrase', 'privpassphrase', 'contextname'])
		));

		$snmp = new ZabbixSnmp();
		$started = microtime(true);
		$result = $snmp->request($this->getInput('hostid'), $this->getInput('interfaceid'), $operation, [$oid],
			$overrides
		);
		$duration = round(microtime(true) - $started, 2);

		if ($result === null) {
			$error = $snmp->getError();

			if (strpos($error, 'policy:') === 0) {
				$this->respond([
					'error' => I18n::s('Request denied: the module requires an encrypted SNMPv3 interface (authPriv), but this interface uses %1$s. Configure SNMPv3 authPriv on the host, or ask a Super admin to change the policy in the "SNMP security" tab.',
						substr($error, 7)
					),
					'security' => $snmp->getSecurity()
				]);
			}
			elseif (strpos($error, 'secret:') === 0) {
				$this->respond([
					'error' => I18n::t('The SNMP credentials of this interface use secret or unresolved macros. Enter them in "Credentials" for this request.'),
					'need_credentials' => explode(',', substr($error, 7))
				]);
			}
			else {
				$this->respondError($error !== '' ? $error : I18n::t('Unknown error.'));
			}

			return;
		}

		$records = $operation === ZabbixSnmp::OP_WALK
			? SnmpResult::parseWalk($result)
			: [['oid' => $oid] + SnmpResult::parseValue($result)];

		if ($operation === ZabbixSnmp::OP_GET && $records[0]['type'] === '' && $node === null) {
			// Untyped value: try node of the instance.
			$records[0]['type'] = '';
		}

		$max = (int) Storage::option('max_results', 5000);
		$total = count($records);
		$rows = SnmpResult::buildRows(array_slice($records, 0, $max), $repository);

		$this->respond([
			'operation' => $operation,
			'oid' => $oid,
			'rows' => $rows,
			'total' => $total,
			'limited' => $total > count($rows),
			'truncated' => $snmp->isTruncated(),
			'duration' => $duration,
			'security' => $snmp->getSecurity(),
			'snmp_oid' => $operation.'['.$oid.']'
		]);
	}
}
