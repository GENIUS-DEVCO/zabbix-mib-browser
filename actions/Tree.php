<?php declare(strict_types = 0);
/*
** MIB Browser module for Zabbix 8.0
** Tree data: children of a node, search, or path to an OID.
*/

namespace Modules\MibBrowser\Actions;

use Modules\MibBrowser\Lib\Storage;

class Tree extends BaseJson {

	protected function checkInput(): bool {
		return $this->validate([
			'parent' => 'string',
			'search' => 'string',
			'path' => 'string'
		]);
	}

	protected function doAction(): void {
		$repository = Storage::repository();

		if ($this->hasInput('search')) {
			$this->respond(['results' => $repository->search($this->getInput('search'), 200)]);

			return;
		}

		if ($this->hasInput('path')) {
			$oid = $repository->resolveSymbolic($this->getInput('path'));

			if ($oid === null) {
				$this->respondError(_('Objet MIB inconnu.'));

				return;
			}

			// Return children of each ancestor so the client can expand the tree down to the node.
			$match = $repository->translate($oid);
			$target = $match['oid'] ?? $oid;
			$levels = [];

			foreach ($repository->getPath($target) as $ancestor) {
				$levels[$ancestor] = $repository->getChildren($ancestor);
			}

			$this->respond(['target' => $target, 'levels' => $levels, 'roots' => $repository->getRoots()]);

			return;
		}

		$parent = $this->getInput('parent', '');

		$this->respond([
			'parent' => $parent,
			'children' => $parent === '' ? $repository->getRoots() : $repository->getChildren($parent)
		]);
	}
}
