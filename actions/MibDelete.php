<?php declare(strict_types = 0);
/*
** MIB Browser module for Zabbix 8.0
** Delete a user MIB file (Super admin only). Bundled standard MIBs cannot be deleted.
*/

namespace Modules\MibBrowser\Actions;

use Modules\MibBrowser\Lib\I18n;

use Modules\MibBrowser\Lib\MibRepository,
	Modules\MibBrowser\Lib\Storage;

class MibDelete extends BaseJson {

	protected int $min_user_type = USER_TYPE_SUPER_ADMIN;

	protected function checkInput(): bool {
		return $this->validate(['module' => 'required|string|not_empty']);
	}

	protected function doAction(): void {
		$modules = Storage::repository()->getModules();
		$name = $this->getInput('module');

		if (!array_key_exists($name, $modules) || $modules[$name]['source'] !== MibRepository::SOURCE_USER) {
			$this->respondError(I18n::t('Only MIBs added by a user can be deleted.'));

			return;
		}

		$path = Storage::userMibDir().'/'.basename($modules[$name]['file']);

		if (!is_file($path) || !@unlink($path)) {
			$this->respondError(I18n::t('Cannot delete the file.'));

			return;
		}

		Storage::resetRepository();

		$this->respond(['deleted' => $name]);
	}
}
