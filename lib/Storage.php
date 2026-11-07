<?php declare(strict_types = 0);
/*
** MIB Browser module for Zabbix 8.0
** Paths of bundled MIBs, user MIBs and cache.
*/

namespace Modules\MibBrowser\Lib;

class Storage {

	private static ?MibRepository $repository = null;

	public static function moduleDir(): string {
		return dirname(__DIR__);
	}

	/**
	 * Data directory: module option "data_dir" or "<module>/data". Must be writable by the web server user.
	 */
	public static function dataDir(): string {
		$dir = '';

		if (class_exists('APP', false)) {
			$module = \APP::ModuleManager()->getModule('mibbrowser');

			if ($module !== null) {
				$dir = (string) $module->getOption('data_dir', '');
			}
		}

		return rtrim($dir !== '' ? $dir : self::moduleDir().'/data', '/');
	}

	public static function standardMibDir(): string {
		return self::moduleDir().'/mibs';
	}

	public static function userMibDir(): string {
		return self::dataDir().'/mibs';
	}

	public static function cacheDir(): string {
		return self::dataDir().'/cache';
	}

	public static function option(string $name, $default = null) {
		if (class_exists('APP', false)) {
			$module = \APP::ModuleManager()->getModule('mibbrowser');

			if ($module !== null) {
				return $module->getOption($name, $default);
			}
		}

		return $default;
	}

	/**
	 * Create data directories when possible.
	 *
	 * @return array  List of problems (not writable directories).
	 */
	public static function check(): array {
		$problems = [];

		foreach ([self::userMibDir(), self::cacheDir()] as $dir) {
			if (!is_dir($dir)) {
				@mkdir($dir, 0775, true);
			}

			if (!is_dir($dir) || !is_writable($dir)) {
				$problems[] = $dir;
			}
		}

		return $problems;
	}

	public static function repository(): MibRepository {
		if (self::$repository === null) {
			self::$repository = new MibRepository([
				MibRepository::SOURCE_STANDARD => self::standardMibDir(),
				MibRepository::SOURCE_USER => self::userMibDir()
			], self::cacheDir());
		}

		return self::$repository;
	}

	public static function resetRepository(): void {
		self::$repository = null;
		@unlink(self::cacheDir().'/index.json');
	}
}
