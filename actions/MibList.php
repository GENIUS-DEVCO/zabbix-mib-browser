<?php declare(strict_types = 0);
/*
** MIB Browser module for Zabbix 8.0
** List of loaded MIB modules.
*/

namespace Modules\MibBrowser\Actions;

use Modules\MibBrowser\Lib\Storage;

class MibList extends BaseJson {

	protected function checkInput(): bool {
		return $this->validate([]);
	}

	protected function doAction(): void {
		$modules = Storage::repository()->getModules();
		$loaded = array_flip(array_keys($modules));

		// Modules that only define SMI macros are not real dependencies to report.
		$ignore = ['RFC-1212' => true, 'RFC-1215' => true, 'SNMPv2-SMI' => true, 'SNMPv2-CONF' => true];
		$list = [];

		foreach ($modules as $module) {
			$missing = array_values(array_filter($module['missing_imports'],
				static fn($m) => !array_key_exists($m, $loaded) && !array_key_exists($m, $ignore)
			));

			$list[] = [
				'name' => $module['name'],
				'file' => $module['file'],
				'source' => $module['source'],
				'objects' => $module['objects'],
				'root_oid' => $module['root_oid'],
				'missing' => $missing,
				'unresolved' => count($module['unresolved']),
				'duplicates' => $module['duplicates']
			];
		}

		$this->respond([
			'modules' => $list,
			'storage_problems' => Storage::check()
		]);
	}
}
