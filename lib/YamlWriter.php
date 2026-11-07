<?php declare(strict_types = 0);
/*
** MIB Browser module for Zabbix 8.0
** Minimal YAML emitter for Zabbix export arrays (maps, lists, strings).
** Output follows the style of Zabbix exports: 2-space indentation, "- " for list items, block literals for
** multi-line strings.
*/

namespace Modules\MibBrowser\Lib;

class YamlWriter {

	public static function write(array $data): string {
		return self::emitMap($data, 0);
	}

	private static function emitMap(array $map, int $indent): string {
		$out = '';
		$pad = str_repeat(' ', $indent);

		foreach ($map as $key => $value) {
			$out .= $pad.$key.':'.self::emitValue($value, $indent);
		}

		return $out;
	}

	private static function emitValue($value, int $indent): string {
		if (is_array($value)) {
			if ($value === []) {
				return " []\n";
			}

			if (array_is_list($value)) {
				return "\n".self::emitList($value, $indent + 2);
			}

			return "\n".self::emitMap($value, $indent + 2);
		}

		$value = (string) $value;

		if (strpos($value, "\n") !== false) {
			return self::emitBlock($value, $indent + 2);
		}

		return ' '.self::scalar($value)."\n";
	}

	private static function emitList(array $list, int $indent): string {
		$out = '';
		$pad = str_repeat(' ', $indent);

		foreach ($list as $item) {
			if (is_array($item) && $item !== [] && !array_is_list($item)) {
				// "- key: value" then remaining keys aligned under the first one.
				$lines = self::emitMap($item, $indent + 2);
				$out .= $pad.'- '.substr($lines, $indent + 2);
			}
			elseif (is_array($item)) {
				$out .= $pad.'-'.self::emitValue($item, $indent);
			}
			else {
				$item = (string) $item;
				$out .= $pad.'-'.(strpos($item, "\n") !== false
					? self::emitBlock($item, $indent + 2)
					: ' '.self::scalar($item)."\n");
			}
		}

		return $out;
	}

	private static function emitBlock(string $value, int $indent): string {
		$pad = str_repeat(' ', $indent);
		$value = str_replace("\r\n", "\n", $value);
		$keep = substr($value, -1) === "\n" ? '' : '-';
		$lines = explode("\n", rtrim($value, "\n"));
		$out = ' |'.$keep."\n";

		foreach ($lines as $line) {
			$out .= ($line === '' ? '' : $pad.$line)."\n";
		}

		return $out;
	}

	/**
	 * Quote a scalar when needed. Plain style only for simple safe strings.
	 */
	public static function scalar(string $value): string {
		if ($value === '') {
			return "''";
		}

		$plain = preg_match('/^[A-Za-z_][A-Za-z0-9_ .\/-]*$/', $value)
			&& substr($value, -1) !== ' '
			&& !in_array(strtolower($value), ['yes', 'no', 'true', 'false', 'on', 'off', 'null', 'y', 'n', '~'], true);

		if ($plain) {
			return $value;
		}

		return "'".str_replace("'", "''", $value)."'";
	}
}
