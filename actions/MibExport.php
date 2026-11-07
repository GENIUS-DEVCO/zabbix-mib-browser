<?php declare(strict_types = 0);
/*
** MIB Browser module for Zabbix 8.0
** Download of MIB files: one module, the MIBs added by users, or all loaded MIBs (.zip).
*/

namespace Modules\MibBrowser\Actions;

use Modules\MibBrowser\Lib\I18n,
	Modules\MibBrowser\Lib\MibRepository,
	Modules\MibBrowser\Lib\Storage,
	Modules\MibBrowser\Lib\ZipWriter;

class MibExport extends BaseJson {

	protected function checkInput(): bool {
		return $this->validate([
			'scope' => 'required|in module,user,all',
			'module' => 'string'
		]);
	}

	protected function doAction(): void {
		$scope = $this->getInput('scope');
		$modules = Storage::repository()->getModules();
		$dirs = [
			MibRepository::SOURCE_STANDARD => Storage::standardMibDir(),
			MibRepository::SOURCE_USER => Storage::userMibDir()
		];

		// Files to export: [archive path => absolute path].
		$files = [];

		foreach ($modules as $module) {
			if ($scope === 'module' && $module['name'] !== $this->getInput('module', '')) {
				continue;
			}

			if ($scope === 'user' && $module['source'] !== MibRepository::SOURCE_USER) {
				continue;
			}

			$dir = $dirs[$module['source']] ?? null;
			$path = $dir !== null ? realpath($dir.'/'.$module['file']) : false;

			// Only files of the MIB directories.
			if ($path === false || strpos($path, realpath($dir).DIRECTORY_SEPARATOR) !== 0 || !is_file($path)) {
				continue;
			}

			$files[($scope === 'all' ? $module['source'].'/' : '').basename($path)] = $path;
		}

		if (!$files) {
			$this->respondError($scope === 'user' ? I18n::t('No MIB added by a user.') : I18n::t('Module not found.'));

			return;
		}

		if ($scope === 'module') {
			$path = reset($files);

			$this->respondFile([
				'filename' => basename($path),
				'mime' => 'text/plain',
				'content' => (string) file_get_contents($path)
			]);

			return;
		}

		$zip = new ZipWriter();

		foreach ($files as $name => $path) {
			$zip->add($name, (string) file_get_contents($path), (int) filemtime($path));
		}

		$this->respondFile([
			'filename' => ($scope === 'user' ? 'mibs-added-' : 'mibs-all-').date('Ymd').'.zip',
			'mime' => 'application/zip',
			'content' => $zip->build()
		], ['count' => count($files)]);
	}
}
