<?php declare(strict_types = 0);
/*
** MIB Browser module for Zabbix 8.0
** Export of a MIB branch or module (object definitions): CSV, JSON or Excel.
*/

namespace Modules\MibBrowser\Lib;

class TreeExporter {

	public const FORMATS = ['csv', 'json', 'xlsx'];

	private const FIELDS = ['oid', 'module', 'name', 'kind', 'syntax', 'base_type', 'access', 'status', 'units', 'index',
		'enum', 'description'
	];

	/**
	 * Object definitions in a flat form.
	 *
	 * @param array $nodes  [oid => node] from MibRepository::getSubtree() or getModuleNodes().
	 */
	public static function rows(array $nodes): array {
		$rows = [];

		foreach ($nodes as $oid => $node) {
			if ($node === null) {
				continue;
			}

			$enum = [];

			foreach ($node['enum'] ?? [] as $value => $label) {
				$enum[] = $label.'('.$value.')';
			}

			$rows[] = [
				'oid' => (string) $oid,
				'module' => (string) ($node['module'] ?? ''),
				'name' => (string) ($node['name'] ?? ''),
				'kind' => (string) ($node['kind'] ?? ''),
				'syntax' => (string) ($node['syntax'] ?? ''),
				'base_type' => (string) ($node['base_type'] ?? ''),
				'access' => (string) ($node['access'] ?? ''),
				'status' => (string) ($node['status'] ?? ''),
				'units' => (string) ($node['units'] ?? ''),
				'index' => implode(', ', (array) ($node['index'] ?? [])),
				'enum' => implode(', ', $enum),
				'description' => trim((string) ($node['description'] ?? ''))
			];
		}

		return $rows;
	}

	/**
	 * @param string $format  csv, json or xlsx.
	 * @param array  $nodes   [oid => node].
	 * @param string $name    Branch or module name (file name and title).
	 *
	 * @return array  ['filename' => ..., 'mime' => ..., 'content' => ...]
	 */
	public static function export(string $format, array $nodes, string $name): array {
		$rows = self::rows($nodes);
		$base = 'mib-'.(trim(preg_replace('/[^A-Za-z0-9_.-]+/', '_', $name), '_') ?: 'tree');

		switch ($format) {
			case 'json':
				return [
					'filename' => $base.'.json',
					'mime' => 'application/json',
					'content' => json_encode([
						'generator' => 'Zabbix MIB Browser',
						'exported' => gmdate('Y-m-d\TH:i:s\Z'),
						'root' => $name,
						'objects' => $rows
					], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)."\n"
				];

			case 'xlsx':
				$sheet = [self::FIELDS];

				foreach ($rows as $row) {
					$sheet[] = array_values($row);
				}

				$writer = new XlsxWriter();
				$writer->addSheet('MIB', $sheet, true, [28, 22, 28, 10, 24, 14, 14, 10, 10, 24, 30, 80]);

				return [
					'filename' => $base.'.xlsx',
					'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
					'content' => $writer->build()
				];

			default:
				$escape = static fn($v) => '"'.str_replace('"', '""', $v).'"';
				$lines = [implode(';', self::FIELDS)];

				foreach ($rows as $row) {
					$lines[] = implode(';', array_map($escape, $row));
				}

				return [
					'filename' => $base.'.csv',
					'mime' => 'text/csv',
					'content' => "\xEF\xBB\xBF".implode("\r\n", $lines)."\r\n"
				];
		}
	}
}
