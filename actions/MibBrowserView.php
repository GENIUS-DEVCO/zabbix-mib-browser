<?php declare(strict_types = 0);
/*
** MIB Browser module for Zabbix 8.0
** Main page.
*/

namespace Modules\MibBrowser\Actions;

use Modules\MibBrowser\Lib\I18n;

use API,
	CController,
	CControllerResponseData,
	CCsrfTokenHelper,
	CWebUser,
	Modules\MibBrowser\Lib\Storage;

class MibBrowserView extends CController {

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		return $this->validateInput([
			'hostid' => 'db hosts.hostid',
			'oid' => 'string'
		]);
	}

	protected function checkPermissions(): bool {
		return $this->getUserType() >= USER_TYPE_ZABBIX_ADMIN;
	}

	protected function doAction(): void {
		$problems = Storage::check();

		$tokens = [];

		foreach (['mibbrowser.tree', 'mibbrowser.node', 'mibbrowser.interfaces', 'mibbrowser.query',
				'mibbrowser.mibs', 'mibbrowser.mib.upload', 'mibbrowser.mib.delete', 'mibbrowser.item.create',
				'mibbrowser.template.generate', 'mibbrowser.security', 'mibbrowser.settings', 'mibbrowser.test',
				'mibbrowser.results.export', 'mibbrowser.walk.import', 'mibbrowser.mib.export',
				'mibbrowser.tree.export'] as $action) {
			$tokens[$action] = CCsrfTokenHelper::get($action);
		}

		$ms_host = [];

		if ($this->hasInput('hostid')) {
			$hosts = API::Host()->get([
				'output' => ['hostid', 'name'],
				'hostids' => [$this->getInput('hostid')]
			]);

			if ($hosts) {
				$ms_host = [['id' => $hosts[0]['hostid'], 'name' => $hosts[0]['name']]];
			}
		}

		$data = [
			'tokens' => $tokens,
			'ms_host' => $ms_host,
			'oid' => $this->getInput('oid', ''),
			'storage_problems' => $problems,
			'is_super_admin' => CWebUser::getType() == USER_TYPE_SUPER_ADMIN,
			'max_upload_size' => (int) Storage::option('max_upload_size', 5242880),
			'max_walk_size' => (int) Storage::option('max_walk_size', 20971520),
			'max_archive_size' => (int) Storage::option('max_archive_size', 52428800),
			'is_https' => self::isHttps()
		];

		$response = new CControllerResponseData($data);
		$response->setTitle(I18n::t('MIB browser'));
		$this->setResponse($response);
	}

	/**
	 * Whether the page is served over HTTPS (directly or behind a TLS reverse proxy).
	 */
	private static function isHttps(): bool {
		return (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
			|| strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
	}
}
