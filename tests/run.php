<?php declare(strict_types = 0);
/*
** MIB Browser module for Zabbix 8.0
** Unit tests of the pure PHP libraries (no Zabbix needed). Run: php tests/run.php
*/

if (!function_exists('_')) {
	function _($s) {
		return $s;
	}
}

$root = dirname(__DIR__);

foreach (['MibParser', 'MibRepository', 'SnmpResult', 'ItemMapper', 'TemplateBuilder', 'YamlWriter', 'SnmpSecurity'] as $class) {
	require $root.'/lib/'.$class.'.php';
}

use Modules\MibBrowser\Lib\ItemMapper,
	Modules\MibBrowser\Lib\MibParser,
	Modules\MibBrowser\Lib\MibRepository,
	Modules\MibBrowser\Lib\SnmpResult,
	Modules\MibBrowser\Lib\SnmpSecurity,
	Modules\MibBrowser\Lib\TemplateBuilder,
	Modules\MibBrowser\Lib\YamlWriter;

$failures = 0;
$count = 0;

function check(string $name, $actual, $expected): void {
	global $failures, $count;

	$count++;

	if ($actual !== $expected) {
		$failures++;
		echo "FAIL: $name\n  expected: ".var_export($expected, true)."\n  actual:   ".var_export($actual, true)."\n";
	}
}

function ok(string $name, bool $condition): void {
	check($name, $condition, true);
}

$cache = sys_get_temp_dir().'/mibb-tests-'.getmypid();
@mkdir($cache, 0700, true);

/* Tokenizer and parser ------------------------------------------------- */

$tokens = MibParser::tokenize("a -- comment -- b\n c \"text -- not a comment\" ::= { x 1 } 'FF'H");
check('tokenize: inline comment ends at --', $tokens, ['a', 'b', 'c', '"text -- not a comment"', '::=', '{', 'x', '1', '}', "'FF'H"]);

$text = <<<'MIB'
TEST-MIB DEFINITIONS ::= BEGIN
IMPORTS enterprises, OBJECT-TYPE, Counter32 FROM SNMPv2-SMI
        DisplayString FROM SNMPv2-TC
        TRAP-TYPE FROM RFC-1215;

acme OBJECT IDENTIFIER ::= { enterprises 99999 }
acmeProducts OBJECT IDENTIFIER ::= { iso org(3) dod(6) internet(1) private(4) enterprises(1) 99999 2 }

AcmeState ::= TEXTUAL-CONVENTION
    STATUS current
    DESCRIPTION "State."
    SYNTAX INTEGER { ok(1), failed(2) }

acmeName OBJECT-TYPE
    SYNTAX DisplayString (SIZE (0..64))
    MAX-ACCESS read-only
    STATUS current
    DESCRIPTION "Name of the box,
                 on two lines."
    ::= { acme 1 }

acmeTable OBJECT-TYPE
    SYNTAX SEQUENCE OF AcmeEntry
    MAX-ACCESS not-accessible
    STATUS current
    DESCRIPTION "Table."
    ::= { acme 2 }

acmeEntry OBJECT-TYPE
    SYNTAX AcmeEntry
    MAX-ACCESS not-accessible
    STATUS current
    DESCRIPTION "Row."
    INDEX { acmeIndex }
    ::= { acmeTable 1 }

AcmeEntry ::= SEQUENCE { acmeIndex INTEGER, acmeState AcmeState, acmePackets Counter32 }

acmeIndex OBJECT-TYPE SYNTAX INTEGER (1..100) MAX-ACCESS not-accessible STATUS current DESCRIPTION "Index." ::= { acmeEntry 1 }
acmeState OBJECT-TYPE SYNTAX AcmeState MAX-ACCESS read-only STATUS current DESCRIPTION "State." ::= { acmeEntry 2 }
acmePackets OBJECT-TYPE SYNTAX Counter32 UNITS "packets" MAX-ACCESS read-only STATUS current DESCRIPTION "Packets." ::= { acmeEntry 3 }

acmeTrap TRAP-TYPE ENTERPRISE acme VARIABLES { acmeState } DESCRIPTION "Trap." ::= 7
END

SECOND-MIB DEFINITIONS ::= BEGIN
IMPORTS acme FROM TEST-MIB;
second OBJECT IDENTIFIER ::= { acme 50 }
END
MIB;

$parser = new MibParser();
$modules = $parser->parse($text);
check('parser: two modules in one file', array_column($modules, 'name'), ['TEST-MIB', 'SECOND-MIB']);
check('parser: imports', $modules[0]['imports']['DisplayString'], 'SNMPv2-TC');
check('parser: OID value with name(number)', $modules[0]['nodes']['acmeProducts']['oid'],
	['iso', 'org(3)', 'dod(6)', 'internet(1)', 'private(4)', 'enterprises(1)', '99999', '2']
);
check('parser: description joined', $modules[0]['nodes']['acmeName']['description'], 'Name of the box, on two lines.');
check('parser: index', $modules[0]['nodes']['acmeEntry']['index'], ['acmeIndex']);
check('parser: TC enum', $modules[0]['types']['AcmeState']['syntax']['enum'], ['1' => 'ok', '2' => 'failed']);
check('parser: trap OID', $modules[0]['nodes']['acmeTrap']['oid'], ['acme', '0', '7']);
check('parser: no errors', $modules[0]['errors'], []);

/* Repository with test MIB + bundled standard MIBs ----------------------- */

$user_dir = $cache.'/user';
@mkdir($user_dir);
file_put_contents($user_dir.'/TEST-MIB.mib', $text);

$repository = new MibRepository(['standard' => $root.'/mibs', 'user' => $user_dir], $cache);
$index = $repository->getIndex();

check('repo: enterprise OID', $repository->getNode('1.3.6.1.4.1.99999.1')['name'] ?? null, 'acmeName');
check('repo: OID from name(number) form', $repository->getNode('1.3.6.1.4.1.99999.2')['name'] ?? null, 'acmeTable');
check('repo: imported parent', $repository->getNode('1.3.6.1.4.1.99999.50')['module'] ?? null, 'SECOND-MIB');
check('repo: kinds', [
	$repository->getNode('1.3.6.1.4.1.99999.1')['kind'],
	$repository->getNode('1.3.6.1.4.1.99999.2')['kind'],
	$repository->getNode('1.3.6.1.4.1.99999.2.1')['kind'],
	$repository->getNode('1.3.6.1.4.1.99999.2.1.3')['kind'],
	$repository->getNode('1.3.6.1.4.1.99999.0.7')['kind']
], ['scalar', 'table', 'row', 'column', 'notification']);
check('repo: TC base type and enum', [
	$repository->getNode('1.3.6.1.4.1.99999.2.1.2')['base_type'],
	$repository->getNode('1.3.6.1.4.1.99999.2.1.2')['enum']
], ['INTEGER', ['1' => 'ok', '2' => 'failed']]);
check('repo: DisplayString resolved through SNMPv2-TC', $repository->getNode('1.3.6.1.4.1.99999.1')['base_type'],
	'OCTET STRING'
);
check('repo: standard ifOperStatus', $repository->getNode('1.3.6.1.2.1.2.2.1.8')['name'] ?? null, 'ifOperStatus');
check('repo: IF-MIB wins over RFC1213-MIB', $repository->getNode('1.3.6.1.2.1.2.2')['module'], 'IF-MIB');
check('repo: translate instance', $repository->translate('.1.3.6.1.2.1.2.2.1.10.3')['label'], 'IF-MIB::ifInOctets.3');
check('repo: translate prefers object over instance alias', $repository->translate('1.3.6.1.2.1.1.3.0')['label'],
	'SNMPv2-MIB::sysUpTime.0'
);
check('repo: translate unknown', $repository->translate('1.3.6.1.4.1.12345.1')['oid'], '1.3.6.1.4.1');
check('repo: symbolic with module', $repository->resolveSymbolic('IF-MIB::ifDescr.3'), '1.3.6.1.2.1.2.2.1.2.3');
check('repo: symbolic without module', $repository->resolveSymbolic('sysUpTime.0'), '1.3.6.1.2.1.1.3.0');
check('repo: symbolic numeric', $repository->resolveSymbolic('.1.3.6.1'), '1.3.6.1');
check('repo: symbolic unknown', $repository->resolveSymbolic('NOPE-MIB::nope'), null);
check('repo: search exact first', ($repository->search('ifTable')[0]['name'] ?? null), 'ifTable');
check('repo: columns of ifEntry', count($repository->getColumns('1.3.6.1.2.1.2.2.1')), 22);
check('repo: module root', $index['modules']['IF-MIB']['root_oid'], '1.3.6.1.2.1.31');
check('repo: children sorted numerically',
	array_slice(array_column($repository->getChildren('1.3.6.1.2.1.2.2.1'), 'oid'), 0, 3),
	['1.3.6.1.2.1.2.2.1.1', '1.3.6.1.2.1.2.2.1.2', '1.3.6.1.2.1.2.2.1.3']
);

$ignore = ['RFC-1212', 'RFC-1215', 'SNMPv2-SMI', 'SNMPv2-CONF'];
$missing = [];

foreach ($index['modules'] as $module) {
	if ($module['source'] === 'standard' && array_diff($module['missing_imports'], $ignore)) {
		$missing[] = $module['name'];
	}
}

check('repo: bundled MIBs have no missing dependency', $missing, []);

$repository2 = new MibRepository(['standard' => $root.'/mibs', 'user' => $user_dir], $cache);
check('repo: cache reused', $repository2->getIndex()['fingerprint'], $index['fingerprint']);

/* SNMP results (fixtures captured from Zabbix 8.0 server walk[]) ----------- */

$records = SnmpResult::parseWalk(file_get_contents(__DIR__.'/fixtures/walk-system.txt'));
check('walk: record count', count($records), 37);
check('walk: string', [$records[0]['oid'], $records[0]['type']], ['1.3.6.1.2.1.1.1.0', 'STRING']);
check('walk: OID value', [$records[1]['type'], $records[1]['raw']], ['OID', '1.3.6.1.4.1.8072.3.2.10']);
check('walk: untyped number', [$records[2]['type'], ctype_digit($records[2]['raw'])], ['', true]);

$records = SnmpResult::parseWalk(".1.3.6.1.2.1.1.1.0 = STRING: \"line 1\nline 2 with \\\"quote\\\"\"\n.1.3.6.1.2.1.1.5.0 = \"\"");
check('walk: multi-line string', $records[0]['raw'], "line 1\nline 2 with \"quote\"");
check('walk: empty string', [$records[1]['type'], $records[1]['raw']], ['STRING', '']);

$records = SnmpResult::parseWalk(file_get_contents(__DIR__.'/fixtures/walk-iftable.txt'));
$by_oid = array_column($records, null, 'oid');
$mac = $by_oid['1.3.6.1.2.1.2.2.1.6.2'];
check('format: MAC address', SnmpResult::format($mac, $repository->getNode('1.3.6.1.2.1.2.2.1.6'))['type'],
	'PhysAddress'
);
ok('format: MAC value', (bool) preg_match('/^([0-9A-F]{2}:){5}[0-9A-F]{2}$/',
	SnmpResult::format($mac, $repository->getNode('1.3.6.1.2.1.2.2.1.6'))['value']
));
check('format: enum', SnmpResult::format(['type' => '', 'raw' => '1'], $repository->getNode('1.3.6.1.2.1.2.2.1.8'))['value'],
	'up (1)'
);
check('format: time ticks', SnmpResult::format(['type' => '', 'raw' => '8640123'], $repository->getNode('1.3.6.1.2.1.1.3'))['value'],
	'1j 00:00:01.23 (8640123)'
);

$records = SnmpResult::parseWalk(file_get_contents(__DIR__.'/fixtures/walk-hrsystem.txt'));
$date = SnmpResult::format($records[1], $repository->getNode('1.3.6.1.2.1.25.1.2'));
ok('format: DateAndTime', (bool) preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} [+-]\d{2}:\d{2}$/', $date['value']));

/* Item mapping -------------------------------------------------------------- */

$counter = $repository->getNode('1.3.6.1.2.1.31.1.1.1.6');
check('mapper: counter64 value type', ItemMapper::valueType($counter), ItemMapper::VALUE_FLOAT);
check('mapper: octets units', ItemMapper::units($counter), 'Bps');
check('mapper: counter preprocessing', array_column(ItemMapper::preprocessing($counter), 'type'),
	[ItemMapper::PP_CHANGE_PER_SECOND]
);
check('mapper: enum value type', ItemMapper::valueType($repository->getNode('1.3.6.1.2.1.2.2.1.8')),
	ItemMapper::VALUE_UINT64
);
check('mapper: uptime', [ItemMapper::units($repository->getNode('1.3.6.1.2.1.1.3')),
	ItemMapper::preprocessing($repository->getNode('1.3.6.1.2.1.1.3'))[0]['params']
], ['uptime', '0.01']);
check('mapper: string', ItemMapper::valueType($repository->getNode('1.3.6.1.2.1.1.1')), ItemMapper::VALUE_STR);
check('mapper: MAC walk format', ItemMapper::walkFormat($repository->getNode('1.3.6.1.2.1.2.2.1.6')),
	ItemMapper::FORMAT_MAC_FROM_HEX
);
check('mapper: key', ItemMapper::key('net.if', $counter, '3'), 'net.if.ifHCInOctets[3]');
check('mapper: scalar key', ItemMapper::key('', $repository->getNode('1.3.6.1.2.1.1.5'), '0'), 'sysName');
check('mapper: value map', ItemMapper::valueMap($repository->getNode('1.3.6.1.2.1.2.2.1.8'))['mappings'][0],
	['value' => '1', 'newvalue' => 'up']
);

/* Template builder ------------------------------------------------------------ */

$builder = new TemplateBuilder($repository, ['template' => 'Acme (test) by SNMP', 'key_prefix' => 'acme']);
$export = $builder->build(['module:TEST-MIB']);
$template = $export['zabbix_export']['templates'][0];

check('template: version', $export['zabbix_export']['version'], '8.0');
check('template: technical name', $template['template'], 'Acme test by SNMP');
check('template: visible name', $template['name'], 'Acme (test) by SNMP');
check('template: items', array_column($template['items'], 'key'), ['acme.acmeName', 'acme.acmeTable.walk']);
check('template: scalar get', $template['items'][0]['snmp_oid'], 'get[1.3.6.1.4.1.99999.1.0]');
check('template: master walk', [$template['items'][1]['snmp_oid'], $template['items'][1]['history']],
	['walk[1.3.6.1.4.1.99999.2.1]', '0']
);

$rule = $template['discovery_rules'][0];
check('template: rule type', [$rule['type'], $rule['master_item']['key']], ['DEPENDENT', 'acme.acmeTable.walk']);
check('template: walk to JSON params', $rule['preprocessing'][0]['parameters'],
	['{#ACMESTATE}', '1.3.6.1.4.1.99999.2.1.2', '0']
);
check('template: prototypes', array_column($rule['item_prototypes'], 'key'),
	['acme.acmeState[{#SNMPINDEX}]', 'acme.acmePackets[{#SNMPINDEX}]']
);
check('template: prototype walk value', $rule['item_prototypes'][1]['preprocessing'][0],
	['type' => 'SNMP_WALK_VALUE', 'parameters' => ['1.3.6.1.4.1.99999.2.1.3.{#SNMPINDEX}', '0']]
);
check('template: prototype rate', $rule['item_prototypes'][1]['preprocessing'][1], ['type' => 'CHANGE_PER_SECOND']);
check('template: value map', $template['valuemaps'][0]['name'], 'TEST-MIB::AcmeState');
ok('template: uuid format', (bool) preg_match('/^[0-9a-f]{12}4[0-9a-f]{3}[89ab][0-9a-f]{15}$/', $template['uuid']));

$export2 = (new TemplateBuilder($repository, ['template' => 'Acme (test) by SNMP', 'key_prefix' => 'acme']))
	->build(['module:TEST-MIB']);
check('template: deterministic uuids', $export2['zabbix_export']['templates'][0]['uuid'], $template['uuid']);

$if = (new TemplateBuilder($repository, ['template' => 'IF']))->build(['1.3.6.1.2.1.2.2']);
check('template: ifTable prototypes', count($if['zabbix_export']['templates'][0]['discovery_rules'][0]['item_prototypes']), 22);

check('mapper: obsolete not pollable', ItemMapper::isPollable(['access' => 'read-only', 'status' => 'obsolete']), false);
check('mapper: deprecated pollable', ItemMapper::isPollable(['access' => 'read-only', 'status' => 'deprecated']), true);

/* SNMP security ------------------------------------------------------------- */

$v2 = SnmpSecurity::assess(['version' => '2', 'community' => 'public']);
check('security: v2c not encrypted', [$v2['level'], $v2['encrypted'], $v2['summary']], ['none', false, 'SNMPv2c']);
check('security: v1', SnmpSecurity::assess(['version' => '1'])['level'], 'none');
check('security: v3 noAuthNoPriv', SnmpSecurity::assess(['version' => '3', 'securitylevel' => '0'])['level'], 'none');
check('security: v3 authNoPriv', SnmpSecurity::assess(['version' => '3', 'securitylevel' => '1', 'authprotocol' => '3']),
	['level' => 'auth_only', 'encrypted' => false, 'summary' => 'SNMPv3 authNoPriv (SHA256)',
		'issues' => ['Authentifié mais non chiffré (authNoPriv) : les valeurs circulent en clair.']]
);
$strong = SnmpSecurity::assess(['version' => '3', 'securitylevel' => '2', 'authprotocol' => '3', 'privprotocol' => '3']);
check('security: v3 authPriv SHA256/AES256', [$strong['level'], $strong['encrypted'], $strong['summary'], $strong['issues']],
	['encrypted', true, 'SNMPv3 authPriv (SHA256/AES256)', []]
);
$weak = SnmpSecurity::assess(['version' => '3', 'securitylevel' => '2', 'authprotocol' => '0', 'privprotocol' => '0']);
check('security: MD5/DES is weak', [$weak['level'], $weak['encrypted'], count($weak['issues'])], ['weak', true, 2]);
check('security: SHA1/AES128 is weak', SnmpSecurity::assess(['version' => '3', 'securitylevel' => '2',
	'authprotocol' => '1', 'privprotocol' => '1'])['level'], 'weak'
);
check('security: policy warn allows all', SnmpSecurity::isAllowed('none', 'warn'), true);
check('security: policy require blocks clear text', [SnmpSecurity::isAllowed('none', 'require_encryption'),
	SnmpSecurity::isAllowed('auth_only', 'require_encryption'), SnmpSecurity::isAllowed('weak', 'require_encryption'),
	SnmpSecurity::isAllowed('encrypted', 'require_encryption')
], [false, false, true, true]);
check('security: unknown policy falls back to warn', SnmpSecurity::normalizePolicy('whatever'), 'warn');

/* YAML ------------------------------------------------------------------------ */

$yaml = YamlWriter::write(['zabbix_export' => ['version' => '8.0', 'templates' => [[
	'name' => 'A: b', 'description' => "line 1\nline 2", 'tags' => [['tag' => 'mib', 'value' => 'IF-MIB']],
	'parameters' => ['{#X}', '1.3.6', '0']
]]]]);
$expected = <<<'YAML'
zabbix_export:
  version: '8.0'
  templates:
    - name: 'A: b'
      description: |-
        line 1
        line 2
      tags:
        - tag: mib
          value: IF-MIB
      parameters:
        - '{#X}'
        - '1.3.6'
        - '0'

YAML;
check('yaml: output', $yaml, $expected);
check('yaml: quoting', [YamlWriter::scalar('yes'), YamlWriter::scalar("it's"), YamlWriter::scalar('plain text')],
	["'yes'", "'it''s'", 'plain text']
);

// Cleanup.
array_map('unlink', glob($user_dir.'/*'));
@rmdir($user_dir);
array_map('unlink', glob($cache.'/*'));
@rmdir($cache);

echo ($failures ? "\n$failures/$count tests FAILED\n" : "$count tests passed\n");
exit($failures ? 1 : 0);
