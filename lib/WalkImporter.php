<?php declare(strict_types = 0);
/*
** MIB Browser module for Zabbix 8.0
** Import of SNMP walk files, to browse a device offline and build a template without access to it.
**
** Accepted formats (detected from the content):
** - snmpwalk output (Net-SNMP), numeric or symbolic:
**     .1.3.6.1.2.1.1.1.0 = STRING: "Linux"            (snmpwalk -On, Zabbix walk[] value)
**     iso.3.6.1.2.1.1.3.0 = Timeticks: (1234) 0:00:12.34
**     SNMPv2-MIB::sysDescr.0 = STRING: Linux
**     IF-MIB::ifOperStatus.2 = INTEGER: up(1)
**     IF-MIB::ifName[2] = STRING: eth0                 (snmpwalk -OX)
** - exports of the module: JSON, CSV and XML.
*/

namespace Modules\MibBrowser\Lib;

class WalkImporter {

	/** Start of a record in snmpwalk output: "<OID> = ". */
	private const RECORD_START = '/^(\.?\d+(?:\.\d+)+|iso(?:\.\d+)+|[A-Za-z][A-Za-z0-9_-]*(?:::[A-Za-z][A-Za-z0-9_-]*)?'
		.'(?:\.(?:\d+|[A-Za-z][A-Za-z0-9_-]*|"(?:[^"\\\\]|\\\\.)*"|\'[^\']*\')|\[(?:[^\]"]|"(?:[^"\\\\]|\\\\.)*")*\])*) = /m';

	/** Numeric SNMP types whose value may be followed by units or a label. */
	private const NUMERIC_TYPES = ['INTEGER', 'Integer32', 'Gauge32', 'Unsigned32', 'UInteger32', 'Counter32',
		'Counter64', 'Counter', 'Gauge', 'Timeticks', 'TimeTicks'
	];

	private MibRepository $repository;
	private array $skipped = [];
	private int $skipped_count = 0;
	private array $meta = [];

	public function __construct(MibRepository $repository) {
		$this->repository = $repository;
	}

	/**
	 * Lines that could not be imported (first ones).
	 */
	public function getSkipped(): array {
		return $this->skipped;
	}

	public function getSkippedCount(): int {
		return $this->skipped_count;
	}

	/**
	 * Host and request of the export, when the file contains them (JSON and XML exports of the module).
	 */
	public function getMeta(): array {
		return $this->meta;
	}

	/**
	 * Detected format of a content: snmpwalk, json, csv or xml.
	 */
	public static function detect(string $content): string {
		$start = ltrim(substr($content, 0, 4096));

		if ($start !== '' && ($start[0] === '{' || $start[0] === '[')) {
			return 'json';
		}

		if (strncmp($start, '<?xml', 5) == 0 || strncmp($start, '<snmp_results', 13) == 0) {
			return 'xml';
		}

		$first = strtok($start, "\n");

		if ($first !== false && preg_match('/^"?OID"?\s*[;,\t]/i', $first)) {
			return 'csv';
		}

		return 'snmpwalk';
	}

	/**
	 * Parse a file content.
	 *
	 * @return array  Records ['oid' => numeric OID, 'type' => Zabbix walk type label, 'raw' => value].
	 */
	public function import(string $content): array {
		$this->skipped = [];
		$this->skipped_count = 0;
		$this->meta = [];

		// UTF-8 BOM and Windows line ends.
		if (strncmp($content, "\xEF\xBB\xBF", 3) == 0) {
			$content = substr($content, 3);
		}

		$content = str_replace(["\r\n", "\r"], "\n", $content);

		switch (self::detect($content)) {
			case 'json':
				return $this->importJson($content);

			case 'xml':
				return $this->importXml($content);

			case 'csv':
				return $this->importCsv($content);

			default:
				return $this->importSnmpwalk($content);
		}
	}

	private function skip(string $line): void {
		$this->skipped_count++;

		if (count($this->skipped) < 10) {
			$this->skipped[] = mb_substr($line, 0, 200);
		}
	}

	/* ------------------------------------------------------------------ */
	/* snmpwalk text                                                        */
	/* ------------------------------------------------------------------ */

	private function importSnmpwalk(string $content): array {
		$records = [];

		if (!preg_match_all(self::RECORD_START, $content, $matches, PREG_OFFSET_CAPTURE)) {
			foreach (array_filter(explode("\n", $content), static fn($l) => trim($l) !== '') as $line) {
				$this->skip($line);
			}

			return [];
		}

		// Text before the first record (headers, prompts...).
		$head = trim(substr($content, 0, $matches[0][0][1]));

		if ($head !== '') {
			foreach (explode("\n", $head) as $line) {
				$this->skip($line);
			}
		}

		$count = count($matches[0]);

		for ($i = 0; $i < $count; $i++) {
			$spec = $matches[1][$i][0];
			$start = $matches[0][$i][1] + strlen($matches[0][$i][0]);
			$end = ($i + 1 < $count) ? $matches[0][$i + 1][1] : strlen($content);
			$value = rtrim(substr($content, $start, $end - $start), "\n");
			$oid = $this->resolveOid($spec);

			if ($oid === null) {
				$this->skip($spec.' = '.strtok($value, "\n"));
				continue;
			}

			$records[] = ['oid' => $oid] + $this->normalizeValue($value);
		}

		return $records;
	}

	/**
	 * Numeric OID of "1.3.6...", "iso.3.6...", "IF-MIB::ifDescr.3", "ifName[2]", "ifName.\"eth0\"".
	 */
	public function resolveOid(string $spec): ?string {
		if (preg_match('/^\.?(\d+(?:\.\d+)+)$/', $spec, $m)) {
			return $m[1];
		}

		if (preg_match('/^iso((?:\.\d+)+)$/', $spec, $m)) {
			return '1'.$m[1];
		}

		if (!preg_match('/^((?:[A-Za-z][A-Za-z0-9_-]*::)?[A-Za-z][A-Za-z0-9_-]*)(.*)$/s', $spec, $m)) {
			return null;
		}

		$base = $this->repository->resolveSymbolic($m[1]);

		// Module of the file not loaded, but object known from another module.
		if ($base === null && strpos($m[1], '::') !== false) {
			$base = $this->repository->resolveSymbolic(explode('::', $m[1], 2)[1]);
		}

		if ($base === null) {
			return null;
		}

		$suffix = $this->encodeIndex($m[2], $base);

		return $suffix === null ? null : $base.$suffix;
	}

	/**
	 * Numeric form of an instance suffix: ".3", ".1.10.0.0.1", "[2]", ".\"eth0\"", "[\"public\"][1]".
	 */
	private function encodeIndex(string $suffix, string $base): ?string {
		if ($suffix === '') {
			return '';
		}

		if (!preg_match_all('/\.(\d+)|\.("(?:[^"\\\\]|\\\\.)*"|\'[^\']*\'|[A-Za-z][A-Za-z0-9_-]*)|\[((?:[^\]"]|"(?:[^"\\\\]|\\\\.)*")*)\]/',
				$suffix, $parts, PREG_SET_ORDER)) {
			return null;
		}

		if (implode('', array_column($parts, 0)) !== $suffix) {
			return null;
		}

		$implied = $this->lastIndexImplied($base);
		$enums = null;
		$arcs = [];
		$last = count($parts) - 1;

		foreach ($parts as $i => $part) {
			$token = $part[1] !== '' ? $part[1] : (($part[2] ?? '') !== '' ? $part[2] : trim($part[3] ?? ''));

			if (preg_match('/^\d+(\.\d+)*$/', $token)) {
				$arcs[] = $token;
			}
			elseif (preg_match('/^"((?:[^"\\\\]|\\\\.)*)"$|^\'([^\']*)\'$/', $token, $m)) {
				$string = isset($m[2]) ? $m[2] : stripcslashes($m[1]);
				$bytes = array_map('ord', str_split($string));

				if (!($implied && $i == $last) || $string === '') {
					array_unshift($bytes, strlen($string));
				}

				if ($bytes) {
					$arcs[] = implode('.', $bytes);
				}
			}
			elseif (preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $token)) {
				// Enumeration label of an index object, for example "authPriv" in vacmAccessTable.
				$enums ??= $this->indexEnums($base);
				$value = $enums[$token] ?? null;

				if ($value === null) {
					return null;
				}

				$arcs[] = $value;
			}
			else {
				return null;
			}
		}

		return '.'.implode('.', $arcs);
	}

	/**
	 * Enumeration labels of the INDEX objects of the row of a column: [label => value].
	 */
	private function indexEnums(string $oid): array {
		$row = $this->repository->getNode((string) MibRepository::parentOid($oid));
		$labels = [];

		foreach ($row['index'] ?? [] as $name) {
			$node = $this->repository->findByName(preg_replace('/^IMPLIED /', '', (string) $name), $row['module']);
			$node ??= $this->repository->findByName(preg_replace('/^IMPLIED /', '', (string) $name));

			foreach ($node['enum'] ?? [] as $value => $label) {
				$labels += [$label => (string) $value];
			}
		}

		return $labels;
	}

	/**
	 * Whether the last INDEX object of the row of a column is IMPLIED.
	 */
	private function lastIndexImplied(string $oid): bool {
		$node = $this->repository->getNode($oid);

		if ($node === null || $node['kind'] !== 'column') {
			return false;
		}

		$row = $this->repository->getNode((string) MibRepository::parentOid($oid));
		$index = $row['index'] ?? [];

		return $index && strpos((string) end($index), 'IMPLIED ') === 0;
	}

	/**
	 * Convert a Net-SNMP value to the form returned by Zabbix walk[] (SnmpResult::parseValue()).
	 */
	public function normalizeValue(string $value): array {
		$record = SnmpResult::parseValue($value);
		$type = $record['type'];
		$raw = $record['raw'];

		if ($type === 'Timeticks' || $type === 'TimeTicks') {
			// "(12345) 0:02:03.45" or "12345".
			if (preg_match('/^\((\d+)\)/', $raw, $m) || preg_match('/^(\d+)/', $raw, $m)) {
				$raw = $m[1];
			}

			$type = 'Timeticks';
		}
		elseif (in_array($type, self::NUMERIC_TYPES, true)) {
			// "up(1)", "4096 Bytes", "1000000000 bits per second".
			if (preg_match('/^[A-Za-z_][\w-]*\((-?\d+)\)$/', $raw, $m) || preg_match('/^(-?\d+)/', $raw, $m)) {
				$raw = $m[1];
			}
		}
		elseif ($type === 'Hex-STRING' || $type === 'BITS') {
			// Multi-line dumps; BITS: "40 00 lowerLayerDown(1)".
			preg_match_all('/\b[0-9A-Fa-f]{2}\b(?![\w(])/', $type === 'BITS' ? preg_replace('/\w+\(\d+\)/', '', $raw) : $raw,
				$bytes
			);
			$raw = strtoupper(implode(' ', $bytes[0]));
			$type = 'Hex-STRING';
		}
		elseif ($type === 'OID' && !preg_match('/^\d+(\.\d+)*$/', $raw)) {
			$oid = $this->resolveOid($raw);

			if ($oid !== null) {
				$raw = $oid;
			}
		}
		elseif ($type === 'STRING' && $value !== '' && strpos($value, 'STRING: "') !== 0 && $value[0] !== '"') {
			// Unquoted string (display hint applied by Net-SNMP).
			$raw = rtrim($raw);
		}

		return ['type' => $type, 'raw' => $raw];
	}

	/* ------------------------------------------------------------------ */
	/* Exports of the module                                               */
	/* ------------------------------------------------------------------ */

	private function importJson(string $content): array {
		$data = json_decode($content, true);

		if (!is_array($data)) {
			$this->skip(mb_substr(trim($content), 0, 80));

			return [];
		}

		if (!array_is_list($data)) {
			$this->meta = [
				'host' => is_string($data['host'] ?? null) ? $data['host'] : '',
				'request' => is_string($data['request'] ?? null) ? $data['request'] : ''
			];
			$data = $data['values'] ?? [];
		}

		$records = [];

		foreach (is_array($data) ? $data : [] as $value) {
			if (!is_array($value)) {
				continue;
			}

			$record = $this->fromFields(
				(string) ($value['oid'] ?? ''),
				array_key_exists('raw', $value) ? (string) $value['raw'] : (string) ($value['value'] ?? ''),
				(string) ($value['snmp_type'] ?? ''),
				(string) ($value['type'] ?? '')
			);

			if ($record !== null) {
				$records[] = $record;
			}
		}

		return $records;
	}

	private function importCsv(string $content): array {
		$lines = explode("\n", rtrim($content, "\n"));
		$header = array_shift($lines);
		$separator = substr_count($header, ';') >= substr_count($header, ',') ? ';' : ',';

		if (substr_count($header, "\t") > substr_count($header, $separator)) {
			$separator = "\t";
		}

		$columns = array_map(static fn($c) => strtolower(trim($c)), str_getcsv($header, $separator, '"', ''));
		$col = static fn(string ...$names) => array_values(array_intersect($names, $columns))
			? array_search(array_values(array_intersect($names, $columns))[0], $columns)
			: null;
		$c_oid = $col('oid');
		$c_raw = $col('raw', 'raw value');
		$c_value = $col('value');
		$c_snmp = $col('snmp type', 'snmp_type');
		$c_type = $col('type');

		// Quoted values may contain line breaks: rebuild records.
		$records = [];
		$buffer = '';

		foreach ($lines as $line) {
			$buffer .= ($buffer !== '' ? "\n" : '').$line;

			if (substr_count($buffer, '"') % 2 == 1) {
				continue;
			}

			$fields = str_getcsv($buffer, $separator, '"', '');
			$buffer = '';

			$record = $this->fromFields(
				(string) ($fields[$c_oid] ?? ''),
				(string) ($c_raw !== null ? ($fields[$c_raw] ?? '') : ($c_value !== null ? ($fields[$c_value] ?? '') : '')),
				$c_snmp !== null ? (string) ($fields[$c_snmp] ?? '') : '',
				$c_type !== null ? (string) ($fields[$c_type] ?? '') : ''
			);

			if ($record !== null) {
				$records[] = $record;
			}
		}

		return $records;
	}

	private function importXml(string $content): array {
		if (!class_exists('DOMDocument')) {
			$this->skip('XML');

			return [];
		}

		$dom = new \DOMDocument();
		$previous = libxml_use_internal_errors(true);
		// No network access, no entity substitution.
		$loaded = $dom->loadXML($content, LIBXML_NONET);
		libxml_use_internal_errors($previous);

		if (!$loaded || $dom->documentElement === null || $dom->documentElement->nodeName !== 'snmp_results') {
			$this->skip(mb_substr(trim($content), 0, 80));

			return [];
		}

		$root = $dom->documentElement;
		$this->meta = ['host' => $root->getAttribute('host'), 'request' => $root->getAttribute('request')];
		$records = [];

		foreach ($root->getElementsByTagName('value') as $value) {
			$field = static function (string $name) use ($value): string {
				$list = $value->getElementsByTagName($name);

				return $list->length ? $list->item(0)->textContent : '';
			};

			$record = $this->fromFields($value->getAttribute('oid'), $field('raw'), $field('snmp_type'), $field('type'));

			if ($record !== null) {
				$records[] = $record;
			}
		}

		return $records;
	}

	/**
	 * Record from exported fields. The SNMP type is the one returned by Zabbix (empty for numbers).
	 */
	private function fromFields(string $oid, string $raw, string $snmp_type, string $type): ?array {
		$numeric = $this->resolveOid(trim($oid));

		if ($numeric === null) {
			if (trim($oid) !== '') {
				$this->skip($oid.' = '.$raw);
			}

			return null;
		}

		if ($snmp_type === '' && !preg_match('/^-?\d+$/', $raw) && !in_array($type, self::NUMERIC_TYPES, true)) {
			$snmp_type = $raw === '' ? '' : 'STRING';
		}

		return ['oid' => $numeric, 'type' => $snmp_type, 'raw' => $raw];
	}
}
