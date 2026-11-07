<?php declare(strict_types = 0);
/*
** MIB Browser module for Zabbix 8.0
** Export of Get/Walk results: CSV, JSON, XML, Excel (.xlsx) and snmpwalk text.
**
** CSV, JSON and snmpwalk exports can be imported back by the module (WalkImporter).
*/

namespace Modules\MibBrowser\Lib;

class ResultExporter {

	public const FORMATS = ['csv', 'json', 'xml', 'xlsx', 'snmpwalk'];

	/** Net-SNMP type labels for numeric SMI types (values returned by Zabbix have no type label). */
	private const SNMPWALK_TYPES = [
		'INTEGER' => 'INTEGER', 'Integer32' => 'INTEGER', 'Unsigned32' => 'Gauge32', 'Gauge32' => 'Gauge32',
		'Counter32' => 'Counter32', 'Counter64' => 'Counter64', 'TimeTicks' => 'Timeticks', 'IpAddress' => 'IpAddress'
	];

	/**
	 * @param string $format  One of FORMATS.
	 * @param array  $rows    Result rows: oid, label, type, value, raw, snmp_type.
	 * @param array  $meta    host, request (for example "walk[1.3.6.1.2.1.1]"), source.
	 * @param array  $labels  Translated column titles for the spreadsheet: name, type, value, raw, host, request,
	 *                        date.
	 *
	 * @return array  ['filename' => ..., 'mime' => ..., 'content' => ...]
	 */
	public static function export(string $format, array $rows, array $meta = [], array $labels = []): array {
		$rows = array_map([self::class, 'normalizeRow'], array_values($rows));
		$base = 'snmp-'.self::slug($meta['host'] ?? '').date('Ymd-His');

		switch ($format) {
			case 'json':
				return ['filename' => $base.'.json', 'mime' => 'application/json', 'content' => self::toJson($rows, $meta)];

			case 'xml':
				return ['filename' => $base.'.xml', 'mime' => 'application/xml', 'content' => self::toXml($rows, $meta)];

			case 'xlsx':
				return [
					'filename' => $base.'.xlsx',
					'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
					'content' => self::toXlsx($rows, $meta, $labels)
				];

			case 'snmpwalk':
				return ['filename' => $base.'.txt', 'mime' => 'text/plain', 'content' => self::toSnmpwalk($rows)];

			default:
				return ['filename' => $base.'.csv', 'mime' => 'text/csv', 'content' => self::toCsv($rows)];
		}
	}

	public static function normalizeRow($row): array {
		$row = is_array($row) ? $row : [];
		$result = [];

		foreach (['oid', 'label', 'type', 'value', 'raw', 'snmp_type'] as $field) {
			$result[$field] = is_scalar($row[$field] ?? null) ? (string) $row[$field] : '';
		}

		$result['oid'] = ltrim($result['oid'], '.');

		return $result;
	}

	public static function toCsv(array $rows): string {
		$escape = static fn($v) => '"'.str_replace('"', '""', $v).'"';
		$lines = [implode(';', ['OID', 'Name', 'Type', 'Value', 'Raw', 'SNMP type'])];

		foreach ($rows as $row) {
			$lines[] = implode(';', array_map($escape,
				[$row['oid'], $row['label'], $row['type'], $row['value'], $row['raw'], $row['snmp_type']]
			));
		}

		// BOM: Excel opens the UTF-8 file correctly.
		return "\xEF\xBB\xBF".implode("\r\n", $lines)."\r\n";
	}

	public static function toJson(array $rows, array $meta): string {
		$values = [];

		foreach ($rows as $row) {
			$values[] = [
				'oid' => $row['oid'],
				'name' => $row['label'],
				'type' => $row['type'],
				'value' => $row['value'],
				'raw' => $row['raw'],
				'snmp_type' => $row['snmp_type']
			];
		}

		return json_encode([
			'generator' => 'Zabbix MIB Browser',
			'exported' => gmdate('Y-m-d\TH:i:s\Z'),
			'host' => (string) ($meta['host'] ?? ''),
			'request' => (string) ($meta['request'] ?? ''),
			'values' => $values
		], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)."\n";
	}

	public static function toXml(array $rows, array $meta): string {
		$e = [XlsxWriter::class, 'esc'];
		$xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
			.'<snmp_results generator="Zabbix MIB Browser" exported="'.gmdate('Y-m-d\TH:i:s\Z').'"'
			.' host="'.$e((string) ($meta['host'] ?? '')).'" request="'.$e((string) ($meta['request'] ?? '')).'">'."\n";

		foreach ($rows as $row) {
			$xml .= '  <value oid="'.$e($row['oid']).'">'
				.'<name>'.$e($row['label']).'</name>'
				.'<type>'.$e($row['type']).'</type>'
				.'<snmp_type>'.$e($row['snmp_type']).'</snmp_type>'
				.'<display>'.$e($row['value']).'</display>'
				.'<raw>'.$e($row['raw']).'</raw>'
				."</value>\n";
		}

		return $xml.'</snmp_results>'."\n";
	}

	/**
	 * Same format as "snmpwalk -On" (and as Zabbix walk[] values).
	 */
	public static function toSnmpwalk(array $rows): string {
		$lines = [];

		foreach ($rows as $row) {
			$lines[] = '.'.$row['oid'].' = '.self::snmpwalkValue($row);
		}

		return implode("\n", $lines)."\n";
	}

	public static function snmpwalkValue(array $row): string {
		$raw = $row['raw'];
		$type = $row['snmp_type'];

		if ($type === 'ERROR') {
			return $raw;
		}

		if ($type === '') {
			if (array_key_exists($row['type'], self::SNMPWALK_TYPES) && preg_match('/^-?\d+$|^\d+(\.\d+){3}$/', $raw)) {
				return self::SNMPWALK_TYPES[$row['type']].': '.$raw;
			}

			if (preg_match('/^-?\d+$/', $raw)) {
				return $raw;
			}

			$type = 'STRING';
		}

		switch ($type) {
			case 'STRING':
				return 'STRING: "'.addcslashes($raw, "\"\\").'"';

			case 'OID':
				return 'OID: .'.ltrim($raw, '.');

			default:
				return $type.': '.$raw;
		}
	}

	public static function toXlsx(array $rows, array $meta, array $labels): string {
		$label = static fn(string $key, string $default) => (string) ($labels[$key] ?? $default);

		$sheet = [['OID', $label('name', 'Name'), $label('type', 'Type'), $label('value', 'Value'), $label('raw', 'Raw value')]];

		foreach ($rows as $row) {
			$raw = $row['raw'];

			// Numbers stay numbers in Excel (counters, gauges...), within the precision of a spreadsheet.
			if (preg_match('/^-?\d{1,15}$/', $raw) && $row['snmp_type'] !== 'STRING') {
				$raw = (int) $raw;
			}

			$sheet[] = [$row['oid'], $row['label'], $row['type'], $row['value'], $raw];
		}

		$writer = new XlsxWriter();
		$writer->addSheet('SNMP', $sheet, true, [30, 36, 14, 50, 30]);
		$writer->addSheet('Info', [
			[$label('host', 'Host'), (string) ($meta['host'] ?? '')],
			[$label('request', 'Request'), (string) ($meta['request'] ?? '')],
			[$label('date', 'Date'), date('Y-m-d H:i:s')],
			['Generator', 'Zabbix MIB Browser']
		], false, [20, 60]);

		return $writer->build();
	}

	private static function slug(string $value): string {
		$value = trim(preg_replace('/[^A-Za-z0-9_.-]+/', '_', $value), '_');

		return $value !== '' ? mb_substr($value, 0, 40).'-' : '';
	}
}
