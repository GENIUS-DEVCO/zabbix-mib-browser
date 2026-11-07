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

// Zabbix translation helper with sprintf-style positional arguments.
function _s($format, ...$args) {
	return vsprintf($format, $args);
}

$root = dirname(__DIR__);

foreach (['I18n', 'MibParser', 'MibRepository', 'SnmpResult', 'ItemMapper', 'TemplateBuilder', 'YamlWriter', 'SnmpSecurity',
		'ConnectionDiagnosis', 'ZipWriter', 'ArchiveReader', 'XlsxWriter', 'ResultExporter', 'WalkImporter',
		'ZabbixXmlWriter', 'TreeExporter'] as $class) {
	require $root.'/lib/'.$class.'.php';
}

use Modules\MibBrowser\Lib\ArchiveReader,
	Modules\MibBrowser\Lib\ConnectionDiagnosis,
	Modules\MibBrowser\Lib\I18n,
	Modules\MibBrowser\Lib\ItemMapper,
	Modules\MibBrowser\Lib\MibParser,
	Modules\MibBrowser\Lib\MibRepository,
	Modules\MibBrowser\Lib\SnmpResult,
	Modules\MibBrowser\Lib\SnmpSecurity,
	Modules\MibBrowser\Lib\ResultExporter,
	Modules\MibBrowser\Lib\TemplateBuilder,
	Modules\MibBrowser\Lib\TreeExporter,
	Modules\MibBrowser\Lib\WalkImporter,
	Modules\MibBrowser\Lib\XlsxWriter,
	Modules\MibBrowser\Lib\YamlWriter,
	Modules\MibBrowser\Lib\ZabbixXmlWriter,
	Modules\MibBrowser\Lib\ZipWriter;

// The expectations below are written for the French catalog; English and the other catalogs are checked at the end.
I18n::setLocale('fr_FR');

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

/* Connection diagnosis (messages captured from Zabbix 8.0 server) --------------- */

$cases = [
	["cannot retrieve OID: '.1.3.6.1.2.1.1.3.0' from [[127.0.0.1]:161]: timed out", 2, 'timeout'],
	["cannot resolve address [[nonexistent.invalid]:161]: timed out: nodename nor servname provided, or not known", 2, 'dns'],
	['Cannot connect to "127.0.0.1:161": Unknown user name.', 3, 'v3_user'],
	['Cannot connect to "127.0.0.1:161": Authentication failure (incorrect password, community or key).', 3, 'v3_auth'],
	["Probe successful, cannot retrieve OID: '.1.3.6.1.2.1.1.3.0' from [[127.0.0.1]:161]: timed out", 3, 'v3_priv'],
	['Cannot connect to "127.0.0.1:161": Unsupported security level.', 3, 'v3_level'],
	["Connection to Zabbix server \"127.0.0.1:10051\" refused. Possible reasons:", 2, 'server'],
	['policy:SNMPv2c', 2, 'policy'],
	['secret:community', 2, 'secret'],
	['No Such Object available on this agent at this OID', 2, 'view'],
	['Failed to validate host permissions.', 2, 'sync'],
	['something unexpected', 2, 'unknown']
];

foreach ($cases as [$error, $version, $category]) {
	check('diagnosis: '.$category, ConnectionDiagnosis::diagnose($error, $version)['category'], $category);
}

ok('diagnosis: v2c timeout mentions community first',
	strpos(ConnectionDiagnosis::diagnose('... timed out', 2)['causes'][0], 'Communauté') === 0
);
ok('diagnosis: v3 timeout does not blame community',
	strpos(implode(' ', ConnectionDiagnosis::diagnose('... timed out', 3)['causes']), 'Communauté') === false
);
ok('diagnosis: proxy wording', strpos(ConnectionDiagnosis::diagnose('... timed out', 2, true)['causes'][1], 'le proxy') !== false);
check('vendor: Cisco', ConnectionDiagnosis::vendor('.1.3.6.1.4.1.9.1.1208'), ['enterprise' => 9, 'vendor' => 'Cisco']);
check('vendor: unknown enterprise', ConnectionDiagnosis::vendor('1.3.6.1.4.1.999999.1'), ['enterprise' => 999999, 'vendor' => null]);
check('vendor: not an enterprise OID', ConnectionDiagnosis::vendor('1.3.6.1.2.1.1'), null);

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


/* Import of walk files --------------------------------------------------------------------------------------------- */

$importer = new WalkImporter($repository);
$fixtures = $root.'/tests/fixtures';

// Each Net-SNMP output style gives the same OIDs as the numeric walk of the same device ("-On -Ot -Oe").
foreach (['sym-if' => 'symbolic', 'ox' => 'symbolic -OX', 'iso' => 'iso. without MIBs', 'vacm' => 'string and enum indexes']
		as $name => $label) {
	$records = $importer->import(file_get_contents($fixtures.'/snmpwalk-'.$name.'.txt'));
	$reference = SnmpResult::parseWalk(file_get_contents($fixtures.'/snmpwalk-'.$name.'.num'));

	check('walk import: '.$label.' OIDs', array_column($records, 'oid'), array_column($reference, 'oid'));
	check('walk import: '.$label.' nothing skipped', $importer->getSkippedCount(), 0);
}

check('walk import: format detection', [
	WalkImporter::detect('.1.3.6.1.2.1.1.5.0 = STRING: "x"'), WalkImporter::detect("{\n\"values\": []}"),
	WalkImporter::detect("OID;Name;Type\n"), WalkImporter::detect('<?xml version="1.0"?><snmp_results/>')
], ['snmpwalk', 'json', 'csv', 'xml']);

check('walk import: Net-SNMP values', array_map([$importer, 'normalizeValue'], [
	'Timeticks: (12345) 0:02:03.45', 'INTEGER: up(1)', 'INTEGER: 4096 Bytes', 'Gauge32: 1000000000 bits per second',
	'OID: SNMPv2-MIB::snmpMIB', 'OID: iso.3.6.1.4.1.8072.3.2.10', "Hex-STRING: 00 0C 29 \n3A 4B 5C ",
	'BITS: 40 00 lowerLayerDown(1)', 'STRING: "quoted \"x\""', '""'
]), [
	['type' => 'Timeticks', 'raw' => '12345'], ['type' => 'INTEGER', 'raw' => '1'], ['type' => 'INTEGER', 'raw' => '4096'],
	['type' => 'Gauge32', 'raw' => '1000000000'], ['type' => 'OID', 'raw' => '1.3.6.1.6.3.1'],
	['type' => 'OID', 'raw' => '1.3.6.1.4.1.8072.3.2.10'], ['type' => 'Hex-STRING', 'raw' => '00 0C 29 3A 4B 5C'],
	['type' => 'Hex-STRING', 'raw' => '40 00'], ['type' => 'STRING', 'raw' => 'quoted "x"'], ['type' => 'STRING', 'raw' => '']
]);

check('walk import: OID forms', array_map([$importer, 'resolveOid'], [
	'.1.3.6.1.2.1.1.5.0', 'iso.3.6.1.2.1.1.5.0', 'SNMPv2-MIB::sysName.0', 'sysName.0', 'IF-MIB::ifDescr[3]',
	'SNMP-VIEW-BASED-ACM-MIB::vacmGroupName.3."zbxv3"', 'SNMP-VIEW-BASED-ACM-MIB::vacmAccessStatus."grp"."".3.authPriv',
	'UNKNOWN-MIB::nothing.1'
]), [
	'1.3.6.1.2.1.1.5.0', '1.3.6.1.2.1.1.5.0', '1.3.6.1.2.1.1.5.0', '1.3.6.1.2.1.1.5.0', '1.3.6.1.2.1.2.2.1.2.3',
	'1.3.6.1.6.3.16.1.2.1.3.3.5.122.98.120.118.51', '1.3.6.1.6.3.16.1.4.1.9.3.103.114.112.0.3.3', null
]);

$records = $importer->import("Some header\nSNMPv2-MIB::sysName.0 = STRING: box\nFOO-MIB::fooBar.0 = INTEGER: 1\n");
check('walk import: unknown objects skipped', [count($records), $importer->getSkippedCount()], [1, 2]);

/* Export of results and round trip --------------------------------------------------------------------------------- */

$walk = SnmpResult::parseWalk(file_get_contents($fixtures.'/walk-iftable.txt'));
$rows = SnmpResult::buildRows($walk, $repository);
$expected = array_map(static fn($r) => [$r['oid'], $r['type'], $r['raw']], $walk);

foreach (['json', 'csv', 'xml', 'snmpwalk'] as $format) {
	$file = ResultExporter::export($format, $rows, ['host' => 'sw-1', 'request' => 'walk[1.3.6.1.2.1.2.2]']);
	$back = $importer->import($file['content']);

	check('export: '.$format.' imported back', array_map(static fn($r) => [$r['oid'], $r['raw']], $back),
		array_map(static fn($r) => [$r[0], $r[2]], $expected)
	);
	check('export: '.$format.' same display', array_column(SnmpResult::buildRows($back, $repository), 'value'),
		array_column($rows, 'value')
	);
}

$json = json_decode(ResultExporter::export('json', $rows, ['host' => 'sw-1'])['content'], true);
check('export: json metadata', [$json['host'], count($json['values']), array_keys($json['values'][0])],
	['sw-1', count($rows), ['oid', 'name', 'type', 'value', 'raw', 'snmp_type']]
);
check('export: snmpwalk line', ResultExporter::snmpwalkValue(['oid' => '1', 'type' => 'TimeTicks', 'raw' => '42', 'snmp_type' => '']),
	'Timeticks: 42'
);

$xml = new DOMDocument();
check('export: xml well-formed', $xml->loadXML(ResultExporter::export('xml', $rows)['content']), true);

/* ZIP, archives and Excel ------------------------------------------------------------------------------------------- */

$zip = new ZipWriter();
$zip->add('dir/A.mib', str_repeat("A-MIB DEFINITIONS ::= BEGIN\n", 50));
$zip->add('été.txt', 'small');
$reader = new ArchiveReader(1048576, 10485760);
check('zip: round trip', $reader->read($zip->build()), [
	'dir/A.mib' => str_repeat("A-MIB DEFINITIONS ::= BEGIN\n", 50), 'été.txt' => 'small'
]);

foreach (['acme-mibs.zip', 'acme-mibs.tar.gz'] as $archive) {
	$files = (new ArchiveReader(1048576, 10485760))->read(file_get_contents($fixtures.'/'.$archive), $archive);
	ksort($files);
	check('archive: '.$archive, array_keys($files), [
		'acme/ACME-SMI.my', 'acme/ACME-SYSTEM-MIB.mib', 'acme/README.txt', 'acme/__MACOSX/._ACME-SMI.my',
		'acme/docs/manual.pdf'
	]);
}

$small = new ArchiveReader(100, 1000);
$files = $small->read($zip->build());
check('archive: size limit', [array_keys($files), $small->getWarnings()[0]['reason'] ?? ''], [['été.txt'], 'too_large']);
check('archive: not an archive', [ArchiveReader::detect('plain text'), (new ArchiveReader(10, 10))->read('plain')],
	[null, null]
);
check('archive: single .gz', (new ArchiveReader(1000, 1000))->read(gzencode('walk data'), 'switch.txt.gz'),
	['switch.txt' => 'walk data']
);

$xlsx = (new ArchiveReader(10485760, 10485760))->read(ResultExporter::export('xlsx', $rows, ['host' => 'sw-1'])['content']);
check('xlsx: parts', array_keys($xlsx), ['[Content_Types].xml', '_rels/.rels', 'docProps/core.xml', 'docProps/app.xml',
	'xl/worksheets/sheet1.xml', 'xl/worksheets/sheet2.xml', 'xl/workbook.xml', 'xl/_rels/workbook.xml.rels',
	'xl/styles.xml'
]);
$sheet = new DOMDocument();
check('xlsx: sheet well-formed', $sheet->loadXML($xlsx['xl/worksheets/sheet1.xml']), true);
check('xlsx: rows', $sheet->getElementsByTagName('row')->length, count($rows) + 1);
check('xlsx: column names', [XlsxWriter::column(0), XlsxWriter::column(25), XlsxWriter::column(26), XlsxWriter::column(701)],
	['A', 'Z', 'AA', 'ZZ']
);
check('xlsx: XML escaping', XlsxWriter::esc("a<b>&\x01\"c"), 'a&lt;b&gt;&amp;&quot;c');

/* Templates in XML and branch export -------------------------------------------------------------------------------- */

$builder = new TemplateBuilder($repository, ['template' => 'IF test']);
$export = $builder->build(['1.3.6.1.2.1.2.2']);
$dom = new DOMDocument();
check('template xml: well-formed', $dom->loadXML(ZabbixXmlWriter::write($export)), true);
$xpath = new DOMXPath($dom);
check('template xml: layout', [
	$xpath->evaluate('string(/zabbix_export/version)'),
	$xpath->query('/zabbix_export/templates/template/items/item')->length,
	$xpath->query('/zabbix_export/templates/template/discovery_rules/discovery_rule/item_prototypes/item_prototype')->length,
	$xpath->query('//item_prototype/preprocessing/step/parameters/parameter')->length > 0,
	$xpath->query('/zabbix_export/templates/template/valuemaps/valuemap/mappings/mapping')->length > 0,
	$xpath->evaluate('string(/zabbix_export/templates/template/groups/group/name)')
], [
	'8.0', count($export['zabbix_export']['templates'][0]['items']),
	count($export['zabbix_export']['templates'][0]['discovery_rules'][0]['item_prototypes']), true, true,
	'Templates/SNMP'
]);

$columns = ['1.3.6.1.2.1.2.2.1.2', '1.3.6.1.2.1.2.2.1.8'];
$export = (new TemplateBuilder($repository, ['template' => 'IF test']))->build(['1.3.6.1.2.1.2.2.1'], $columns);
check('template: columns of an imported walk only',
	count($export['zabbix_export']['templates'][0]['discovery_rules'][0]['item_prototypes']), 2
);

$tree = TreeExporter::export('csv', $repository->getSubtree('1.3.6.1.2.1.2.2'), 'IF-MIB-ifTable');
$lines = explode("\r\n", trim($tree['content']));
check('tree export: csv', [$tree['filename'], count($lines), substr($lines[0], 3, 12)],
	['mib-IF-MIB-ifTable.csv', count($repository->getSubtree('1.3.6.1.2.1.2.2')) + 1, 'oid;module;n']
);
$tree = json_decode(TreeExporter::export('json', $repository->getModuleNodes('IF-MIB'), 'IF-MIB')['content'], true);
$status = array_values(array_filter($tree['objects'], static fn($o) => $o['name'] === 'ifOperStatus'))[0];
check('tree export: json', [$tree['root'], $status['oid'], $status['kind'], substr($status['enum'], 0, 13)],
	['IF-MIB', '1.3.6.1.2.1.2.2.1.8', 'column', 'up(1), down(2']
);

// Cleanup.
array_map('unlink', glob($user_dir.'/*'));
@rmdir($user_dir);
array_map('unlink', glob($cache.'/*'));
@rmdir($cache);

// --- Translations ---------------------------------------------------------------------------------------------------

I18n::setLocale('en_US');
check('i18n: English source', SnmpResult::format(['type' => '', 'raw' => '8640123'], $repository->getNode('1.3.6.1.2.1.1.3'))['value'],
	'1d 00:00:01.23 (8640123)'
);
I18n::setLocale('en_GB');
check('i18n: locale without catalog falls back to English', I18n::t('Test connection'), 'Test connection');
I18n::setLocale('de_DE');
check('i18n: German', I18n::t('Test connection'), 'Verbindung testen');
I18n::setLocale('pt_BR');
check('i18n: exact locale file first (pt_BR)', is_file($root.'/lang/pt_BR.php') && I18n::t('Results') !== 'Results', true);
I18n::setLocale('zz_ZZ');
check('i18n: unknown locale', I18n::s('Source: %1$s', 'x'), 'Source: x');

// Every module text (I18n::t / I18n::s literal) is in every catalog, with the same placeholders.
$keys = [];
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

foreach ($files as $file) {
	$file = (string) $file;

	if (substr($file, -4) !== '.php' || preg_match('#[/\\\\](\.git|tests|lang)[/\\\\]#', $file)) {
		continue;
	}

	$tokens = token_get_all(file_get_contents($file));

	for ($i = 0, $n = count($tokens) - 4; $i < $n; $i++) {
		if (!is_array($tokens[$i]) || $tokens[$i][1] !== 'I18n' || !is_array($tokens[$i + 1])
				|| $tokens[$i + 1][0] !== T_DOUBLE_COLON || !in_array($tokens[$i + 2][1] ?? '', ['t', 's'], true)) {
			continue;
		}

		for ($j = $i + 3; is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE; $j++);

		if ($tokens[$j] !== '(') {
			continue;
		}

		for ($j++; is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE; $j++);

		if (is_array($tokens[$j]) && $tokens[$j][0] === T_CONSTANT_ENCAPSED_STRING) {
			$keys[stripcslashes(substr($tokens[$j][1], 1, -1))] = true;
		}
	}
}

check('i18n: module texts found', count($keys) > 200, true);

$placeholders = static function (string $text): array {
	preg_match_all('/%\d+\$s/', $text, $m);
	$m = array_unique($m[0]);
	sort($m);

	return $m;
};

foreach (glob($root.'/lang/*.php') as $file) {
	$catalog = include $file;
	$name = basename($file, '.php');
	$missing = array_keys(array_diff_key($keys, $catalog));
	$broken = [];

	foreach ($catalog as $source => $translation) {
		if (!is_string($translation) || $translation === '' || $placeholders($source) !== $placeholders($translation)) {
			$broken[] = $source;
		}
	}

	check('i18n: '.$name.' complete', [$missing, $broken], [[], []]);
}

check('i18n: all Zabbix languages', count(glob($root.'/lang/*.php')), 23);

echo ($failures ? "\n$failures/$count tests FAILED\n" : "$count tests passed\n");
exit($failures ? 1 : 0);
