<?php declare(strict_types = 0);
/*
** MIB Browser module for Zabbix 8.0
** Create an SNMP item from a MIB object instance on a host or a template.
*/

namespace Modules\MibBrowser\Actions;

use API,
	CUrl,
	Modules\MibBrowser\Lib\ItemMapper,
	Modules\MibBrowser\Lib\Storage;

class ItemCreate extends BaseJson {

	protected function checkInput(): bool {
		return $this->validate([
			'hostid' => 'required|db hosts.hostid',
			'oid' => 'required|string|not_empty',
			'name' => 'required|string|not_empty',
			'key' => 'required|string|not_empty',
			'value_type' => 'required|in '.implode(',', [ITEM_VALUE_TYPE_FLOAT, ITEM_VALUE_TYPE_STR,
				ITEM_VALUE_TYPE_UINT64, ITEM_VALUE_TYPE_TEXT
			]),
			'delay' => 'required|string|not_empty',
			'units' => 'string',
			'change_per_second' => 'in 0,1',
			'create_valuemap' => 'in 0,1',
			'interfaceid' => 'db interface.interfaceid'
		]);
	}

	protected function doAction(): void {
		$repository = Storage::repository();
		$oid = ltrim(trim($this->getInput('oid')), '.');

		if (!preg_match('/^\d+(\.\d+)+$/', $oid)) {
			$this->respondError(_('OID numérique attendu.'));

			return;
		}

		$match = $repository->translate($oid);
		$node = $match['oid'] !== null ? $repository->getNode($match['oid']) : null;
		$hostid = $this->getInput('hostid');

		$hosts = API::Host()->get([
			'output' => ['hostid', 'name'],
			'hostids' => [$hostid],
			'editable' => true
		]);
		$is_template = false;

		if (!$hosts) {
			$hosts = API::Template()->get([
				'output' => ['templateid', 'name'],
				'templateids' => [$hostid],
				'editable' => true
			]);

			if (!$hosts) {
				$this->respondError(_('No permissions to referred object or it does not exist!'));

				return;
			}

			$is_template = true;
		}

		$item = [
			'hostid' => $hostid,
			'name' => $this->getInput('name'),
			'key_' => $this->getInput('key'),
			'type' => ITEM_TYPE_SNMP,
			'snmp_oid' => 'get['.$oid.']',
			'value_type' => (int) $this->getInput('value_type'),
			'delay' => $this->getInput('delay'),
			'units' => $this->getInput('units', ''),
			'description' => $node !== null ? ItemMapper::description($node) : 'OID: '.$oid,
			'tags' => [['tag' => 'mib', 'value' => $node['module'] ?? 'unknown']]
		];

		if (!$is_template) {
			$interfaceid = $this->getInput('interfaceid', 0);

			if ($interfaceid == 0) {
				$interfaces = API::HostInterface()->get([
					'output' => ['interfaceid'],
					'hostids' => [$hostid],
					'filter' => ['type' => INTERFACE_TYPE_SNMP, 'main' => INTERFACE_PRIMARY]
				]);

				if (!$interfaces) {
					$this->respondError(_('Cet hôte n\'a pas d\'interface SNMP.'));

					return;
				}

				$interfaceid = $interfaces[0]['interfaceid'];
			}

			$item['interfaceid'] = $interfaceid;
		}

		if ($node !== null) {
			$steps = ItemMapper::preprocessing($node, (bool) $this->getInput('change_per_second', 0));

			// The user may have chosen another value type: keep only compatible steps.
			if (in_array($item['value_type'], [ITEM_VALUE_TYPE_STR, ITEM_VALUE_TYPE_TEXT])) {
				$steps = array_filter($steps,
					static fn($s) => $s['type'] == ItemMapper::PP_DISCARD_UNCHANGED_HEARTBEAT
				);
			}

			$item['preprocessing'] = array_values(array_map(static fn($s) => [
				'type' => $s['type'],
				'params' => $s['params'],
				'error_handler' => ZBX_PREPROC_FAIL_DEFAULT,
				'error_handler_params' => ''
			], $steps));

			if ($this->getInput('create_valuemap', 0) && $item['value_type'] == ITEM_VALUE_TYPE_UINT64) {
				$valuemapid = $this->ensureValueMap($hostid, $node);

				if ($valuemapid !== null) {
					$item['valuemapid'] = $valuemapid;
				}
			}
		}

		$result = API::Item()->create($item);

		if (!$result) {
			$this->respondError(_('Cannot add item'));

			return;
		}

		$url = (new CUrl('zabbix.php'))
			->setArgument('action', 'item.list')
			->setArgument('context', $is_template ? 'template' : 'host')
			->setArgument('filter_set', '1')
			->setArgument('filter_hostids', [$hostid])
			->getUrl();

		$this->respond([
			'itemid' => $result['itemids'][0],
			'host' => $hosts[0]['name'],
			'url' => $url,
			'message' => _s('Élément « %1$s » créé sur %2$s.', $item['name'], $hosts[0]['name'])
		]);
	}

	private function ensureValueMap(string $hostid, array $node): ?string {
		$valuemap = ItemMapper::valueMap($node);

		if ($valuemap === null) {
			return null;
		}

		$name = mb_substr($valuemap['name'], 0, 64);

		$existing = API::ValueMap()->get([
			'output' => ['valuemapid'],
			'hostids' => [$hostid],
			'filter' => ['name' => $name]
		]);

		if ($existing) {
			return $existing[0]['valuemapid'];
		}

		$created = API::ValueMap()->create([
			'hostid' => $hostid,
			'name' => $name,
			'mappings' => array_map(static fn($m) => $m + ['type' => VALUEMAP_MAPPING_TYPE_EQUAL],
				$valuemap['mappings']
			)
		]);

		return $created ? $created['valuemapids'][0] : null;
	}
}
