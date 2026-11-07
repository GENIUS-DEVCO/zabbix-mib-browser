<?php declare(strict_types = 0);
/*
** MIB Browser module for Zabbix 8.0
** Lightweight SMIv1/SMIv2 parser.
**
** The parser extracts the information needed by a MIB browser from MIB files:
** - module name and imports;
** - OBJECT IDENTIFIER assignments and macro invocations (OBJECT-TYPE, MODULE-IDENTITY, OBJECT-IDENTITY,
**   NOTIFICATION-TYPE, TRAP-TYPE, OBJECT-GROUP, ...);
** - textual conventions and type assignments (to resolve base syntaxes).
**
** It is deliberately tolerant: unknown constructs are skipped instead of failing the whole file.
*/

namespace Modules\MibBrowser\Lib;

class MibParser {

	/** Macros whose invocation defines an OID node. */
	public const NODE_MACROS = [
		'OBJECT-TYPE', 'MODULE-IDENTITY', 'OBJECT-IDENTITY', 'NOTIFICATION-TYPE', 'TRAP-TYPE', 'OBJECT-GROUP',
		'NOTIFICATION-GROUP', 'MODULE-COMPLIANCE', 'AGENT-CAPABILITIES'
	];

	/** Clauses that start a new section inside a macro invocation. */
	private const CLAUSES = [
		'SYNTAX', 'UNITS', 'MAX-ACCESS', 'ACCESS', 'MIN-ACCESS', 'STATUS', 'DESCRIPTION', 'REFERENCE', 'INDEX',
		'AUGMENTS', 'DEFVAL', 'OBJECTS', 'NOTIFICATIONS', 'ENTERPRISE', 'VARIABLES', 'LAST-UPDATED',
		'ORGANIZATION', 'CONTACT-INFO', 'REVISION', 'DISPLAY-HINT', 'MODULE', 'MANDATORY-GROUPS', 'GROUP',
		'OBJECT', 'WRITE-SYNTAX', 'PRODUCT-RELEASE', 'SUPPORTS', 'INCLUDES', 'VARIATION', 'CREATION-REQUIRES'
	];

	private array $tokens = [];
	private int $pos = 0;
	private int $count = 0;

	/**
	 * Parse MIB source text. A file may contain several modules.
	 *
	 * @param string $text
	 *
	 * @return array  List of modules: ['name', 'imports' => [symbol => module], 'nodes' => [...], 'types' => [...],
	 *                'errors' => [...]]
	 */
	public function parse(string $text): array {
		$this->tokens = self::tokenize($text);
		$this->count = count($this->tokens);
		$this->pos = 0;

		$modules = [];

		while ($this->pos < $this->count) {
			// Look for "<Name> DEFINITIONS [tags] ::= BEGIN".
			if ($this->peek(1) === 'DEFINITIONS') {
				$name = $this->tokens[$this->pos];
				$this->pos += 2;

				while ($this->pos < $this->count && $this->tokens[$this->pos] !== '::=') {
					$this->pos++;
				}

				$this->pos++;

				if ($this->current() === 'BEGIN') {
					$this->pos++;
					$modules[] = $this->parseModuleBody($name);
					continue;
				}
			}

			$this->pos++;
		}

		return $modules;
	}

	private function parseModuleBody(string $module_name): array {
		$module = [
			'name' => $module_name,
			'imports' => [],
			'nodes' => [],
			'types' => [],
			'errors' => []
		];

		while ($this->pos < $this->count) {
			$token = $this->tokens[$this->pos];

			if ($token === 'END') {
				$this->pos++;
				break;
			}

			if ($token === 'IMPORTS') {
				$this->pos++;
				$this->parseImports($module);
				continue;
			}

			if ($token === 'EXPORTS') {
				$this->skipUntil(';');
				continue;
			}

			$next = $this->peek(1);

			// MACRO definitions (as in SNMPv2-SMI) are skipped as a whole.
			if ($next === 'MACRO') {
				$this->skipMacroDefinition();
				continue;
			}

			try {
				if ($next === 'OBJECT' && $this->peek(2) === 'IDENTIFIER' && $this->peek(3) === '::=') {
					// name OBJECT IDENTIFIER ::= { parent n }
					$name = $token;
					$this->pos += 4;
					$oid = $this->parseOidValue();
					$module['nodes'][$name] = ['name' => $name, 'macro' => 'OBJECT IDENTIFIER', 'oid' => $oid];
					continue;
				}

				if ($next !== null && in_array($next, self::NODE_MACROS, true) && self::isValueName($token)) {
					$name = $token;
					$this->pos += 2;
					$node = $this->parseMacroInvocation($name, $next);

					if ($node !== null) {
						$module['nodes'][$name] = $node;
					}
					continue;
				}

				if ($next === '::=' && self::isTypeName($token)) {
					// Type assignment: Name ::= TEXTUAL-CONVENTION ... | Name ::= <type>.
					$name = $token;
					$this->pos += 2;
					$type = $this->parseTypeAssignment();

					if ($type !== null) {
						$module['types'][$name] = $type;
					}
					continue;
				}
			}
			catch (\RuntimeException $e) {
				$module['errors'][] = $e->getMessage();
				continue;
			}

			$this->pos++;
		}

		return $module;
	}

	private function parseImports(array &$module): void {
		$symbols = [];

		while ($this->pos < $this->count) {
			$token = $this->tokens[$this->pos];

			if ($token === ';') {
				$this->pos++;
				break;
			}

			if ($token === 'FROM') {
				$from = $this->peek(1);
				$this->pos += 2;

				if ($from !== null) {
					foreach ($symbols as $symbol) {
						$module['imports'][$symbol] = $from;
					}
				}

				$symbols = [];
				continue;
			}

			if ($token !== ',') {
				$symbols[] = $token;
			}

			$this->pos++;
		}
	}

	/**
	 * Parse the clauses of a macro invocation up to and including "::= value".
	 */
	private function parseMacroInvocation(string $name, string $macro): ?array {
		$node = ['name' => $name, 'macro' => $macro];

		while ($this->pos < $this->count) {
			$token = $this->tokens[$this->pos];

			if ($token === '::=') {
				$this->pos++;

				if ($macro === 'TRAP-TYPE') {
					// SMIv1 trap: ::= <number>, OID is derived from ENTERPRISE.
					$number = $this->current();
					$this->pos++;

					if (!array_key_exists('enterprise', $node) || !ctype_digit((string) $number)) {
						return null;
					}

					$node['oid'] = [$node['enterprise'], '0', $number];
					$node['trap_number'] = $number;
				}
				else {
					$node['oid'] = $this->parseOidValue();
				}

				return $node;
			}

			switch ($token) {
				case 'SYNTAX':
					$this->pos++;
					$node['syntax'] = $this->parseSyntax();
					break;

				case 'UNITS':
				case 'DESCRIPTION':
				case 'REFERENCE':
				case 'DISPLAY-HINT':
				case 'LAST-UPDATED':
				case 'ORGANIZATION':
				case 'CONTACT-INFO':
					$this->pos++;
					$value = $this->current();
					$this->pos++;

					if (self::isString($value)) {
						$key = strtolower(str_replace('-', '_', $token));
						if ($key !== 'reference' && $key !== 'contact_info') {
							$node[$key] = self::cleanString($value);
						}
					}
					break;

				case 'MAX-ACCESS':
				case 'ACCESS':
				case 'MIN-ACCESS':
					$this->pos++;
					if ($token !== 'MIN-ACCESS') {
						$node['access'] = $this->current();
					}
					$this->pos++;
					break;

				case 'STATUS':
					$this->pos++;
					$node['status'] = $this->current();
					$this->pos++;
					break;

				case 'INDEX':
					$this->pos++;
					$node['index'] = $this->parseNameList(true);
					break;

				case 'AUGMENTS':
					$this->pos++;
					$list = $this->parseNameList(false);
					if ($list) {
						$node['augments'] = $list[0];
					}
					break;

				case 'OBJECTS':
				case 'VARIABLES':
				case 'NOTIFICATIONS':
					$this->pos++;
					$list = $this->parseNameList(false);
					if ($token !== 'NOTIFICATIONS') {
						$node['objects'] = $list;
					}
					break;

				case 'ENTERPRISE':
					$this->pos++;
					$node['enterprise'] = $this->current();
					$this->pos++;
					break;

				case 'DEFVAL':
					$this->pos++;
					$this->skipBalanced('{', '}');
					break;

				default:
					$this->pos++;
			}
		}

		throw new \RuntimeException(sprintf('Unexpected end of file in definition of "%s".', $name));
	}

	/**
	 * Parse a syntax: base type name, optional enumeration or constraints.
	 */
	private function parseSyntax(): array {
		$syntax = ['type' => ''];
		$token = $this->current();

		if ($token === 'OCTET' && $this->peek(1) === 'STRING') {
			$syntax['type'] = 'OCTET STRING';
			$this->pos += 2;
		}
		elseif ($token === 'OBJECT' && $this->peek(1) === 'IDENTIFIER') {
			$syntax['type'] = 'OBJECT IDENTIFIER';
			$this->pos += 2;
		}
		elseif ($token === 'SEQUENCE' && $this->peek(1) === 'OF') {
			$syntax['type'] = 'SEQUENCE OF';
			$syntax['entry'] = $this->peek(2);
			$this->pos += 3;

			return $syntax;
		}
		elseif ($token === 'SEQUENCE') {
			$syntax['type'] = 'SEQUENCE';
			$this->pos++;
			$this->skipBalanced('{', '}');

			return $syntax;
		}
		else {
			// Possibly qualified: Module.Type
			$syntax['type'] = $token;
			$this->pos++;

			if ($this->current() === '.' && $this->peek(1) !== null) {
				$syntax['type'] = $this->peek(1);
				$this->pos += 2;
			}
		}

		if ($this->current() === '{') {
			$enum = $this->parseEnumeration();

			if ($enum) {
				$syntax['enum'] = $enum;
			}
		}

		if ($this->current() === '(') {
			$syntax['range'] = $this->collectBalanced('(', ')');
		}

		return $syntax;
	}

	/**
	 * Parse "{ label(1), other(2) }".
	 */
	private function parseEnumeration(): array {
		$enum = [];
		$depth = 0;
		$label = null;

		while ($this->pos < $this->count) {
			$token = $this->tokens[$this->pos];
			$this->pos++;

			if ($token === '{') {
				$depth++;
				continue;
			}

			if ($token === '}') {
				$depth--;

				if ($depth <= 0) {
					break;
				}
				continue;
			}

			if ($token === '(') {
				$value = $this->current();

				if ($label !== null && preg_match('/^-?\d+$/', (string) $value)) {
					$enum[$value] = $label;
				}
				$label = null;
				continue;
			}

			if (self::isValueName($token)) {
				$label = $token;
			}
		}

		return $enum;
	}

	/**
	 * Parse "{ a, IMPLIED b }" and return names. IMPLIED keyword is kept as prefix when requested.
	 */
	private function parseNameList(bool $keep_implied): array {
		$list = [];

		if ($this->current() !== '{') {
			return $list;
		}

		$this->pos++;
		$implied = false;

		while ($this->pos < $this->count) {
			$token = $this->tokens[$this->pos];
			$this->pos++;

			if ($token === '}') {
				break;
			}

			if ($token === ',') {
				continue;
			}

			if ($token === 'IMPLIED') {
				$implied = true;
				continue;
			}

			$list[] = ($implied && $keep_implied) ? 'IMPLIED '.$token : $token;
			$implied = false;
		}

		return $list;
	}

	/**
	 * Parse an OID value "{ parent 1 }", "{ iso org(3) dod(6) 1 }", "{ 1 3 6 }".
	 *
	 * @return array  List of components: first can be a name, others are numbers or "name(number)" strings.
	 */
	private function parseOidValue(): array {
		if ($this->current() !== '{') {
			throw new \RuntimeException('Invalid OID value near "'.$this->current().'".');
		}

		$this->pos++;
		$parts = [];

		while ($this->pos < $this->count) {
			$token = $this->tokens[$this->pos];
			$this->pos++;

			if ($token === '}') {
				break;
			}

			if ($token === '(') {
				// name(number): attach number to the previous name.
				$number = $this->current();
				$this->pos++;

				if ($this->current() === ')') {
					$this->pos++;
				}

				if ($parts) {
					$parts[count($parts) - 1] .= '('.$number.')';
				}
				continue;
			}

			$parts[] = $token;
		}

		if (!$parts) {
			throw new \RuntimeException('Empty OID value.');
		}

		return $parts;
	}

	/**
	 * Parse right-hand side of a type assignment.
	 */
	private function parseTypeAssignment(): ?array {
		$token = $this->current();

		if ($token === 'TEXTUAL-CONVENTION') {
			$this->pos++;
			$type = ['tc' => true];

			while ($this->pos < $this->count) {
				$token = $this->tokens[$this->pos];

				if ($token === 'SYNTAX') {
					$this->pos++;
					$type['syntax'] = $this->parseSyntax();

					return $type;
				}

				if ($token === 'DISPLAY-HINT') {
					$this->pos++;
					$value = $this->current();
					$this->pos++;

					if (self::isString($value)) {
						$type['display_hint'] = self::cleanString($value);
					}
					continue;
				}

				if ($token === 'DESCRIPTION' || $token === 'REFERENCE') {
					$this->pos += 2;
					continue;
				}

				$this->pos++;
			}

			return null;
		}

		// [APPLICATION n] IMPLICIT <type> (as in SNMPv2-SMI / RFC1155-SMI).
		if ($token === '[') {
			$this->skipBalanced('[', ']');

			if ($this->current() === 'IMPLICIT') {
				$this->pos++;
			}
		}

		if ($this->current() === 'CHOICE') {
			$this->pos++;
			$this->skipBalanced('{', '}');

			return null;
		}

		$syntax = $this->parseSyntax();

		if ($syntax['type'] === 'SEQUENCE' || $syntax['type'] === '') {
			return null;
		}

		return ['tc' => false, 'syntax' => $syntax];
	}

	private function skipMacroDefinition(): void {
		while ($this->pos < $this->count && $this->tokens[$this->pos] !== 'BEGIN') {
			$this->pos++;
		}

		while ($this->pos < $this->count && $this->tokens[$this->pos] !== 'END') {
			$this->pos++;
		}

		$this->pos++;
	}

	private function skipUntil(string $token): void {
		while ($this->pos < $this->count && $this->tokens[$this->pos] !== $token) {
			$this->pos++;
		}

		$this->pos++;
	}

	private function skipBalanced(string $open, string $close): void {
		$this->collectBalanced($open, $close);
	}

	private function collectBalanced(string $open, string $close): string {
		if ($this->current() !== $open) {
			return '';
		}

		$depth = 0;
		$out = [];

		while ($this->pos < $this->count) {
			$token = $this->tokens[$this->pos];
			$this->pos++;
			$out[] = $token;

			if ($token === $open) {
				$depth++;
			}
			elseif ($token === $close) {
				$depth--;

				if ($depth === 0) {
					break;
				}
			}
		}

		return implode(' ', $out);
	}

	private function current(): ?string {
		return $this->tokens[$this->pos] ?? null;
	}

	private function peek(int $offset): ?string {
		return $this->tokens[$this->pos + $offset] ?? null;
	}

	/**
	 * Split MIB text into tokens. Comments ("--" up to end of line or next "--") are removed;
	 * quoted strings are kept as single tokens including quotes.
	 */
	public static function tokenize(string $text): array {
		$tokens = [];
		$len = strlen($text);
		$i = 0;

		while ($i < $len) {
			$c = $text[$i];

			// Whitespace.
			if ($c === ' ' || $c === "\t" || $c === "\n" || $c === "\r" || $c === "\f" || $c === "\v") {
				$i++;
				continue;
			}

			// Comment.
			if ($c === '-' && $i + 1 < $len && $text[$i + 1] === '-') {
				$i += 2;

				while ($i < $len && $text[$i] !== "\n" && $text[$i] !== "\r") {
					if ($text[$i] === '-' && $i + 1 < $len && $text[$i + 1] === '-') {
						$i += 2;
						continue 2;
					}
					$i++;
				}
				continue;
			}

			// Quoted string.
			if ($c === '"') {
				$end = strpos($text, '"', $i + 1);

				if ($end === false) {
					$end = $len - 1;
				}

				$tokens[] = substr($text, $i, $end - $i + 1);
				$i = $end + 1;
				continue;
			}

			// Binary/hex strings: 'xx'H or 'xx'B.
			if ($c === "'") {
				$end = strpos($text, "'", $i + 1);

				if ($end === false) {
					$end = $len - 1;
				}

				$end++;

				if ($end < $len && ($text[$end] === 'H' || $text[$end] === 'h' || $text[$end] === 'B'
						|| $text[$end] === 'b')) {
					$end++;
				}

				$tokens[] = substr($text, $i, $end - $i);
				$i = $end;
				continue;
			}

			if ($c === ':' && substr($text, $i, 3) === '::=') {
				$tokens[] = '::=';
				$i += 3;
				continue;
			}

			if ($c === '.' && $i + 1 < $len && $text[$i + 1] === '.') {
				$tokens[] = '..';
				$i += 2;
				continue;
			}

			if (strpos('{}()[],;|.', $c) !== false) {
				$tokens[] = $c;
				$i++;
				continue;
			}

			// Identifier or number (letters, digits, hyphens, underscores).
			if (ctype_alnum($c) || $c === '_' || $c === '-') {
				$start = $i;
				$i++;

				while ($i < $len) {
					$d = $text[$i];

					if (ctype_alnum($d) || $d === '_') {
						$i++;
						continue;
					}

					// Hyphen inside identifier, but not a comment start.
					if ($d === '-' && !($i + 1 < $len && $text[$i + 1] === '-')) {
						$i++;
						continue;
					}

					break;
				}

				$tokens[] = rtrim(substr($text, $start, $i - $start), '-');
				continue;
			}

			// Any other character is a single-character token.
			$tokens[] = $c;
			$i++;
		}

		return $tokens;
	}

	public static function isValueName(?string $token): bool {
		return $token !== null && (bool) preg_match('/^[a-z][A-Za-z0-9_-]*$/', $token);
	}

	public static function isTypeName(?string $token): bool {
		return $token !== null && (bool) preg_match('/^[A-Z][A-Za-z0-9_-]*$/', $token)
			&& !in_array($token, ['OBJECT', 'BEGIN', 'END', 'IMPORTS', 'EXPORTS', 'FROM'], true);
	}

	public static function isString(?string $token): bool {
		return $token !== null && strlen($token) >= 2 && $token[0] === '"';
	}

	/**
	 * Remove quotes and normalize indentation of a quoted string.
	 */
	public static function cleanString(string $value): string {
		$value = substr($value, 1, -1);
		$value = str_replace("\r\n", "\n", $value);
		$lines = explode("\n", $value);
		$lines = array_map('trim', $lines);

		// Join wrapped lines, keep paragraph breaks.
		$out = [];
		$paragraph = [];

		foreach ($lines as $line) {
			if ($line === '') {
				if ($paragraph) {
					$out[] = implode(' ', $paragraph);
					$paragraph = [];
				}
				continue;
			}

			$paragraph[] = $line;
		}

		if ($paragraph) {
			$out[] = implode(' ', $paragraph);
		}

		return implode("\n\n", $out);
	}
}
