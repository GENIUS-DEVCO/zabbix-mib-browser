<?php declare(strict_types = 0);
/*
** MIB Browser module for Zabbix 8.0
** Parsing of Zabbix "walk[]" output and value formatting with MIB information.
**
** Zabbix returns walk[] values in the format of "snmpwalk -Oe -Ot -On":
**   .1.3.6.1.2.1.1.5.0 = STRING: "host"
**   .1.3.6.1.2.1.1.3.0 = 45208                (numeric types have no type label)
**   .1.3.6.1.2.1.2.2.1.6.2 = Hex-STRING: 0A 4C 74 A5 60 12
**   .1.3.6.1.2.1.1.2.0 = OID: .1.3.6.1.4.1.8072.3.2.10
**   .1.3.6.1.2.1.2.2.1.6.1 = ""
*/

namespace Modules\MibBrowser\Lib;

class SnmpResult {

	/**
	 * Split walk output into records.
	 *
	 * @return array  [['oid' => '1.3.6...', 'type' => 'STRING', 'raw' => 'value without quotes'], ...]
	 */
	public static function parseWalk(string $output): array {
		$output = str_replace("\r\n", "\n", $output);
		$records = [];

		// Record boundaries: a line starting with ".<digits>... = ".
		if (!preg_match_all('/^\.?(\d+(?:\.\d+)+) = /m', $output, $matches, PREG_OFFSET_CAPTURE)) {
			return [];
		}

		$count = count($matches[0]);

		for ($i = 0; $i < $count; $i++) {
			$start = $matches[0][$i][1] + strlen($matches[0][$i][0]);
			$end = ($i + 1 < $count) ? $matches[0][$i + 1][1] : strlen($output);
			$value = rtrim(substr($output, $start, $end - $start), "\n");

			$records[] = ['oid' => $matches[1][$i][0]] + self::parseValue($value);
		}

		return $records;
	}

	/**
	 * Result rows for the browser: MIB translation and formatted values.
	 *
	 * @param array         $records     Records from parseWalk() / parseValue() with "oid".
	 * @param MibRepository $repository
	 */
	public static function buildRows(array $records, MibRepository $repository): array {
		$rows = [];

		foreach ($records as $record) {
			$match = $repository->translate($record['oid']);
			$mib_node = $match['oid'] !== null ? $repository->getNode($match['oid']) : null;
			$formatted = self::format($record, $mib_node);

			$rows[] = [
				'oid' => $record['oid'],
				'label' => $match['label'],
				'node_oid' => $match['oid'],
				'index' => $match['suffix'],
				'kind' => $mib_node['kind'] ?? '',
				'type' => $formatted['type'],
				'snmp_type' => $record['type'],
				'value' => mb_substr($formatted['value'], 0, 4096),
				'raw' => mb_substr($record['raw'], 0, 4096),
				'can_create_item' => $mib_node !== null && in_array($mib_node['kind'], ['scalar', 'column'], true)
					&& ItemMapper::isReadable($mib_node)
			];
		}

		return $rows;
	}

	/**
	 * Parse "TYPE: value" or bare value.
	 */
	public static function parseValue(string $value): array {
		$type = '';

		if (preg_match('/^([A-Za-z][A-Za-z0-9-]*): ?(.*)$/s', $value, $m)) {
			$type = $m[1];
			$value = $m[2];
		}

		if (strlen($value) >= 2 && $value[0] === '"' && substr($value, -1) === '"') {
			$value = stripcslashes(substr($value, 1, -1));
			$type = $type === '' ? 'STRING' : $type;
		}
		elseif ($type === 'Hex-STRING') {
			$value = trim($value);
		}
		elseif ($type === 'OID') {
			$value = ltrim(trim($value), '.');
		}
		elseif ($type === '' && preg_match('/^No (Such Object|Such Instance|more variables)/', $value)) {
			$type = 'ERROR';
		}

		return ['type' => $type, 'raw' => $value];
	}

	/**
	 * Convert "0A 4C 74" to binary string.
	 */
	public static function hexToBinary(string $hex): string {
		$hex = preg_replace('/[^0-9A-Fa-f]/', '', $hex);

		return (strlen($hex) % 2 == 0) ? (string) hex2bin($hex) : '';
	}

	/**
	 * Human readable value using MIB definition (enum labels, MAC, dates, time ticks, ...).
	 *
	 * @param array      $record  Record from parseWalk().
	 * @param array|null $node    MIB node.
	 *
	 * @return array  ['value' => display string, 'type' => SMI type for display]
	 */
	public static function format(array $record, ?array $node): array {
		$raw = $record['raw'];
		$type = $record['type'];
		$base = $node['base_type'] ?? '';
		$syntax = $node['syntax'] ?? '';
		$hint = $node['display_hint'] ?? '';

		if ($type === '' && $base !== '') {
			$type = $base;
		}

		// Enumerations.
		if (array_key_exists('enum', $node ?? []) && preg_match('/^-?\d+$/', $raw)
				&& in_array($base, ['INTEGER', 'Integer32', 'Unsigned32', ''], true)) {
			$label = $node['enum'][$raw] ?? null;

			return [
				'value' => $label !== null ? $label.' ('.$raw.')' : $raw,
				'type' => $type !== '' ? $type : 'INTEGER'
			];
		}

		// BITS: Hex-STRING with named bits.
		if ($base === 'BITS' && $type === 'Hex-STRING' && array_key_exists('enum', $node ?? [])) {
			$bin = self::hexToBinary($raw);
			$set = [];

			for ($i = 0; $i < strlen($bin) * 8; $i++) {
				if (ord($bin[intdiv($i, 8)]) & (0x80 >> ($i % 8))) {
					$set[] = ($node['enum'][(string) $i] ?? $i).'('.$i.')';
				}
			}

			return ['value' => $set ? implode(' ', $set) : '-', 'type' => 'BITS'];
		}

		if (in_array($base, ['TimeTicks'], true) && preg_match('/^\d+$/', $raw)) {
			return ['value' => self::formatTicks((int) $raw).' ('.$raw.')', 'type' => 'TimeTicks'];
		}

		if ($type === 'Hex-STRING') {
			$bin = self::hexToBinary($raw);

			if ($syntax === 'DateAndTime' && (strlen($bin) == 8 || strlen($bin) == 11)) {
				return ['value' => self::formatDateAndTime($bin), 'type' => 'DateAndTime'];
			}

			if (in_array($syntax, ['PhysAddress', 'MacAddress'], true) || preg_match('/^1x:$/', $hint)) {
				return ['value' => implode(':', str_split(strtoupper(bin2hex($bin)), 2)), 'type' => $syntax];
			}

			if ($bin !== '' && self::isPrintable($bin)) {
				return ['value' => $bin, 'type' => 'OCTET STRING'];
			}

			return ['value' => $raw, 'type' => 'Hex-STRING'];
		}

		if ($type === 'IpAddress' || $syntax === 'IpAddress') {
			return ['value' => $raw, 'type' => 'IpAddress'];
		}

		return ['value' => $raw, 'type' => $type !== '' ? $type : 'unknown'];
	}

	public static function formatTicks(int $ticks): string {
		$seconds = intdiv($ticks, 100);
		$days = intdiv($seconds, 86400);
		$seconds %= 86400;

		// "%1$sd" is the translatable short form of a number of days (for example "3d", "3j" in French).
		return I18n::s('%1$sd', $days).' '.sprintf('%02d:%02d:%02d.%02d', intdiv($seconds, 3600),
			intdiv($seconds % 3600, 60), $seconds % 60, $ticks % 100
		);
	}

	public static function formatDateAndTime(string $bin): string {
		$b = array_values(unpack('C*', $bin));
		$year = ($b[0] << 8) + $b[1];
		$out = sprintf('%04d-%02d-%02d %02d:%02d:%02d', $year, $b[2], $b[3], $b[4], $b[5], $b[6]);

		if (count($b) == 11) {
			$out .= sprintf(' %s%02d:%02d', chr($b[8]), $b[9], $b[10]);
		}

		return $out;
	}

	public static function isPrintable(string $bin): bool {
		$check = rtrim($bin, "\0");

		return $check !== '' && mb_check_encoding($check, 'UTF-8') && !preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $check);
	}
}
