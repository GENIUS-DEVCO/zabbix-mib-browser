<?php declare(strict_types = 0);
/*
** MIB Browser module for Zabbix 8.0
** Translations of the module texts.
**
** Source texts are in English. Translations are in lang/<locale>.php (for example lang/fr.php, lang/pt_BR.php) as
** arrays [English text => translated text]. The language is the one of the Zabbix user profile; a missing
** language or text falls back to English. Texts that Zabbix already translates (Host, Name, Save, ...) keep using
** the Zabbix _() function.
*/

namespace Modules\MibBrowser\Lib;

class I18n {

	private static ?string $locale = null;
	private static ?array $catalog = null;

	/**
	 * Force the locale (tests, CLI). Null: use the Zabbix user language.
	 */
	public static function setLocale(?string $locale): void {
		self::$locale = $locale;
		self::$catalog = null;
	}

	/**
	 * Current locale, as stored by Zabbix for the user (for example "fr_FR"; "default" is already resolved to the
	 * system language at login).
	 */
	public static function getLocale(): string {
		if (self::$locale === null) {
			$lang = '';

			if (class_exists('CWebUser', false) && is_array(\CWebUser::$data) && !empty(\CWebUser::$data['lang'])) {
				$lang = (string) \CWebUser::$data['lang'];
			}

			self::$locale = $lang !== '' && $lang !== 'default' ? $lang : 'en_US';
		}

		return self::$locale;
	}

	/**
	 * Translation catalog for the current locale: exact file (pt_BR.php), else language file (fr.php).
	 */
	public static function getCatalog(): array {
		if (self::$catalog !== null) {
			return self::$catalog;
		}

		self::$catalog = [];
		$locale = preg_replace('/[^A-Za-z_]/', '', self::getLocale());
		$dir = dirname(__DIR__).'/lang';

		foreach ([$locale, strtok($locale, '_')] as $name) {
			$file = $dir.'/'.$name.'.php';

			if ($name !== '' && is_file($file)) {
				$catalog = include $file;

				if (is_array($catalog)) {
					self::$catalog = $catalog;
				}

				break;
			}
		}

		return self::$catalog;
	}

	/**
	 * Translate a text.
	 */
	public static function t(string $text): string {
		$translation = self::getCatalog()[$text] ?? '';

		return $translation !== '' ? $translation : $text;
	}

	/**
	 * Translate a format with positional arguments (%1$s, %2$s...).
	 */
	public static function s(string $format, ...$args): string {
		// A broken translation (wrong placeholders) must never break the page: fall back to English.
		try {
			return vsprintf(self::t($format), $args);
		}
		catch (\ValueError | \ArgumentCountError $e) {
			return vsprintf($format, $args);
		}
	}
}
