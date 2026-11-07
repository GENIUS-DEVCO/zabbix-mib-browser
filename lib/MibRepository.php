<?php declare(strict_types = 0);
/*
** MIB Browser module for Zabbix 8.0
** MIB repository: loads MIB files, resolves OIDs across modules and keeps a cached index.
*/

namespace Modules\MibBrowser\Lib;

class MibRepository {

	public const SOURCE_STANDARD = 'standard';
	public const SOURCE_USER = 'user';

	/** Allowed extensions for MIB files. */
	public const EXTENSIONS = ['txt', 'mib', 'my', 'smi', ''];

	/** Base ASN.1/SMI types. */
	private const BASE_TYPES = [
		'INTEGER', 'Integer32', 'Unsigned32', 'Gauge32', 'Gauge', 'Counter32', 'Counter', 'Counter64', 'TimeTicks',
		'OCTET STRING', 'OBJECT IDENTIFIER', 'IpAddress', 'NetworkAddress', 'Opaque', 'BITS', 'SEQUENCE OF',
		'SEQUENCE'
	];

	/** Well-known OIDs used when the defining module (SNMPv2-SMI, RFC1155-SMI) is not loaded. */
	private const WELL_KNOWN = [
		'ccitt' => '0', 'iso' => '1', 'joint-iso-ccitt' => '2', 'zeroDotZero' => '0.0',
		'org' => '1.3', 'dod' => '1.3.6', 'internet' => '1.3.6.1', 'directory' => '1.3.6.1.1',
		'mgmt' => '1.3.6.1.2', 'mib-2' => '1.3.6.1.2.1', 'transmission' => '1.3.6.1.2.1.10',
		'experimental' => '1.3.6.1.3', 'private' => '1.3.6.1.4', 'enterprises' => '1.3.6.1.4.1',
		'security' => '1.3.6.1.5', 'snmpV2' => '1.3.6.1.6', 'snmpDomains' => '1.3.6.1.6.1',
		'snmpProxys' => '1.3.6.1.6.2', 'snmpModules' => '1.3.6.1.6.3'
	];

	/** Modules superseded by SMIv2 versions; their definitions lose conflicts. */
	private const LEGACY_MODULES = ['RFC1213-MIB', 'RFC1155-SMI', 'RFC-1212', 'RFC-1215', 'RFC1158-MIB'];

	private const CACHE_VERSION = 4;

	private array $dirs;
	private string $cache_dir;

	/** @var array|null  Resolved index. */
	private ?array $index = null;

	/**
	 * @param array  $dirs       [source => directory]
	 * @param string $cache_dir  Writable directory for cache files.
	 */
	public function __construct(array $dirs, string $cache_dir) {
		$this->dirs = $dirs;
		$this->cache_dir = rtrim($cache_dir, '/');
	}

	/**
	 * List MIB files of all sources.
	 *
	 * @return array  [path => ['source', 'file', 'mtime', 'size']]
	 */
	public function listFiles(): array {
		$files = [];

		foreach ($this->dirs as $source => $dir) {
			if (!is_dir($dir)) {
				continue;
			}

			foreach (scandir($dir) as $file) {
				$path = $dir.'/'.$file;

				if ($file[0] === '.' || !is_file($path)) {
					continue;
				}

				$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));

				if (!in_array($ext, self::EXTENSIONS, true)) {
					continue;
				}

				$files[$path] = [
					'source' => $source,
					'file' => $file,
					'mtime' => filemtime($path),
					'size' => filesize($path)
				];
			}
		}

		ksort($files);

		return $files;
	}

	/**
	 * Get resolved index, from cache when possible.
	 */
	public function getIndex(): array {
		if ($this->index !== null) {
			return $this->index;
		}

		$files = $this->listFiles();
		$fingerprint = md5(self::CACHE_VERSION.json_encode($files));
		$cache_file = $this->cache_dir.'/index.json';

		if (is_file($cache_file)) {
			$cached = json_decode((string) file_get_contents($cache_file), true);

			if (is_array($cached) && ($cached['fingerprint'] ?? '') === $fingerprint) {
				return $this->index = $cached;
			}
		}

		$index = $this->build($files);
		$index['fingerprint'] = $fingerprint;

		if (is_dir($this->cache_dir) && is_writable($this->cache_dir)) {
			@file_put_contents($cache_file, json_encode($index, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
				LOCK_EX
			);
		}

		return $this->index = $index;
	}

	/**
	 * Parse all files and build the resolved index.
	 */
	public function build(array $files): array {
		$parser = new MibParser();
		$modules = [];
		$module_files = [];

		foreach ($files as $path => $info) {
			$text = file_get_contents($path);

			if ($text === false) {
				continue;
			}

			foreach ($parser->parse($text) as $module) {
				// The first loaded definition of a module wins (user files are listed after standard ones,
				// so a user can't silently replace a standard module by mistake; duplicates are reported).
				if (array_key_exists($module['name'], $modules)) {
					$module_files[$module['name']]['duplicates'][] = $info['file'];
					continue;
				}

				$modules[$module['name']] = $module;
				$module_files[$module['name']] = [
					'file' => $info['file'],
					'source' => $info['source'],
					'duplicates' => []
				];
			}
		}

		// Global name lookup: symbol => [module, ...].
		$symbols = [];
		foreach ($modules as $name => $module) {
			foreach (array_keys($module['nodes']) as $symbol) {
				$symbols[$symbol][] = $name;
			}
		}

		$resolver = new class($modules, $symbols) {
			private array $modules;
			private array $symbols;
			private array $resolved = [];
			private array $in_progress = [];

			public function __construct(array $modules, array $symbols) {
				$this->modules = $modules;
				$this->symbols = $symbols;
			}

			public function findModule(string $module, string $symbol): ?string {
				if (array_key_exists($symbol, $this->modules[$module]['nodes'])) {
					return $module;
				}

				$imported_from = $this->modules[$module]['imports'][$symbol] ?? null;

				if ($imported_from !== null && array_key_exists($imported_from, $this->modules)
						&& array_key_exists($symbol, $this->modules[$imported_from]['nodes'])) {
					return $imported_from;
				}

				if (array_key_exists($symbol, $this->symbols)) {
					return $this->symbols[$symbol][0];
				}

				return null;
			}

			public function resolve(string $module, string $symbol): ?string {
				$key = $module.'::'.$symbol;

				if (array_key_exists($key, $this->resolved)) {
					return $this->resolved[$key];
				}

				$owner = $this->findModule($module, $symbol);

				if ($owner === null) {
					return $this->resolved[$key] = MibRepositoryWellKnown::get($symbol);
				}

				$owner_key = $owner.'::'.$symbol;

				if (array_key_exists($owner_key, $this->resolved)) {
					return $this->resolved[$key] = $this->resolved[$owner_key];
				}

				if (array_key_exists($owner_key, $this->in_progress)) {
					return null;
				}

				$this->in_progress[$owner_key] = true;
				$oid = $this->resolveParts($owner, $this->modules[$owner]['nodes'][$symbol]['oid']);
				unset($this->in_progress[$owner_key]);

				$this->resolved[$owner_key] = $oid;

				return $this->resolved[$key] = $oid;
			}

			public function resolveParts(string $module, array $parts): ?string {
				$arcs = [];

				foreach ($parts as $i => $part) {
					$part = (string) $part;

					if (ctype_digit($part)) {
						$arcs[] = $part;
						continue;
					}

					if (preg_match('/^([A-Za-z][\w-]*)\((\d+)\)$/', $part, $m)) {
						// name(number): the first component may still be a known name.
						if ($i == 0) {
							$base = $this->resolve($module, $m[1]);
							$arcs[] = $base ?? $m[2];
						}
						else {
							$arcs[] = $m[2];
						}
						continue;
					}

					if ($i == 0) {
						$base = $this->resolve($module, $part);

						if ($base === null) {
							return null;
						}

						$arcs[] = $base;
						continue;
					}

					return null;
				}

				return $arcs ? implode('.', $arcs) : null;
			}
		};

		$nodes = [];
		$unresolved = [];
		$names = [];

		foreach ($modules as $module_name => $module) {
			foreach ($module['nodes'] as $symbol => $node) {
				$oid = $resolver->resolve($module_name, $symbol);

				if ($oid === null) {
					$unresolved[$module_name][] = $symbol;
					continue;
				}

				$entry = [
					'oid' => $oid,
					'name' => $symbol,
					'module' => $module_name,
					'macro' => $node['macro']
				];

				foreach (['access', 'status', 'description', 'units', 'index', 'augments', 'objects', 'display_hint']
						as $field) {
					if (array_key_exists($field, $node)) {
						$entry[$field] = $node[$field];
					}
				}

				if (array_key_exists('syntax', $node)) {
					$entry['syntax'] = $node['syntax']['type'];

					$base = $this->resolveBaseType($modules, $module_name, $node['syntax']);
					$entry['base_type'] = $base['type'];

					if (array_key_exists('enum', $node['syntax'])) {
						$entry['enum'] = $node['syntax']['enum'];
					}
					elseif (array_key_exists('enum', $base)) {
						$entry['enum'] = $base['enum'];
					}

					if (!array_key_exists('display_hint', $entry) && array_key_exists('display_hint', $base)) {
						$entry['display_hint'] = $base['display_hint'];
					}

					if (array_key_exists('entry', $node['syntax'])) {
						$entry['entry'] = $node['syntax']['entry'];
					}
				}

				if (array_key_exists($oid, $nodes) && !$this->replaces($entry, $nodes[$oid])) {
					continue;
				}

				$nodes[$oid] = $entry;
			}
		}

		// Classify nodes: table, row, column, scalar, notification, ...
		foreach ($nodes as $oid => &$node) {
			$node['kind'] = $this->classify($node, $nodes);
		}
		unset($node);

		// Build children lists, adding placeholders for missing intermediate arcs.
		$children = [];

		foreach (array_keys($nodes) as $oid) {
			$arcs = explode('.', (string) $oid);

			while (count($arcs) > 1) {
				$child = implode('.', $arcs);
				array_pop($arcs);
				$parent = implode('.', $arcs);
				$children[$parent][$child] = true;

				if (array_key_exists($parent, $nodes) || array_key_exists($parent, $children) && count($children[$parent]) > 1) {
					break;
				}
			}
		}

		foreach ($children as $parent => &$list) {
			$list = array_keys($list);
			usort($list, [self::class, 'compareOids']);
		}
		unset($list);

		foreach ($nodes as $oid => $node) {
			$names[$node['name']][] = (string) $oid;
		}

		$module_info = [];

		foreach ($modules as $module_name => $module) {
			$missing = [];

			foreach (array_unique(array_values($module['imports'])) as $import) {
				if (!array_key_exists($import, $modules)) {
					$missing[] = $import;
				}
			}

			$count = 0;
			$root_oid = null;

			foreach ($nodes as $oid => $node) {
				if ($node['module'] !== $module_name) {
					continue;
				}

				$count++;
				$oid = (string) $oid;

				if ($node['macro'] === 'MODULE-IDENTITY') {
					$root_oid = $oid;
				}
				elseif ($root_oid === null || ($nodes[$root_oid]['macro'] !== 'MODULE-IDENTITY'
						&& self::compareOids($oid, $root_oid) < 0)) {
					$root_oid = $oid;
				}
			}

			$module_info[$module_name] = $module_files[$module_name] + [
				'name' => $module_name,
				'objects' => $count,
				'root_oid' => $root_oid,
				'missing_imports' => $missing,
				'unresolved' => $unresolved[$module_name] ?? [],
				'errors' => $module['errors']
			];
		}

		ksort($module_info);

		return [
			'nodes' => $nodes,
			'children' => $children,
			'names' => $names,
			'modules' => $module_info
		];
	}

	/**
	 * Decide whether a new definition of an OID should replace the existing one.
	 */
	private function replaces(array $new, array $old): bool {
		$old_legacy = in_array($old['module'], self::LEGACY_MODULES, true);
		$new_legacy = in_array($new['module'], self::LEGACY_MODULES, true);

		if ($old_legacy !== $new_legacy) {
			return $old_legacy;
		}

		// Prefer real object definitions over plain OBJECT IDENTIFIER assignments.
		if ($old['macro'] === 'OBJECT IDENTIFIER' && $new['macro'] !== 'OBJECT IDENTIFIER') {
			return true;
		}

		return false;
	}

	private function classify(array $node, array $nodes): string {
		switch ($node['macro']) {
			case 'NOTIFICATION-TYPE':
			case 'TRAP-TYPE':
				return 'notification';

			case 'OBJECT-GROUP':
			case 'NOTIFICATION-GROUP':
				return 'group';

			case 'MODULE-COMPLIANCE':
			case 'AGENT-CAPABILITIES':
				return 'compliance';

			case 'MODULE-IDENTITY':
				return 'module';

			case 'OBJECT-TYPE':
				if (($node['syntax'] ?? '') === 'SEQUENCE OF') {
					return 'table';
				}

				if (array_key_exists('index', $node) || array_key_exists('augments', $node)) {
					return 'row';
				}

				$parent = self::parentOid($node['oid']);

				if ($parent !== null && array_key_exists($parent, $nodes)
						&& (array_key_exists('index', $nodes[$parent]) || array_key_exists('augments', $nodes[$parent]))) {
					return 'column';
				}

				return 'scalar';
		}

		return 'node';
	}

	/**
	 * Resolve a syntax to its base SMI type, following textual conventions.
	 */
	private function resolveBaseType(array $modules, string $module_name, array $syntax, int $depth = 0): array {
		$type = $syntax['type'];

		if (in_array($type, self::BASE_TYPES, true) || $depth > 10) {
			return ['type' => $type];
		}

		// Find type definition: current module, imported module, any module.
		$owner = null;

		if (array_key_exists($type, $modules[$module_name]['types'])) {
			$owner = $module_name;
		}
		else {
			$from = $modules[$module_name]['imports'][$type] ?? null;

			if ($from !== null && array_key_exists($from, $modules)
					&& array_key_exists($type, $modules[$from]['types'])) {
				$owner = $from;
			}
			else {
				foreach ($modules as $name => $module) {
					if (array_key_exists($type, $module['types'])) {
						$owner = $name;
						break;
					}
				}
			}
		}

		if ($owner === null) {
			return ['type' => self::guessBaseType($type)];
		}

		$def = $modules[$owner]['types'][$type];
		$result = $this->resolveBaseType($modules, $owner, $def['syntax'], $depth + 1);

		if (array_key_exists('enum', $def['syntax'])) {
			$result['enum'] = $def['syntax']['enum'];
		}

		if (array_key_exists('display_hint', $def)) {
			$result['display_hint'] = $def['display_hint'];
		}

		return $result;
	}

	/**
	 * Base type of well-known textual conventions, when the defining module is not loaded.
	 */
	public static function guessBaseType(string $type): string {
		$map = [
			'DisplayString' => 'OCTET STRING', 'SnmpAdminString' => 'OCTET STRING', 'PhysAddress' => 'OCTET STRING',
			'MacAddress' => 'OCTET STRING', 'DateAndTime' => 'OCTET STRING', 'InetAddress' => 'OCTET STRING',
			'TruthValue' => 'INTEGER', 'RowStatus' => 'INTEGER', 'StorageType' => 'INTEGER',
			'TimeStamp' => 'TimeTicks', 'TimeInterval' => 'INTEGER', 'InterfaceIndex' => 'Integer32',
			'InterfaceIndexOrZero' => 'Integer32', 'CounterBasedGauge64' => 'Counter64',
			'ZeroBasedCounter32' => 'Counter32', 'ZeroBasedCounter64' => 'Counter64', 'AutonomousType' => 'OBJECT IDENTIFIER',
			'InetAddressType' => 'INTEGER', 'InetPortNumber' => 'Unsigned32', 'Float' => 'Opaque'
		];

		return $map[$type] ?? $type;
	}

	public static function compareOids(string $a, string $b): int {
		$a = explode('.', $a);
		$b = explode('.', $b);
		$n = min(count($a), count($b));

		for ($i = 0; $i < $n; $i++) {
			if ($a[$i] != $b[$i]) {
				return (int) $a[$i] <=> (int) $b[$i];
			}
		}

		return count($a) <=> count($b);
	}

	public static function parentOid(string $oid): ?string {
		$pos = strrpos($oid, '.');

		return $pos === false ? null : substr($oid, 0, $pos);
	}

	/* ------------------------------------------------------------------ */
	/* Queries                                                              */
	/* ------------------------------------------------------------------ */

	public function getNode(string $oid): ?array {
		$index = $this->getIndex();

		return $index['nodes'][$oid] ?? null;
	}

	/**
	 * Children of a node, in a light format for the tree.
	 */
	public function getChildren(string $oid): array {
		$index = $this->getIndex();
		$result = [];

		foreach ($index['children'][$oid] ?? [] as $child) {
			$result[] = $this->treeItem($child);
		}

		return $result;
	}

	/**
	 * Top-level entries of the tree.
	 */
	public function getRoots(): array {
		$index = $this->getIndex();
		$roots = [];

		foreach (['0', '1', '2'] as $root) {
			if (array_key_exists($root, $index['children']) || array_key_exists($root, $index['nodes'])) {
				$roots[] = $this->treeItem($root);
			}
		}

		return $roots;
	}

	public function treeItem(string $oid): array {
		$index = $this->getIndex();
		$node = $index['nodes'][$oid] ?? null;

		if ($node === null) {
			$names = ['0' => 'ccitt', '1' => 'iso', '2' => 'joint-iso-ccitt'];
			$name = $names[$oid] ?? array_search($oid, self::WELL_KNOWN, true);

			return [
				'oid' => $oid,
				'name' => $name !== false ? $name : substr($oid, (int) strrpos($oid, '.') + (strpos($oid, '.') !== false ? 1 : 0)),
				'kind' => 'node',
				'module' => '',
				'has_children' => array_key_exists($oid, $index['children'])
			];
		}

		return [
			'oid' => $oid,
			'name' => $node['name'],
			'kind' => $node['kind'],
			'module' => $node['module'],
			'access' => $node['access'] ?? '',
			'has_children' => array_key_exists($oid, $index['children'])
		];
	}

	/**
	 * Path of OIDs from root to the given OID (to expand the tree).
	 */
	public function getPath(string $oid): array {
		$arcs = explode('.', $oid);
		$path = [];

		for ($i = 1; $i <= count($arcs); $i++) {
			$path[] = implode('.', array_slice($arcs, 0, $i));
		}

		return $path;
	}

	/**
	 * Search nodes by name (case-insensitive substring) or by OID prefix.
	 */
	public function search(string $term, int $limit = 100): array {
		$index = $this->getIndex();
		$term = trim($term);
		$result = [];

		if ($term === '') {
			return [];
		}

		$term = ltrim($term, '.');

		if (preg_match('/^\d+(\.\d+)*$/', $term)) {
			$match = $this->translate($term);

			if ($match['oid'] !== null) {
				$result[] = $this->treeItem($match['oid']) + ['index' => $match['suffix']];
			}

			return $result;
		}

		// Exact "MODULE::name" lookup.
		if (strpos($term, '::') !== false) {
			[$module, $name] = explode('::', $term, 2);

			foreach ($index['names'][$name] ?? [] as $oid) {
				if (strcasecmp($index['nodes'][$oid]['module'], $module) == 0) {
					$result[] = $this->treeItem($oid);
				}
			}

			return $result;
		}

		$needle = mb_strtolower($term);
		$exact = [];
		$prefix = [];
		$contains = [];

		foreach ($index['names'] as $name => $oids) {
			$lower = mb_strtolower((string) $name);
			$pos = mb_strpos($lower, $needle);

			if ($pos === false) {
				continue;
			}

			$bucket = $lower === $needle ? 'exact' : ($pos === 0 ? 'prefix' : 'contains');

			foreach ($oids as $oid) {
				${$bucket}[] = $oid;
			}
		}

		foreach (array_merge($exact, $prefix, $contains) as $oid) {
			$result[] = $this->treeItem($oid);

			if (count($result) >= $limit) {
				break;
			}
		}

		return $result;
	}

	/**
	 * Translate a numeric OID to the longest known prefix.
	 *
	 * @return array  ['oid' => prefix or null, 'name' => 'ifDescr', 'module' => 'IF-MIB', 'suffix' => '3',
	 *                'label' => 'IF-MIB::ifDescr.3']
	 */
	public function translate(string $numeric): array {
		$index = $this->getIndex();
		$numeric = ltrim($numeric, '.');
		$arcs = explode('.', $numeric);

		$alias_skipped = false;

		for ($i = count($arcs); $i > 0; $i--) {
			$prefix = implode('.', array_slice($arcs, 0, $i));

			if (array_key_exists($prefix, $index['nodes'])) {
				$node = $index['nodes'][$prefix];

				// Instance aliases such as DISMAN-EVENT-MIB::sysUpTimeInstance ::= { sysUpTime 0 }: prefer the
				// object type they are an instance of.
				if (!$alias_skipped && $node['macro'] === 'OBJECT IDENTIFIER' && $i > 1) {
					$parent = implode('.', array_slice($arcs, 0, $i - 1));

					if (array_key_exists($parent, $index['nodes'])
							&& in_array($index['nodes'][$parent]['kind'], ['scalar', 'column'], true)) {
						$alias_skipped = true;
						continue;
					}
				}

				$suffix = implode('.', array_slice($arcs, $i));

				return [
					'oid' => $prefix,
					'name' => $node['name'],
					'module' => $node['module'],
					'suffix' => $suffix,
					'label' => $node['module'].'::'.$node['name'].($suffix !== '' ? '.'.$suffix : '')
				];
			}
		}

		return ['oid' => null, 'name' => null, 'module' => null, 'suffix' => $numeric, 'label' => $numeric];
	}

	/**
	 * Resolve a symbolic OID like "IF-MIB::ifDescr.3", "ifDescr.3" or "sysDescr" to numeric form.
	 */
	public function resolveSymbolic(string $value): ?string {
		$value = trim($value);

		if (preg_match('/^\.?\d+(\.\d+)*$/', $value)) {
			return ltrim($value, '.');
		}

		$index = $this->getIndex();
		$module = null;

		if (strpos($value, '::') !== false) {
			[$module, $value] = explode('::', $value, 2);
		}

		$parts = explode('.', $value, 2);
		$name = $parts[0];
		$suffix = $parts[1] ?? '';

		if ($suffix !== '' && !preg_match('/^\d+(\.\d+)*$/', $suffix)) {
			return null;
		}

		foreach ($index['names'][$name] ?? [] as $oid) {
			if ($module === null || strcasecmp($index['nodes'][$oid]['module'], $module) == 0) {
				return $oid.($suffix !== '' ? '.'.$suffix : '');
			}
		}

		return null;
	}

	/**
	 * All descendant nodes of an OID (inclusive), sorted.
	 */
	public function getSubtree(string $oid): array {
		$index = $this->getIndex();
		$result = [];
		$stack = [$oid];

		while ($stack) {
			$current = array_pop($stack);

			if (array_key_exists($current, $index['nodes'])) {
				$result[$current] = $index['nodes'][$current];
			}

			foreach ($index['children'][$current] ?? [] as $child) {
				$stack[] = $child;
			}
		}

		uksort($result, [self::class, 'compareOids']);

		return $result;
	}

	/**
	 * Nodes defined by a module.
	 */
	public function getModuleNodes(string $module): array {
		$index = $this->getIndex();
		$result = [];

		foreach ($index['nodes'] as $oid => $node) {
			if ($node['module'] === $module) {
				$result[(string) $oid] = $node;
			}
		}

		uksort($result, [self::class, 'compareOids']);

		return $result;
	}

	public function getModules(): array {
		return $this->getIndex()['modules'];
	}

	/**
	 * Columns of a row (entry) node.
	 */
	public function getColumns(string $row_oid): array {
		$index = $this->getIndex();
		$columns = [];

		foreach ($index['children'][$row_oid] ?? [] as $child) {
			if (array_key_exists($child, $index['nodes']) && $index['nodes'][$child]['kind'] === 'column') {
				$columns[$child] = $index['nodes'][$child];
			}
		}

		return $columns;
	}

	/**
	 * Resolve an index object name of a row to its node.
	 */
	public function findByName(string $name, ?string $module = null): ?array {
		$index = $this->getIndex();

		foreach ($index['names'][$name] ?? [] as $oid) {
			if ($module === null || $index['nodes'][$oid]['module'] === $module) {
				return $index['nodes'][$oid];
			}
		}

		return null;
	}
}

/**
 * Well-known OID helper (kept outside the anonymous class for reuse).
 */
final class MibRepositoryWellKnown {

	private const MAP = [
		'ccitt' => '0', 'iso' => '1', 'joint-iso-ccitt' => '2', 'zeroDotZero' => '0.0',
		'org' => '1.3', 'dod' => '1.3.6', 'internet' => '1.3.6.1', 'directory' => '1.3.6.1.1',
		'mgmt' => '1.3.6.1.2', 'mib-2' => '1.3.6.1.2.1', 'transmission' => '1.3.6.1.2.1.10',
		'experimental' => '1.3.6.1.3', 'private' => '1.3.6.1.4', 'enterprises' => '1.3.6.1.4.1',
		'security' => '1.3.6.1.5', 'snmpV2' => '1.3.6.1.6', 'snmpDomains' => '1.3.6.1.6.1',
		'snmpProxys' => '1.3.6.1.6.2', 'snmpModules' => '1.3.6.1.6.3'
	];

	public static function get(string $symbol): ?string {
		return self::MAP[$symbol] ?? null;
	}
}
