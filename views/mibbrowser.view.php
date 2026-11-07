<?php declare(strict_types = 0);
/*
** MIB Browser module for Zabbix 8.0
** Main page view.
**
** @var CView $this
** @var array $data
*/

use Modules\MibBrowser\Lib\I18n;

$this->includeJsFile('mibbrowser.view.js.php');

$form_name = 'mibb_query';

// Query bar.
$query_form = (new CForm())
	->setName($form_name)
	->setId('mibb-query')
	->addClass('mibb-query')
	->addItem(
		(new CDiv([
			(new CDiv([
				new CLabel(_('Host'), 'mibb_hostid_ms'),
				(new CMultiSelect([
					'name' => 'mibb_hostid',
					'object_name' => 'hosts',
					'multiple' => false,
					'data' => $data['ms_host'],
					'popup' => [
						'parameters' => [
							'srctbl' => 'hosts',
							'srcfld1' => 'hostid',
							'dstfrm' => $form_name,
							'dstfld1' => 'mibb_hostid',
							'real_hosts' => true
						]
					]
				]))->setWidth(ZBX_TEXTAREA_MEDIUM_WIDTH)
			]))->addClass('mibb-field'),
			(new CDiv([
				new CLabel(I18n::t('SNMP interface'), 'mibb-interface'),
				(new CDiv([
					(new CTag('select', true))
						->setId('mibb-interface')
						->addClass('mibb-select')
						->setAttribute('disabled', 'disabled'),
					(new CSpan())->setId('mibb-security-badge')->addClass('mibb-sec')->addStyle('display: none')
				]))->addClass('mibb-interface-row')
			]))->addClass('mibb-field'),
			(new CDiv([
				new CLabel(I18n::t('OID or name'), 'mibb-oid'),
				(new CTextBox('mibb_oid', $data['oid']))
					->setId('mibb-oid')
					->setAttribute('placeholder', 'IF-MIB::ifTable, sysDescr, 1.3.6.1.2.1.1')
					->setAttribute('autocomplete', 'off')
					->setWidth(ZBX_TEXTAREA_MEDIUM_WIDTH)
			]))->addClass('mibb-field mibb-field-oid'),
			(new CDiv([
				(new CButton('mibb-get', 'Get'))->setId('mibb-get'),
				(new CButton('mibb-walk', 'Walk'))->setId('mibb-walk'),
				(new CButton('mibb-test', I18n::t('Test connection')))
					->setId('mibb-test')
					->addClass(ZBX_STYLE_BTN_ALT),
				(new CButton('mibb-credentials-toggle', I18n::t('Credentials')))
					->setId('mibb-credentials-toggle')
					->addClass(ZBX_STYLE_BTN_ALT)
			]))->addClass('mibb-buttons')
		]))->addClass('mibb-query-row')
	)
	->addItem(
		(new CDiv([
			(new CDiv(I18n::t('Optional: use only if the interface uses secret macros. Values are not saved.')))
				->addClass('mibb-hint'),
			(new CDiv())->setId('mibb-credentials-warning')->addClass('mibb-hint-block'),
			(new CDiv([
				new CLabel(I18n::t('Community'), 'mibb-cred-community'),
				(new CTextBox('community'))->setId('mibb-cred-community')->setAttribute('type', 'password')
					->setAttribute('autocomplete', 'off')
			]))->addClass('mibb-field'),
			(new CDiv([
				new CLabel(I18n::t('Security name (v3)'), 'mibb-cred-securityname'),
				(new CTextBox('securityname'))->setId('mibb-cred-securityname')->setAttribute('autocomplete', 'off')
			]))->addClass('mibb-field'),
			(new CDiv([
				new CLabel(I18n::t('Authentication passphrase'), 'mibb-cred-authpassphrase'),
				(new CTextBox('authpassphrase'))->setId('mibb-cred-authpassphrase')->setAttribute('type', 'password')
					->setAttribute('autocomplete', 'new-password')
			]))->addClass('mibb-field'),
			(new CDiv([
				new CLabel(I18n::t('Privacy passphrase'), 'mibb-cred-privpassphrase'),
				(new CTextBox('privpassphrase'))->setId('mibb-cred-privpassphrase')->setAttribute('type', 'password')
					->setAttribute('autocomplete', 'new-password')
			]))->addClass('mibb-field'),
			(new CDiv([
				new CLabel(I18n::t('Context'), 'mibb-cred-contextname'),
				(new CTextBox('contextname'))->setId('mibb-cred-contextname')->setAttribute('autocomplete', 'off')
			]))->addClass('mibb-field')
		]))
			->setId('mibb-credentials')
			->addClass('mibb-credentials')
			->addStyle('display: none')
	);

// Tree panel.
$tree_panel = (new CDiv([
	(new CTextBox('mibb_search'))
		->setId('mibb-search')
		->addClass('mibb-search')
		->setAttribute('placeholder', I18n::t('Search an object (name or OID)'))
		->setAttribute('autocomplete', 'off'),
	(new CDiv())->setId('mibb-search-results')->addClass('mibb-search-results')->addStyle('display: none'),
	(new CTag('ul', true))->setId('mibb-tree')->addClass('mibb-tree')
]))->addClass('mibb-tree-panel');

// Main panel with tabs.
$tabs = (new CDiv([
	(new CTag('button', true, I18n::t('Results')))->setAttribute('type', 'button')->setAttribute('data-tab', 'results')
		->addClass('mibb-tab mibb-tab-active'),
	(new CTag('button', true, I18n::t('Details')))->setAttribute('type', 'button')->setAttribute('data-tab', 'details')
		->addClass('mibb-tab'),
	(new CTag('button', true, I18n::t('Loaded MIBs')))->setAttribute('type', 'button')->setAttribute('data-tab', 'mibs')
		->addClass('mibb-tab'),
	(new CTag('button', true, I18n::t('SNMP security')))->setAttribute('type', 'button')->setAttribute('data-tab', 'security')
		->addClass('mibb-tab')
]))->addClass('mibb-tabs');

$main_panel = (new CDiv([
	$tabs,
	(new CDiv(
		(new CDiv(I18n::t('Choose a host and an OID, then click Get or Walk. You can also select an object in the tree.')))
			->addClass('mibb-empty')
	))->setId('mibb-panel-results')->addClass('mibb-panel'),
	(new CDiv(
		(new CDiv(I18n::t('Select an object in the MIB tree.')))->addClass('mibb-empty')
	))->setId('mibb-panel-details')->addClass('mibb-panel')->addStyle('display: none'),
	(new CDiv())->setId('mibb-panel-mibs')->addClass('mibb-panel')->addStyle('display: none'),
	(new CDiv())->setId('mibb-panel-security')->addClass('mibb-panel')->addStyle('display: none')
]))->addClass('mibb-main');

// Item creation dialog (static markup, filled by JS).
$item_dialog = (new CTag('dialog', true, [
	(new CTag('form', true, [
		(new CTag('h3', true, I18n::t('Create SNMP item'))),
		(new CDiv())->setId('mibb-item-oid')->addClass('mibb-dialog-oid'),
		(new CDiv([
			new CLabel(I18n::t('Create on')),
			(new CDiv([
				(new CTag('label', true, [
					(new CInput('radio', 'target', 'host'))->setId('mibb-item-target-host')->setAttribute('checked', 'checked'),
					' ',
					(new CSpan())->setId('mibb-item-target-host-label')
				])),
				(new CTag('label', true, [
					(new CInput('radio', 'target', 'template'))->setId('mibb-item-target-template'),
					' '.I18n::t('Template')
				]))
			]))->addClass('mibb-radios')
		]))->addClass('mibb-field'),
		(new CDiv([
			new CLabel(I18n::t('Template'), 'mibb_item_templateid_ms'),
			(new CMultiSelect([
				'name' => 'mibb_item_templateid',
				'object_name' => 'templates',
				'multiple' => false,
				'popup' => [
					'parameters' => [
						'srctbl' => 'templates',
						'srcfld1' => 'hostid',
						'dstfrm' => 'mibb_item_form',
						'dstfld1' => 'mibb_item_templateid'
					]
				]
			]))->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
		]))->setId('mibb-item-template-row')->addClass('mibb-field')->addStyle('display: none'),
		(new CDiv([
			new CLabel(_('Name'), 'mibb-item-name'),
			(new CTextBox('name'))->setId('mibb-item-name')->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
		]))->addClass('mibb-field'),
		(new CDiv([
			new CLabel(_('Key'), 'mibb-item-key'),
			(new CTextBox('key'))->setId('mibb-item-key')->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
		]))->addClass('mibb-field'),
		(new CDiv([
			new CLabel(_('Type of information'), 'mibb-item-value-type'),
			(new CTag('select', true, [
				(new CTag('option', true, _('Numeric (unsigned)')))->setAttribute('value', ITEM_VALUE_TYPE_UINT64),
				(new CTag('option', true, _('Numeric (float)')))->setAttribute('value', ITEM_VALUE_TYPE_FLOAT),
				(new CTag('option', true, _('Character')))->setAttribute('value', ITEM_VALUE_TYPE_STR),
				(new CTag('option', true, _('Text')))->setAttribute('value', ITEM_VALUE_TYPE_TEXT)
			]))->setId('mibb-item-value-type')->addClass('mibb-select')
		]))->addClass('mibb-field'),
		(new CDiv([
			(new CDiv([
				new CLabel(_('Update interval'), 'mibb-item-delay'),
				(new CTextBox('delay', '1m'))->setId('mibb-item-delay')->setWidth(ZBX_TEXTAREA_TINY_WIDTH)
			]))->addClass('mibb-field'),
			(new CDiv([
				new CLabel(_('Units'), 'mibb-item-units'),
				(new CTextBox('units'))->setId('mibb-item-units')->setWidth(ZBX_TEXTAREA_TINY_WIDTH)
			]))->addClass('mibb-field')
		]))->addClass('mibb-inline'),
		(new CDiv([
			(new CTag('label', true, [
				(new CInput('checkbox', 'change_per_second', '1'))->setId('mibb-item-cps'),
				' '.I18n::t('Convert the counter to a per-second rate')
			]))->setId('mibb-item-cps-row'),
			(new CTag('label', true, [
				(new CInput('checkbox', 'create_valuemap', '1'))->setId('mibb-item-valuemap')
					->setAttribute('checked', 'checked'),
				' '.I18n::t('Create the value mapping (MIB enumeration)')
			]))->setId('mibb-item-valuemap-row')
		]))->addClass('mibb-checks'),
		(new CDiv())->setId('mibb-item-message')->addClass('mibb-dialog-message'),
		(new CDiv([
			(new CButton('mibb-item-submit', _('Add')))->setId('mibb-item-submit'),
			(new CButton('mibb-item-cancel', _('Cancel')))->setId('mibb-item-cancel')->addClass(ZBX_STYLE_BTN_ALT)
		]))->addClass('mibb-dialog-buttons')
	]))
		->setAttribute('name', 'mibb_item_form')
		->setId('mibb-item-form')
		->setAttribute('method', 'dialog')
]))->setId('mibb-item-dialog')->addClass('mibb-dialog');

// Template generation dialog.
$template_dialog = (new CTag('dialog', true, [
	(new CTag('form', true, [
		(new CTag('h3', true, I18n::t('Generate a Zabbix template'))),
		(new CDiv())->setId('mibb-tpl-roots')->addClass('mibb-dialog-oid'),
		(new CDiv([
			new CLabel(I18n::t('Template name'), 'mibb-tpl-name'),
			(new CTextBox('template'))->setId('mibb-tpl-name')->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
		]))->addClass('mibb-field'),
		(new CDiv([
			new CLabel(I18n::t('Template group'), 'mibb-tpl-group'),
			(new CTextBox('group', 'Templates/SNMP'))->setId('mibb-tpl-group')->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
		]))->addClass('mibb-field'),
		(new CDiv([
			(new CDiv([
				new CLabel(I18n::t('Key prefix'), 'mibb-tpl-prefix'),
				(new CTextBox('key_prefix'))->setId('mibb-tpl-prefix')->setWidth(ZBX_TEXTAREA_SMALL_WIDTH)
			]))->addClass('mibb-field'),
			(new CDiv([
				new CLabel(I18n::t('Interval (scalars)'), 'mibb-tpl-delay'),
				(new CTextBox('delay', '1m'))->setId('mibb-tpl-delay')->setWidth(ZBX_TEXTAREA_TINY_WIDTH)
			]))->addClass('mibb-field'),
			(new CDiv([
				new CLabel(I18n::t('Interval (tables)'), 'mibb-tpl-walk-delay'),
				(new CTextBox('walk_delay', '1m'))->setId('mibb-tpl-walk-delay')->setWidth(ZBX_TEXTAREA_TINY_WIDTH)
			]))->addClass('mibb-field')
		]))->addClass('mibb-inline'),
		(new CDiv([
			(new CTag('label', true, [
				(new CInput('checkbox', 'include_scalars', '1'))->setId('mibb-tpl-scalars')->setAttribute('checked', 'checked'),
				' '.I18n::t('Items for scalar objects (get[])')
			])),
			(new CTag('label', true, [
				(new CInput('checkbox', 'include_tables', '1'))->setId('mibb-tpl-tables')->setAttribute('checked', 'checked'),
				' '.I18n::t('LLD discovery for tables (walk[])')
			])),
			(new CTag('label', true, [
				(new CInput('checkbox', 'change_per_second', '1'))->setId('mibb-tpl-cps')->setAttribute('checked', 'checked'),
				' '.I18n::t('Convert counters to per-second rates')
			])),
			(new CTag('label', true, [
				(new CInput('checkbox', 'link_host', '1'))->setId('mibb-tpl-link'),
				' ',
				(new CSpan(I18n::t('Link the template to the selected host after import')))->setId('mibb-tpl-link-label')
			]))->setId('mibb-tpl-link-row')
		]))->addClass('mibb-checks'),
		(new CDiv())->setId('mibb-tpl-preview')->addClass('mibb-tpl-preview'),
		(new CDiv())->setId('mibb-tpl-message')->addClass('mibb-dialog-message'),
		(new CDiv([
			(new CButton('mibb-tpl-preview-btn', I18n::t('Preview')))->setId('mibb-tpl-preview-btn')->addClass(ZBX_STYLE_BTN_ALT),
			(new CButton('mibb-tpl-yaml', I18n::t('Download YAML')))->setId('mibb-tpl-yaml')->addClass(ZBX_STYLE_BTN_ALT),
			(new CButton('mibb-tpl-import', I18n::t('Import into Zabbix')))->setId('mibb-tpl-import'),
			(new CButton('mibb-tpl-cancel', _('Close')))->setId('mibb-tpl-cancel')->addClass(ZBX_STYLE_BTN_ALT)
		]))->addClass('mibb-dialog-buttons')
	]))
		->setAttribute('name', 'mibb_tpl_form')
		->setId('mibb-tpl-form')
		->setAttribute('method', 'dialog')
]))->setId('mibb-tpl-dialog')->addClass('mibb-dialog');

$messages = [];

if ($data['storage_problems']) {
	$messages[] = (new CDiv(I18n::s('The module data directory is not writable by the web server: %1$s. The cache and MIB upload are disabled.',
		implode(', ', $data['storage_problems'])
	)))->addClass('mibb-warning');
}

(new CHtmlPage())
	->setTitle(I18n::t('MIB browser'))
	->addItem($messages)
	->addItem($query_form)
	->addItem((new CDiv([$tree_panel, $main_panel]))->addClass('mibb-layout'))
	->addItem($item_dialog)
	->addItem($template_dialog)
	->show();

(new CScriptTag('
	mibb_view.init('.json_encode([
		'tokens' => $data['tokens'],
		'csrf_field' => CSRF_TOKEN_NAME,
		'oid' => $data['oid'],
		'is_super_admin' => $data['is_super_admin'],
		'max_upload_size' => $data['max_upload_size'],
		'is_https' => $data['is_https']
	]).');
'))
	->setOnDocumentReady()
	->show();
