<?php declare(strict_types = 0);
/*
** MIB Browser module for Zabbix 8.0
** Save module settings (Super admin only). Zabbix stores module options in its database when the module is
** registered, so they are changed here rather than in manifest.json.
*/

namespace Modules\MibBrowser\Actions;

use Modules\MibBrowser\Lib\I18n;

use APP,
	Modules\MibBrowser\Lib\SnmpSecurity;

class Settings extends BaseJson {

	protected int $min_user_type = USER_TYPE_SUPER_ADMIN;

	protected function checkInput(): bool {
		return $this->validate([
			'snmp_security' => 'required|in warn,require_encryption'
		]);
	}

	protected function doAction(): void {
		$module = APP::ModuleManager()->getModule('mibbrowser');

		if ($module === null) {
			$this->respondError(I18n::t('Module not found.'));

			return;
		}

		$config = $module->getConfig();
		$config['snmp_security'] = SnmpSecurity::normalizePolicy($this->getInput('snmp_security'));

		$module->setConfig($config);

		$errors = array_filter(get_and_clear_messages(), static fn($m) => $m['type'] === 'error');

		if ($errors) {
			$this->respondError(I18n::t('Cannot save the module settings.'));

			return;
		}

		$this->respond(['policy' => $config['snmp_security']]);
	}
}
