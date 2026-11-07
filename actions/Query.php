<?php declare(strict_types = 0);
/*
** MIB Browser module for Zabbix 8.0
** SNMP get/walk on a host through Zabbix server (or proxy).
*/

namespace Modules\MibBrowser\Actions;

use Modules\MibBrowser\Lib\ItemMapper,
	Modules\MibBrowser\Lib\SnmpResult,
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
			$this->respondError(_s('OID ou nom MIB inconnu : %1$s', $input));

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

			if (strpos($error, 'secret:') === 0) {
				$this->respond([
					'error' => _('Les identifiants SNMP de cette interface utilisent des macros secrètes ou non résolues. Saisissez-les dans « Identifiants » pour cette requête.'),
					'need_credentials' => explode(',', substr($error, 7))
				]);
			}
			else {
				$this->respondError($error !== '' ? $error : _('Unknown error.'));
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
		$records = array_slice($records, 0, $max);
		$rows = [];

		foreach ($records as $record) {
			$match = $repository->translate($record['oid']);
			$mib_node = $match['oid'] !== null ? $repository->getNode($match['oid']) : null;
			$formatted = SnmpResult::format($record, $mib_node);

			$rows[] = [
				'oid' => $record['oid'],
				'label' => $match['label'],
				'node_oid' => $match['oid'],
				'index' => $match['suffix'],
				'kind' => $mib_node['kind'] ?? '',
				'type' => $formatted['type'],
				'value' => mb_substr($formatted['value'], 0, 4096),
				'raw' => mb_substr($record['raw'], 0, 4096),
				'can_create_item' => $mib_node !== null && in_array($mib_node['kind'], ['scalar', 'column'], true)
					&& ItemMapper::isReadable($mib_node)
			];
		}

		$this->respond([
			'operation' => $operation,
			'oid' => $oid,
			'rows' => $rows,
			'total' => $total,
			'limited' => $total > count($rows),
			'truncated' => $snmp->isTruncated(),
			'duration' => $duration,
			'snmp_oid' => $operation.'['.$oid.']'
		]);
	}
}
