<?php declare(strict_types = 0);
/*
** MIB Browser module for Zabbix 8.0
** Main page view.
**
** @var CView $this
** @var array $data
*/

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
				new CLabel(_('Interface SNMP'), 'mibb-interface'),
				(new CDiv([
					(new CTag('select', true))
						->setId('mibb-interface')
						->addClass('mibb-select')
						->setAttribute('disabled', 'disabled'),
					(new CSpan())->setId('mibb-security-badge')->addClass('mibb-sec')->addStyle('display: none')
				]))->addClass('mibb-interface-row')
			]))->addClass('mibb-field'),
			(new CDiv([
				new CLabel(_('OID ou nom'), 'mibb-oid'),
				(new CTextBox('mibb_oid', $data['oid']))
					->setId('mibb-oid')
					->setAttribute('placeholder', 'IF-MIB::ifTable, sysDescr, 1.3.6.1.2.1.1')
					->setAttribute('autocomplete', 'off')
					->setWidth(ZBX_TEXTAREA_MEDIUM_WIDTH)
			]))->addClass('mibb-field mibb-field-oid'),
			(new CDiv([
				(new CButton('mibb-get', 'Get'))->setId('mibb-get'),
				(new CButton('mibb-walk', 'Walk'))->setId('mibb-walk'),
				(new CButton('mibb-test', _('Tester la connexion')))
					->setId('mibb-test')
					->addClass(ZBX_STYLE_BTN_ALT),
				(new CButton('mibb-credentials-toggle', _('Identifiants')))
					->setId('mibb-credentials-toggle')
					->addClass(ZBX_STYLE_BTN_ALT)
			]))->addClass('mibb-buttons')
		]))->addClass('mibb-query-row')
	)
	->addItem(
		(new CDiv([
			(new CDiv(_('Facultatif : à utiliser seulement si l\'interface utilise des macros secrètes. Les valeurs ne sont pas enregistrées.')))
				->addClass('mibb-hint'),
			(new CDiv())->setId('mibb-credentials-warning')->addClass('mibb-hint-block'),
			(new CDiv([
				new CLabel(_('Communauté'), 'mibb-cred-community'),
				(new CTextBox('community'))->setId('mibb-cred-community')->setAttribute('type', 'password')
					->setAttribute('autocomplete', 'off')
			]))->addClass('mibb-field'),
			(new CDiv([
				new CLabel(_('Nom de sécurité (v3)'), 'mibb-cred-securityname'),
				(new CTextBox('securityname'))->setId('mibb-cred-securityname')->setAttribute('autocomplete', 'off')
			]))->addClass('mibb-field'),
			(new CDiv([
				new CLabel(_('Phrase d\'authentification'), 'mibb-cred-authpassphrase'),
				(new CTextBox('authpassphrase'))->setId('mibb-cred-authpassphrase')->setAttribute('type', 'password')
					->setAttribute('autocomplete', 'new-password')
			]))->addClass('mibb-field'),
			(new CDiv([
				new CLabel(_('Phrase de chiffrement'), 'mibb-cred-privpassphrase'),
				(new CTextBox('privpassphrase'))->setId('mibb-cred-privpassphrase')->setAttribute('type', 'password')
					->setAttribute('autocomplete', 'new-password')
			]))->addClass('mibb-field'),
			(new CDiv([
				new CLabel(_('Contexte'), 'mibb-cred-contextname'),
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
		->setAttribute('placeholder', _('Rechercher un objet (nom ou OID)'))
		->setAttribute('autocomplete', 'off'),
	(new CDiv())->setId('mibb-search-results')->addClass('mibb-search-results')->addStyle('display: none'),
	(new CTag('ul', true))->setId('mibb-tree')->addClass('mibb-tree')
]))->addClass('mibb-tree-panel');

// Main panel with tabs.
$tabs = (new CDiv([
	(new CTag('button', true, _('Résultats')))->setAttribute('type', 'button')->setAttribute('data-tab', 'results')
		->addClass('mibb-tab mibb-tab-active'),
	(new CTag('button', true, _('Détails')))->setAttribute('type', 'button')->setAttribute('data-tab', 'details')
		->addClass('mibb-tab'),
	(new CTag('button', true, _('MIB chargées')))->setAttribute('type', 'button')->setAttribute('data-tab', 'mibs')
		->addClass('mibb-tab'),
	(new CTag('button', true, _('Sécurité SNMP')))->setAttribute('type', 'button')->setAttribute('data-tab', 'security')
		->addClass('mibb-tab')
]))->addClass('mibb-tabs');

$main_panel = (new CDiv([
	$tabs,
	(new CDiv(
		(new CDiv(_('Choisissez un hôte et un OID, puis cliquez sur Get ou Walk. Vous pouvez aussi sélectionner un objet dans l\'arbre.')))
			->addClass('mibb-empty')
	))->setId('mibb-panel-results')->addClass('mibb-panel'),
	(new CDiv(
		(new CDiv(_('Sélectionnez un objet dans l\'arbre MIB.')))->addClass('mibb-empty')
	))->setId('mibb-panel-details')->addClass('mibb-panel')->addStyle('display: none'),
	(new CDiv())->setId('mibb-panel-mibs')->addClass('mibb-panel')->addStyle('display: none'),
	(new CDiv())->setId('mibb-panel-security')->addClass('mibb-panel')->addStyle('display: none')
]))->addClass('mibb-main');

// Item creation dialog (static markup, filled by JS).
$item_dialog = (new CTag('dialog', true, [
	(new CTag('form', true, [
		(new CTag('h3', true, _('Créer un élément SNMP'))),
		(new CDiv())->setId('mibb-item-oid')->addClass('mibb-dialog-oid'),
		(new CDiv([
			new CLabel(_('Créer sur')),
			(new CDiv([
				(new CTag('label', true, [
					(new CInput('radio', 'target', 'host'))->setId('mibb-item-target-host')->setAttribute('checked', 'checked'),
					' ',
					(new CSpan())->setId('mibb-item-target-host-label')
				])),
				(new CTag('label', true, [
					(new CInput('radio', 'target', 'template'))->setId('mibb-item-target-template'),
					' '._('Modèle')
				]))
			]))->addClass('mibb-radios')
		]))->addClass('mibb-field'),
		(new CDiv([
			new CLabel(_('Modèle'), 'mibb_item_templateid_ms'),
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
				' '._('Convertir le compteur en débit par seconde')
			]))->setId('mibb-item-cps-row'),
			(new CTag('label', true, [
				(new CInput('checkbox', 'create_valuemap', '1'))->setId('mibb-item-valuemap')
					->setAttribute('checked', 'checked'),
				' '._('Créer la table de correspondance des valeurs (énumération MIB)')
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
		(new CTag('h3', true, _('Générer un modèle Zabbix'))),
		(new CDiv())->setId('mibb-tpl-roots')->addClass('mibb-dialog-oid'),
		(new CDiv([
			new CLabel(_('Nom du modèle'), 'mibb-tpl-name'),
			(new CTextBox('template'))->setId('mibb-tpl-name')->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
		]))->addClass('mibb-field'),
		(new CDiv([
			new CLabel(_('Groupe de modèles'), 'mibb-tpl-group'),
			(new CTextBox('group', 'Templates/SNMP'))->setId('mibb-tpl-group')->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
		]))->addClass('mibb-field'),
		(new CDiv([
			(new CDiv([
				new CLabel(_('Préfixe des clés'), 'mibb-tpl-prefix'),
				(new CTextBox('key_prefix'))->setId('mibb-tpl-prefix')->setWidth(ZBX_TEXTAREA_SMALL_WIDTH)
			]))->addClass('mibb-field'),
			(new CDiv([
				new CLabel(_('Intervalle (scalaires)'), 'mibb-tpl-delay'),
				(new CTextBox('delay', '1m'))->setId('mibb-tpl-delay')->setWidth(ZBX_TEXTAREA_TINY_WIDTH)
			]))->addClass('mibb-field'),
			(new CDiv([
				new CLabel(_('Intervalle (tables)'), 'mibb-tpl-walk-delay'),
				(new CTextBox('walk_delay', '1m'))->setId('mibb-tpl-walk-delay')->setWidth(ZBX_TEXTAREA_TINY_WIDTH)
			]))->addClass('mibb-field')
		]))->addClass('mibb-inline'),
		(new CDiv([
			(new CTag('label', true, [
				(new CInput('checkbox', 'include_scalars', '1'))->setId('mibb-tpl-scalars')->setAttribute('checked', 'checked'),
				' '._('Éléments pour les objets scalaires (get[])')
			])),
			(new CTag('label', true, [
				(new CInput('checkbox', 'include_tables', '1'))->setId('mibb-tpl-tables')->setAttribute('checked', 'checked'),
				' '._('Découverte LLD pour les tables (walk[])')
			])),
			(new CTag('label', true, [
				(new CInput('checkbox', 'change_per_second', '1'))->setId('mibb-tpl-cps')->setAttribute('checked', 'checked'),
				' '._('Convertir les compteurs en débit par seconde')
			])),
			(new CTag('label', true, [
				(new CInput('checkbox', 'link_host', '1'))->setId('mibb-tpl-link'),
				' ',
				(new CSpan(_('Lier le modèle à l\'hôte sélectionné après l\'import')))->setId('mibb-tpl-link-label')
			]))->setId('mibb-tpl-link-row')
		]))->addClass('mibb-checks'),
		(new CDiv())->setId('mibb-tpl-preview')->addClass('mibb-tpl-preview'),
		(new CDiv())->setId('mibb-tpl-message')->addClass('mibb-dialog-message'),
		(new CDiv([
			(new CButton('mibb-tpl-preview-btn', _('Aperçu')))->setId('mibb-tpl-preview-btn')->addClass(ZBX_STYLE_BTN_ALT),
			(new CButton('mibb-tpl-yaml', _('Télécharger YAML')))->setId('mibb-tpl-yaml')->addClass(ZBX_STYLE_BTN_ALT),
			(new CButton('mibb-tpl-import', _('Importer dans Zabbix')))->setId('mibb-tpl-import'),
			(new CButton('mibb-tpl-cancel', _('Close')))->setId('mibb-tpl-cancel')->addClass(ZBX_STYLE_BTN_ALT)
		]))->addClass('mibb-dialog-buttons')
	]))
		->setAttribute('name', 'mibb_tpl_form')
		->setId('mibb-tpl-form')
		->setAttribute('method', 'dialog')
]))->setId('mibb-tpl-dialog')->addClass('mibb-dialog');

$messages = [];

if ($data['storage_problems']) {
	$messages[] = (new CDiv(_s('Le dossier de données du module n\'est pas accessible en écriture par le serveur web : %1$s. Le cache et l\'ajout de MIB sont désactivés.',
		implode(', ', $data['storage_problems'])
	)))->addClass('mibb-warning');
}

(new CHtmlPage())
	->setTitle(_('MIB browser'))
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
