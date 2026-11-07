<?php declare(strict_types = 0);
/*
** MIB Browser module for Zabbix 8.0
** Details of a MIB node.
*/

namespace Modules\MibBrowser\Actions;

use Modules\MibBrowser\Lib\ItemMapper,
	Modules\MibBrowser\Lib\MibRepository,
	Modules\MibBrowser\Lib\Storage;

class Node extends BaseJson {

	protected function checkInput(): bool {
		return $this->validate(['oid' => 'required|string']);
	}

	protected function doAction(): void {
		$repository = Storage::repository();
		$oid = ltrim(trim($this->getInput('oid')), '.');
		$node = $repository->getNode($oid);

		if ($node === null) {
			$this->respond(['node' => $repository->treeItem($oid) + ['kind' => 'node']]);

			return;
		}

		// Index objects of a row (or of the row of a column/table).
		$row = null;

		if ($node['kind'] === 'row') {
			$row = $node;
		}
		elseif ($node['kind'] === 'column') {
			$row = $repository->getNode((string) MibRepository::parentOid($oid));
		}
		elseif ($node['kind'] === 'table') {
			$children = $repository->getChildren($oid);
			$row = $children ? $repository->getNode($children[0]['oid']) : null;
		}

		if ($row !== null && array_key_exists('augments', $row)) {
			$augmented = $repository->findByName($row['augments']);

			if ($augmented !== null) {
				$node['index_from'] = $augmented['name'];
				$row = $augmented;
			}
		}

		if ($row !== null && !empty($row['index'])) {
			$node['index'] = $row['index'];
		}

		$node['label'] = $node['module'].'::'.$node['name'];
		$node['readable'] = ItemMapper::isReadable($node);
		$node['can_create_item'] = in_array($node['kind'], ['scalar', 'column'], true) && $node['readable'];
		$node['can_template'] = in_array($node['kind'], ['node', 'module', 'table', 'row', 'scalar', 'column'], true);
		$node['suggested'] = [
			'name' => $node['name'],
			'value_type' => ItemMapper::valueType($node),
			'units' => ItemMapper::units($node),
			'change_per_second' => ItemMapper::isCounter($node)
		];

		$this->respond(['node' => $node]);
	}
}
