<?php declare(strict_types = 0);
/*
** MIB Browser module for Zabbix 8.0
** Export of the object definitions of a MIB branch or module: CSV, JSON or Excel.
*/

namespace Modules\MibBrowser\Actions;

use Modules\MibBrowser\Lib\I18n,
	Modules\MibBrowser\Lib\Storage,
	Modules\MibBrowser\Lib\TreeExporter;

class TreeExport extends BaseJson {

	protected function checkInput(): bool {
		return $this->validate([
			'root' => 'required|string|not_empty',
			'format' => 'required|in '.implode(',', TreeExporter::FORMATS)
		]);
	}

	protected function doAction(): void {
		$repository = Storage::repository();
		$root = $this->getInput('root');

		if (preg_match('/^module:([A-Za-z0-9_-]+)$/', $root, $m)) {
			$nodes = $repository->getModuleNodes($m[1]);
			$name = $m[1];
		}
		elseif (preg_match('/^\d+(\.\d+)*$/', $root)) {
			$nodes = $repository->getSubtree($root);
			$node = $repository->getNode($root);
			$name = $node !== null ? $node['module'].'-'.$node['name'] : $root;
		}
		else {
			$nodes = [];
			$name = '';
		}

		if (!$nodes) {
			$this->respondError(I18n::t('Unknown MIB object.'));

			return;
		}

		$this->respondFile(TreeExporter::export($this->getInput('format'), $nodes, $name), ['count' => count($nodes)]);
	}
}
