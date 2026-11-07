<?php declare(strict_types = 0);
/*
** MIB Browser module for Zabbix 8.0
** Maps MIB objects to Zabbix item properties (value type, units, preprocessing, key, name).
** Pure PHP (no Zabbix dependency) so it can be unit tested and reused by the template builder.
*/

namespace Modules\MibBrowser\Lib;

class ItemMapper {

	// Zabbix item value types.
	public const VALUE_FLOAT = 0;
	public const VALUE_STR = 1;
	public const VALUE_LOG = 2;
	public const VALUE_UINT64 = 3;
	public const VALUE_TEXT = 4;

	public const VALUE_TYPE_NAMES = [
		self::VALUE_FLOAT => 'FLOAT', self::VALUE_STR => 'CHAR', self::VALUE_LOG => 'LOG',
		self::VALUE_UINT64 => 'UNSIGNED', self::VALUE_TEXT => 'TEXT'
	];

	// Preprocessing types (subset).
	public const PP_MULTIPLIER = 1;
	public const PP_CHANGE_PER_SECOND = 10;
	public const PP_DISCARD_UNCHANGED_HEARTBEAT = 20;
	public const PP_SNMP_WALK_VALUE = 28;
	public const PP_SNMP_WALK_TO_JSON = 29;

	public const PP_NAMES = [
		self::PP_MULTIPLIER => 'MULTIPLIER', self::PP_CHANGE_PER_SECOND => 'CHANGE_PER_SECOND',
		self::PP_DISCARD_UNCHANGED_HEARTBEAT => 'DISCARD_UNCHANGED_HEARTBEAT',
		self::PP_SNMP_WALK_VALUE => 'SNMP_WALK_VALUE', self::PP_SNMP_WALK_TO_JSON => 'SNMP_WALK_TO_JSON'
	];

	/** SNMP walk value formats (parameter of SNMP_WALK_VALUE / SNMP_WALK_TO_JSON). */
	public const FORMAT_UNCHANGED = '0';
	public const FORMAT_UTF8_FROM_HEX = '1';
	public const FORMAT_MAC_FROM_HEX = '2';
	public const FORMAT_INT_FROM_BITS = '3';

	public static function isCounter(array $node): bool {
		return in_array($node['base_type'] ?? '', ['Counter32', 'Counter64', 'Counter'], true);
	}

	public static function isNumeric(array $node): bool {
		return in_array($node['base_type'] ?? '', ['INTEGER', 'Integer32', 'Unsigned32', 'Gauge32', 'Gauge',
			'Counter32', 'Counter', 'Counter64', 'TimeTicks'
		], true);
	}

	/**
	 * Whether the object can be read (and so polled).
	 */
	public static function isReadable(array $node): bool {
		return in_array($node['access'] ?? '', ['read-only', 'read-write', 'read-create'], true);
	}

	/**
	 * Whether the object should be polled by a generated template (readable and not obsolete).
	 */
	public static function isPollable(array $node): bool {
		return self::isReadable($node) && ($node['status'] ?? 'current') !== 'obsolete';
	}

	/**
	 * Default value type for a MIB object.
	 */
	public static function valueType(array $node): int {
		$base = $node['base_type'] ?? '';

		if (array_key_exists('enum', $node) && in_array($base, ['INTEGER', 'Integer32', 'Unsigned32'], true)) {
			return self::VALUE_UINT64;
		}

		switch ($base) {
			case 'Counter32':
			case 'Counter':
			case 'Counter64':
				// Counters are converted to rates by "Change per second", which gives a float.
				return self::VALUE_FLOAT;

			case 'Unsigned32':
			case 'Gauge32':
			case 'Gauge':
				return self::VALUE_UINT64;

			case 'TimeTicks':
				// Converted to seconds with a multiplier.
				return self::VALUE_FLOAT;

			case 'INTEGER':
			case 'Integer32':
				// May be negative.
				return self::VALUE_FLOAT;

			case 'Opaque':
				return self::VALUE_TEXT;

			default:
				return self::VALUE_STR;
		}
	}

	/**
	 * Zabbix units from MIB UNITS clause and type.
	 */
	public static function units(array $node): string {
		if (($node['base_type'] ?? '') === 'TimeTicks') {
			$name = strtolower($node['name'] ?? '');

			return (strpos($name, 'uptime') !== false || strpos($name, 'lastchange') !== false) ? 'uptime' : 's';
		}

		$units = trim((string) ($node['units'] ?? ''));
		$name = strtolower($node['name'] ?? '');
		$lower = strtolower($units);

		$map = [
			'seconds' => 's', 'second' => 's', 'secs' => 's', 'milliseconds' => 'ms', 'ms' => 'ms',
			'octets' => 'B', 'bytes' => 'B', 'bits per second' => 'bps', 'bits/second' => 'bps', 'bps' => 'bps',
			'kbytes' => 'KB', 'kilobytes' => 'KB', 'kb' => 'KB', 'megabytes' => 'MB', 'mb' => 'MB',
			'percent' => '%', 'percentage' => '%', '%' => '%', 'degrees celsius' => '°C', 'celsius' => '°C',
			'watts' => 'W', 'volts' => 'V', 'millivolts' => 'mV', 'amperes' => 'A', 'rpm' => 'rpm', 'hz' => 'Hz'
		];

		if (array_key_exists($lower, $map)) {
			$units = $map[$lower];
		}
		elseif ($units === '' && self::isCounter($node) && preg_match('/octets/i', $name)) {
			// ifInOctets, ifHCOutOctets...: rate of bytes; Zabbix templates use bps with multiplier 8.
			$units = 'Bps';
		}
		elseif (strlen($units) > 20) {
			$units = '';
		}

		if (self::isCounter($node) && in_array($units, ['B', 'packets', 'pkts', ''], true)) {
			$units = $units === 'B' ? 'Bps' : ($units === '' ? '' : $units.'/s');
		}

		return $units;
	}

	/**
	 * Preprocessing steps for a get[] item (without walk extraction).
	 *
	 * @param array $node
	 * @param bool  $change_per_second  Convert counters to rates.
	 *
	 * @return array  Zabbix API format: [['type' => int, 'params' => string, 'error_handler' => 0, ...]]
	 */
	public static function preprocessing(array $node, bool $change_per_second = true): array {
		$steps = [];

		if (($node['base_type'] ?? '') === 'TimeTicks') {
			$steps[] = ['type' => self::PP_MULTIPLIER, 'params' => '0.01'];
		}
		elseif ($change_per_second && self::isCounter($node)) {
			$steps[] = ['type' => self::PP_CHANGE_PER_SECOND, 'params' => ''];
		}
		elseif (self::valueType($node) == self::VALUE_STR) {
			$steps[] = ['type' => self::PP_DISCARD_UNCHANGED_HEARTBEAT, 'params' => '1h'];
		}

		return $steps;
	}

	/**
	 * Walk value format for SNMP_WALK_VALUE / SNMP_WALK_TO_JSON.
	 */
	public static function walkFormat(array $node): string {
		$syntax = $node['syntax'] ?? '';
		$hint = $node['display_hint'] ?? '';

		if (in_array($syntax, ['PhysAddress', 'MacAddress'], true) || $hint === '1x:') {
			return self::FORMAT_MAC_FROM_HEX;
		}

		if (($node['base_type'] ?? '') === 'BITS') {
			return self::FORMAT_INT_FROM_BITS;
		}

		if (($node['base_type'] ?? '') === 'OCTET STRING' && preg_match('/a$/', $hint)) {
			// DisplayString-like: devices sometimes return hex encoded UTF-8.
			return self::FORMAT_UTF8_FROM_HEX;
		}

		return self::FORMAT_UNCHANGED;
	}

	/**
	 * Item key: "<prefix>.<name>" for scalars, "<prefix>.<name>[<index>]" for instances.
	 */
	public static function key(string $prefix, array $node, string $index = ''): string {
		$name = preg_replace('/[^A-Za-z0-9_.-]/', '_', $node['name']);
		$key = ($prefix !== '' ? rtrim($prefix, '.').'.' : '').$name;

		if ($index !== '' && $index !== '0') {
			$key .= '['.$index.']';
		}

		return $key;
	}

	/**
	 * Item description with MIB reference.
	 */
	public static function description(array $node): string {
		$text = 'MIB: '.$node['module'].'::'.$node['name'];

		if (!empty($node['description'])) {
			$text .= "\n".$node['description'];
		}

		return mb_substr($text, 0, 65535);
	}

	/**
	 * Value map from enumeration.
	 *
	 * @return array|null  ['name' => ..., 'mappings' => [['value' => '1', 'newvalue' => 'up'], ...]]
	 */
	public static function valueMap(array $node): ?array {
		if (empty($node['enum']) || self::valueType($node) != self::VALUE_UINT64) {
			return null;
		}

		$mappings = [];

		foreach ($node['enum'] as $value => $label) {
			$mappings[] = ['value' => (string) $value, 'newvalue' => (string) $label];
		}

		return [
			'name' => $node['module'].'::'.($node['syntax'] !== '' && $node['syntax'] !== 'INTEGER'
				? $node['syntax']
				: $node['name']),
			'mappings' => $mappings
		];
	}
}
