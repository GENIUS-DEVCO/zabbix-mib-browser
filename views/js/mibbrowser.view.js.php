<?php declare(strict_types = 0);
/*
** MIB Browser module for Zabbix 8.0
** Client side of the MIB browser page.
**
** @var CView $this
*/

use Modules\MibBrowser\Lib\I18n;
?>

<script>
	const mibb_view = new class {

		KIND_LABELS = {
			node: <?= json_encode(I18n::t('Node')) ?>,
			module: <?= json_encode(_('Module')) ?>,
			table: <?= json_encode(I18n::t('Table')) ?>,
			row: <?= json_encode(I18n::t('Table row')) ?>,
			column: <?= json_encode(I18n::t('Column')) ?>,
			scalar: <?= json_encode(I18n::t('Scalar')) ?>,
			notification: <?= json_encode(I18n::t('Notification (trap)')) ?>,
			group: <?= json_encode(I18n::t('Group')) ?>,
			compliance: <?= json_encode(I18n::t('Compliance')) ?>
		};

		KIND_BADGES = {
			node: '', module: 'M', table: 'T', row: 'R', column: 'C', scalar: 'S', notification: 'N', group: 'G',
			compliance: 'G'
		};

		SECURITY_LABELS = {
			encrypted: <?= json_encode(I18n::t('Encrypted')) ?>,
			weak: <?= json_encode(I18n::t('Encrypted (weak algorithms)')) ?>,
			auth_only: <?= json_encode(I18n::t('Authenticated, not encrypted')) ?>,
			none: <?= json_encode(I18n::t('Not encrypted')) ?>
		};

		init({tokens, csrf_field, oid, is_super_admin, max_upload_size, is_https}) {
			this.is_https = is_https;
			this.policy = 'warn';
			this.tokens = tokens;
			this.csrf_field = csrf_field;
			this.is_super_admin = is_super_admin;
			this.max_upload_size = max_upload_size;
			this.host = null;
			this.interfaces = [];
			this.selected_oid = null;
			this.selected_node = null;
			this.last_rows = [];
			this.item_row = null;
			this.template_roots = [];

			this.$ = (id) => document.getElementById(id);

			this.initTabs();
			this.initQuery();
			this.initTree();
			this.initSearch();
			this.initItemDialog();
			this.initTemplateDialog();

			this.loadRoots();

			const host_data = jQuery('#mibb_hostid').multiSelect('getData');

			if (host_data.length) {
				this.onHostChange();
			}

			if (oid !== '') {
				this.$('mibb-oid').value = oid;
				this.revealInTree(oid);
			}
		}

		/* ------------------------------------------------------------------ */
		/* Helpers                                                              */
		/* ------------------------------------------------------------------ */

		el(tag, attrs = {}, ...children) {
			const node = document.createElement(tag);

			for (const [name, value] of Object.entries(attrs)) {
				if (value === null || value === undefined || value === false) {
					continue;
				}

				if (name === 'class') {
					node.className = value;
				}
				else if (name === 'text') {
					node.textContent = value;
				}
				else if (name.startsWith('on')) {
					node.addEventListener(name.substring(2), value);
				}
				else if (name === 'dataset') {
					Object.assign(node.dataset, value);
				}
				else {
					node.setAttribute(name, value === true ? '' : value);
				}
			}

			for (const child of children.flat()) {
				if (child === null || child === undefined || child === false) {
					continue;
				}

				node.append(child instanceof Node ? child : document.createTextNode(String(child)));
			}

			return node;
		}

		/**
		 * Fill positional placeholders (%1$s, %2$s...) of a translated format.
		 */
		fmt(format, ...args) {
			return format.replace(/%(\d+)\$s/g, (match, n) => String(args[n - 1] ?? ''));
		}

		async request(action, params = {}, files = null) {
			const body = new FormData();

			body.append(this.csrf_field, this.tokens[action]);

			const add = (name, value) => {
				if (Array.isArray(value)) {
					value.forEach((v) => add(name + '[]', v));
				}
				else if (value !== null && typeof value === 'object') {
					Object.entries(value).forEach(([k, v]) => add(name + '[' + k + ']', v));
				}
				else if (value !== null && value !== undefined) {
					body.append(name, value);
				}
			};

			Object.entries(params).forEach(([name, value]) => add(name, value));

			if (files !== null) {
				for (const file of files) {
					body.append('mibs[]', file, file.name);
				}
			}

			let response;

			try {
				response = await fetch('zabbix.php?action=' + encodeURIComponent(action), {method: 'POST', body});
			}
			catch (e) {
				return {error: <?= json_encode(I18n::t('Network error.')) ?> + ' ' + e.message};
			}

			const text = await response.text();

			try {
				return JSON.parse(text);
			}
			catch (e) {
				return {error: <?= json_encode(I18n::t('Unexpected server response.')) ?> + ' (HTTP ' + response.status + ')'};
			}
		}

		message(container, text, type = 'error') {
			container.innerHTML = '';

			if (text) {
				container.append(this.el('div', {class: 'mibb-msg mibb-msg-' + type, text}));
			}
		}

		kindBadge(kind) {
			const badge = this.KIND_BADGES[kind] ?? '';

			return this.el('span', {
				class: 'mibb-badge mibb-badge-' + kind,
				title: this.KIND_LABELS[kind] ?? kind,
				text: badge
			});
		}

		/* ------------------------------------------------------------------ */
		/* Tabs                                                                 */
		/* ------------------------------------------------------------------ */

		initTabs() {
			document.querySelectorAll('.mibb-tab').forEach((tab) => {
				tab.addEventListener('click', () => this.showTab(tab.dataset.tab));
			});
		}

		showTab(name) {
			document.querySelectorAll('.mibb-tab').forEach((tab) => {
				tab.classList.toggle('mibb-tab-active', tab.dataset.tab === name);
			});

			for (const panel of ['results', 'details', 'mibs', 'security']) {
				this.$('mibb-panel-' + panel).style.display = panel === name ? '' : 'none';
			}

			if (name === 'mibs' && !this.mibs_loaded) {
				this.loadMibs();
			}

			if (name === 'security') {
				this.loadSecurity();
			}
		}

		/* ------------------------------------------------------------------ */
		/* Host / query                                                          */
		/* ------------------------------------------------------------------ */

		initQuery() {
			jQuery('#mibb_hostid').on('change', () => this.onHostChange());

			this.$('mibb-get').addEventListener('click', () => this.runQuery('get'));
			this.$('mibb-walk').addEventListener('click', () => this.runQuery('walk'));
			this.$('mibb-test').addEventListener('click', () => this.testConnection());

			this.$('mibb-oid').addEventListener('keydown', (e) => {
				if (e.key === 'Enter') {
					e.preventDefault();
					this.runQuery('walk');
				}
			});

			this.$('mibb-query').addEventListener('submit', (e) => e.preventDefault());

			this.$('mibb-credentials-toggle').addEventListener('click', () => {
				const panel = this.$('mibb-credentials');
				panel.style.display = panel.style.display === 'none' ? '' : 'none';
			});

			if (!this.is_https && location.protocol !== 'https:') {
				this.$('mibb-credentials-warning').append(this.el('div', {class: 'mibb-msg mibb-msg-warning',
					text: <?= json_encode(I18n::t('This page is not served over HTTPS: credentials typed here cross the network in clear text between your browser and Zabbix. Enable HTTPS on the web interface before using them.')) ?>
				}));
			}

			this.$('mibb-interface').addEventListener('change', () => this.updateSecurityBadge());
		}

		async onHostChange() {
			const data = jQuery('#mibb_hostid').multiSelect('getData');
			const select = this.$('mibb-interface');

			select.innerHTML = '';
			select.disabled = true;
			this.host = null;
			this.interfaces = [];
			this.updateSecurityBadge();

			if (!data.length) {
				return;
			}

			const result = await this.request('mibbrowser.interfaces', {hostid: data[0].id});

			if (result.error) {
				this.message(this.$('mibb-panel-results'), result.error);
				this.showTab('results');

				return;
			}

			this.host = result.host;
			this.interfaces = result.interfaces;
			this.policy = result.policy;

			if (!result.interfaces.length) {
				select.append(this.el('option', {value: '', text: <?= json_encode(I18n::t('No SNMP interface')) ?>}));

				return;
			}

			// Interfaces come sorted from the most secure one, which is selected by default.
			for (const iface of result.interfaces) {
				select.append(this.el('option', {value: iface.interfaceid, text: iface.label}));
			}

			select.disabled = false;
			this.updateSecurityBadge();
		}

		currentInterface() {
			const id = this.$('mibb-interface').value;

			return this.interfaces.find((iface) => iface.interfaceid === id) ?? null;
		}

		securityBadge(security) {
			return this.el('span', {
				class: 'mibb-sec mibb-sec-' + security.level,
				title: security.summary + (security.issues.length ? '\n' + security.issues.join('\n') : ''),
				text: (security.encrypted ? '🔒 ' : '⚠ ') + this.SECURITY_LABELS[security.level]
			});
		}

		updateSecurityBadge() {
			const badge = this.$('mibb-security-badge');
			const iface = this.currentInterface();

			if (iface === null) {
				badge.style.display = 'none';

				return;
			}

			const security = iface.security;
			const blocked = this.policy === 'require_encryption' && !security.encrypted;

			badge.className = 'mibb-sec mibb-sec-' + security.level;
			badge.textContent = (security.encrypted ? '🔒 ' : '⚠ ') + this.SECURITY_LABELS[security.level]
				+ (blocked ? ' — ' + <?= json_encode(I18n::t('blocked by the policy')) ?> : '');
			badge.title = security.summary + (security.issues.length ? '\n' + security.issues.join('\n') : '');
			badge.style.display = '';
		}

		credentials() {
			const overrides = {};

			for (const field of ['community', 'securityname', 'authpassphrase', 'privpassphrase', 'contextname']) {
				const value = this.$('mibb-cred-' + field).value;

				if (value !== '') {
					overrides[field] = value;
				}
			}

			return overrides;
		}

		async runQuery(operation, oid = null) {
			const panel = this.$('mibb-panel-results');

			if (oid !== null) {
				this.$('mibb-oid').value = oid;
			}

			oid = this.$('mibb-oid').value.trim();

			this.showTab('results');

			if (this.host === null || this.$('mibb-interface').value === '') {
				this.message(panel, <?= json_encode(I18n::t('First choose a host with an SNMP interface.')) ?>);

				return;
			}

			if (oid === '') {
				this.message(panel, <?= json_encode(I18n::t('Enter an OID or a MIB object name.')) ?>);

				return;
			}

			panel.innerHTML = '';
			panel.append(this.el('div', {class: 'mibb-loading',
				text: (operation === 'walk' ? 'Walk ' : 'Get ') + oid + ' …'
			}));

			this.setBusy(true);

			const result = await this.request('mibbrowser.query', {
				hostid: this.host.hostid,
				interfaceid: this.$('mibb-interface').value,
				oid,
				operation,
				overrides: this.credentials()
			});

			this.setBusy(false);

			if (result.error) {
				this.message(panel, result.error);

				if (result.need_credentials) {
					this.$('mibb-credentials').style.display = '';
				}

				return;
			}

			this.renderResults(result);
		}

		async testConnection() {
			const panel = this.$('mibb-panel-results');

			this.showTab('results');

			if (this.host === null || this.$('mibb-interface').value === '') {
				this.message(panel, <?= json_encode(I18n::t('First choose a host with an SNMP interface.')) ?>);

				return;
			}

			const iface = this.currentInterface();

			panel.innerHTML = '';
			panel.append(this.el('div', {class: 'mibb-loading',
				text: <?= json_encode(I18n::t('Testing the SNMP connection')) ?> + ' — ' + (iface ? iface.label : '') + ' …'
			}));

			this.setBusy(true);

			const result = await this.request('mibbrowser.test', {
				hostid: this.host.hostid,
				interfaceid: this.$('mibb-interface').value,
				overrides: this.credentials()
			});

			this.setBusy(false);
			panel.innerHTML = '';

			if (result.error && result.diagnosis === undefined) {
				this.message(panel, result.error);

				return;
			}

			const via = result.proxy
				? this.fmt(<?= json_encode(I18n::t('via proxy %1$s')) ?>, result.proxy)
				: <?= json_encode(I18n::t('via Zabbix server')) ?>;
			const meta = [this.host.name, iface ? iface.label : '', via, result.duration + ' s'].filter((v) => v !== '');

			const card = this.el('div', {class: 'mibb-test ' + (result.ok ? 'mibb-test-ok' : 'mibb-test-ko')},
				this.el('div', {class: 'mibb-test-head'},
					this.el('span', {class: 'mibb-test-icon', text: result.ok ? '✔' : '✖'}),
					this.el('div', {},
						this.el('div', {class: 'mibb-test-title', text: result.ok
							? <?= json_encode(I18n::t('SNMP connection successful')) ?>
							: result.diagnosis.title
						}),
						this.el('div', {class: 'mibb-test-meta', text: meta.join(' — ')})
					),
					result.security ? this.securityBadge(result.security) : null
				)
			);

			if (result.ok) {
				const labels = {
					name: <?= json_encode(I18n::t('Name (sysName)')) ?>,
					descr: <?= json_encode(I18n::t('Description (sysDescr)')) ?>,
					vendor: <?= json_encode(I18n::t('Vendor')) ?>,
					object_id: <?= json_encode(I18n::t('Identifier (sysObjectID)')) ?>,
					uptime: <?= json_encode(I18n::t('Up since (sysUpTime)')) ?>,
					location: <?= json_encode(I18n::t('Location (sysLocation)')) ?>,
					contact: <?= json_encode(I18n::t('Contact (sysContact)')) ?>
				};
				const table = this.el('table', {class: 'mibb-details-table'});

				for (const [field, label] of Object.entries(labels)) {
					let value = result.info[field];

					if (value === undefined || value === '') {
						continue;
					}

					if (field === 'object_id' && result.info.object_label) {
						value += '  (' + result.info.object_label + ')';
					}

					table.append(this.el('tr', {}, this.el('th', {text: label}),
						this.el('td', {class: field === 'object_id' ? 'mibb-mono' : 'mibb-value', text: value})
					));
				}

				card.append(table);

				if (result.security && result.security.level !== 'encrypted') {
					card.append(this.el('div', {class: 'mibb-msg ' + (result.security.encrypted ? 'mibb-msg-info' : 'mibb-msg-warning'),
						text: result.security.summary + ' — ' + result.security.issues.join(' ')
					}));
				}

				card.append(this.el('div', {class: 'mibb-detail-buttons'},
					this.el('button', {type: 'button', class: 'btn-alt', text: 'Walk system',
						onclick: () => this.runQuery('walk', 'SNMPv2-MIB::system')
					}),
					this.el('button', {type: 'button', class: 'btn-alt', text: 'Walk interfaces',
						onclick: () => this.runQuery('walk', 'IF-MIB::ifXTable')
					})
				));
			}
			else {
				const causes = this.el('ul', {class: 'mibb-test-list'});
				const actions = this.el('ul', {class: 'mibb-test-list'});

				result.diagnosis.causes.forEach((c) => causes.append(this.el('li', {text: c})));
				result.diagnosis.actions.forEach((a) => actions.append(this.el('li', {text: a})));

				card.append(
					this.el('div', {class: 'mibb-test-section', text: <?= json_encode(I18n::t('Probable causes')) ?>}), causes,
					this.el('div', {class: 'mibb-test-section', text: <?= json_encode(I18n::t('To check')) ?>}), actions,
					this.el('details', {class: 'mibb-test-raw'},
						this.el('summary', {text: <?= json_encode(I18n::t('Detailed error message')) ?>}),
						this.el('pre', {class: 'mibb-mono', text: result.error})
					)
				);

				if (result.need_credentials) {
					this.$('mibb-credentials').style.display = '';
				}
			}

			panel.append(card);
		}

		setBusy(busy) {
			for (const id of ['mibb-get', 'mibb-walk', 'mibb-test']) {
				this.$(id).disabled = busy;
			}
		}

		/* ------------------------------------------------------------------ */
		/* Results                                                              */
		/* ------------------------------------------------------------------ */

		renderResults(result) {
			const panel = this.$('mibb-panel-results');
			panel.innerHTML = '';
			this.last_rows = result.rows;

			const info = [
				result.operation === 'walk' ? 'Walk' : 'Get',
				' ', result.snmp_oid, ' — ',
				this.fmt(<?= json_encode(I18n::t('%1$s value(s)')) ?>, result.total),
				' — ' + result.duration + ' s',
				this.host ? ' — ' + this.host.name + (this.host.proxy ? ' (proxy ' + this.host.proxy + ')' : '') : ''
			].join('');

			const filter = this.el('input', {type: 'text', class: 'mibb-filter',
				placeholder: <?= json_encode(I18n::t('Filter results')) ?>
			});

			const header = this.el('div', {class: 'mibb-results-header'},
				this.el('span', {class: 'mibb-results-info', text: info}),
				filter,
				this.el('button', {type: 'button', class: 'btn-alt', text: 'CSV', onclick: () => this.exportCsv()}),
				result.operation === 'walk' && result.rows.length
					? this.el('button', {type: 'button', class: 'btn-alt',
						text: <?= json_encode(I18n::t('Generate a template')) ?>,
						onclick: () => this.openTemplateDialog([this.subtreeRoot(result.oid)])
					})
					: null
			);

			panel.append(header);

			if (result.security && result.security.level !== 'encrypted') {
				panel.append(this.el('div', {class: 'mibb-msg ' + (result.security.encrypted ? 'mibb-msg-info' : 'mibb-msg-warning')},
					this.securityBadge(result.security), ' ',
					result.security.summary + ' — ' + result.security.issues.join(' ')
						+ (result.security.encrypted ? '' : ' ' + <?= json_encode(I18n::t('Configure SNMPv3 authPriv (SHA-256 / AES) on this device and on the host interface.')) ?>)
				));
			}

			if (result.limited) {
				panel.append(this.el('div', {class: 'mibb-msg mibb-msg-warning',
					text: <?= json_encode(I18n::t('Display limited to the first values (module option max_results).')) ?>
				}));
			}

			if (result.truncated) {
				panel.append(this.el('div', {class: 'mibb-msg mibb-msg-warning',
					text: <?= json_encode(I18n::t('Zabbix server truncated the response: walk a smaller subtree.')) ?>
				}));
			}

			if (!result.rows.length) {
				panel.append(this.el('div', {class: 'mibb-empty',
					text: <?= json_encode(I18n::t('No value (object absent on the device or outside the SNMP view).')) ?>
				}));

				return;
			}

			const tbody = this.el('tbody');
			const table = this.el('table', {class: 'list-table mibb-results-table'},
				this.el('thead', {}, this.el('tr', {},
					this.el('th', {text: 'OID'}),
					this.el('th', {text: <?= json_encode(_('Name')) ?>}),
					this.el('th', {text: <?= json_encode(_('Type')) ?>}),
					this.el('th', {text: <?= json_encode(_('Value')) ?>}),
					this.el('th', {text: ''})
				)),
				tbody
			);

			result.rows.forEach((row, i) => {
				const tr = this.el('tr', {dataset: {i}},
					this.el('td', {class: 'mibb-mono', text: row.oid}),
					this.el('td', {},
						row.node_oid
							? this.el('a', {href: 'javascript:void(0)', class: 'mibb-mono', text: row.label,
								title: <?= json_encode(I18n::t('Show in tree')) ?>,
								onclick: () => this.revealInTree(row.node_oid)
							})
							: this.el('span', {class: 'mibb-mono mibb-unknown', text: row.label})
					),
					this.el('td', {text: row.type}),
					this.el('td', {class: 'mibb-value', text: row.value}),
					this.el('td', {class: 'mibb-actions'},
						row.can_create_item
							? this.el('button', {type: 'button', class: 'btn-link', text: <?= json_encode(I18n::t('+ Item')) ?>,
								onclick: () => this.openItemDialog(row)
							})
							: null
					)
				);
				tbody.append(tr);
			});

			panel.append(table);

			filter.addEventListener('input', () => {
				const term = filter.value.toLowerCase();

				tbody.querySelectorAll('tr').forEach((tr) => {
					tr.style.display = term === '' || tr.textContent.toLowerCase().includes(term) ? '' : 'none';
				});
			});
		}

		subtreeRoot(oid) {
			return oid;
		}

		exportCsv() {
			const escape = (v) => '"' + String(v ?? '').replace(/"/g, '""') + '"';
			const lines = [['OID', 'Name', 'Type', 'Value', 'Raw'].join(';')];

			for (const row of this.last_rows) {
				lines.push([row.oid, row.label, row.type, row.value, row.raw].map(escape).join(';'));
			}

			this.download('snmp-' + Date.now() + '.csv', '﻿' + lines.join('\r\n'), 'text/csv');
		}

		download(filename, content, mime) {
			const url = URL.createObjectURL(new Blob([content], {type: mime + ';charset=utf-8'}));
			const link = this.el('a', {href: url, download: filename});

			document.body.append(link);
			link.click();
			link.remove();
			setTimeout(() => URL.revokeObjectURL(url), 1000);
		}

		/* ------------------------------------------------------------------ */
		/* Tree                                                                 */
		/* ------------------------------------------------------------------ */

		initTree() {
			this.$('mibb-tree').addEventListener('click', (e) => {
				const row = e.target.closest('.mibb-node-row');

				if (!row) {
					return;
				}

				const li = row.parentElement;

				if (e.target.closest('.mibb-toggle')) {
					this.toggleNode(li);
				}
				else {
					this.selectNode(li.dataset.oid);
				}
			});

			this.$('mibb-tree').addEventListener('dblclick', (e) => {
				const row = e.target.closest('.mibb-node-row');

				if (row) {
					this.toggleNode(row.parentElement);
				}
			});
		}

		async loadRoots() {
			const result = await this.request('mibbrowser.tree', {parent: ''});
			const tree = this.$('mibb-tree');

			tree.innerHTML = '';

			if (result.error) {
				tree.append(this.el('li', {class: 'mibb-msg mibb-msg-error', text: result.error}));

				return;
			}

			for (const child of result.children) {
				tree.append(this.treeNode(child));
			}

			// Open iso.org.dod.internet by default.
			const path = ['1', '1.3', '1.3.6', '1.3.6.1'];

			for (const oid of path) {
				const li = tree.querySelector('li[data-oid="' + oid + '"]');

				if (li) {
					await this.expandNode(li);
				}
			}
		}

		treeNode(item) {
			return this.el('li', {class: 'mibb-node', dataset: {oid: item.oid, loaded: '0'}},
				this.el('div', {class: 'mibb-node-row', title: item.oid + (item.module ? ' — ' + item.module : '')},
					this.el('span', {class: 'mibb-toggle' + (item.has_children ? '' : ' mibb-toggle-leaf'),
						text: item.has_children ? '▸' : ''
					}),
					this.kindBadge(item.kind),
					this.el('span', {class: 'mibb-node-name', text: item.name}),
					this.el('span', {class: 'mibb-node-arc', text: '(' + item.oid.split('.').pop() + ')'})
				)
			);
		}

		async expandNode(li, children = null) {
			const toggle = li.querySelector(':scope > .mibb-node-row > .mibb-toggle');

			if (toggle.classList.contains('mibb-toggle-leaf')) {
				return;
			}

			if (li.dataset.loaded !== '1') {
				if (children === null) {
					const result = await this.request('mibbrowser.tree', {parent: li.dataset.oid});
					children = result.children ?? [];
				}

				const ul = this.el('ul');

				for (const child of children) {
					ul.append(this.treeNode(child));
				}

				li.append(ul);
				li.dataset.loaded = '1';
			}

			li.classList.add('mibb-open');
			toggle.textContent = '▾';
		}

		collapseNode(li) {
			li.classList.remove('mibb-open');

			const toggle = li.querySelector(':scope > .mibb-node-row > .mibb-toggle');

			if (!toggle.classList.contains('mibb-toggle-leaf')) {
				toggle.textContent = '▸';
			}
		}

		toggleNode(li) {
			li.classList.contains('mibb-open') ? this.collapseNode(li) : this.expandNode(li);
		}

		async revealInTree(value) {
			const result = await this.request('mibbrowser.tree', {path: value});

			if (result.error) {
				return;
			}

			const tree = this.$('mibb-tree');

			for (const [ancestor, children] of Object.entries(result.levels)) {
				const li = tree.querySelector('li[data-oid="' + ancestor + '"]');

				if (li && ancestor !== result.target) {
					await this.expandNode(li, children);
				}
			}

			this.selectNode(result.target);

			const li = tree.querySelector('li[data-oid="' + result.target + '"]');

			if (li) {
				li.scrollIntoView({block: 'center'});
			}
		}

		async selectNode(oid) {
			const tree = this.$('mibb-tree');

			tree.querySelectorAll('.mibb-selected').forEach((n) => n.classList.remove('mibb-selected'));

			const li = tree.querySelector('li[data-oid="' + oid + '"]');

			if (li) {
				li.querySelector(':scope > .mibb-node-row').classList.add('mibb-selected');
			}

			this.selected_oid = oid;

			const seq = this.select_seq = (this.select_seq ?? 0) + 1;
			const result = await this.request('mibbrowser.node', {oid});

			// A newer selection was made while this one was loading.
			if (seq !== this.select_seq) {
				return;
			}

			if (result.error) {
				this.message(this.$('mibb-panel-details'), result.error);

				return;
			}

			this.selected_node = result.node;
			this.$('mibb-oid').value = result.node.label ?? oid;
			this.renderDetails(result.node);
			this.showTab('details');
		}

		/* ------------------------------------------------------------------ */
		/* Search                                                               */
		/* ------------------------------------------------------------------ */

		initSearch() {
			const input = this.$('mibb-search');
			const box = this.$('mibb-search-results');
			let timer = null;

			input.addEventListener('input', () => {
				clearTimeout(timer);
				timer = setTimeout(() => this.search(input.value), 250);
			});

			input.addEventListener('keydown', (e) => {
				if (e.key === 'Escape') {
					input.value = '';
					box.style.display = 'none';
				}
			});

			box.addEventListener('click', (e) => {
				const item = e.target.closest('[data-oid]');

				if (item) {
					box.style.display = 'none';
					this.revealInTree(item.dataset.oid);
				}
			});
		}

		async search(term) {
			const box = this.$('mibb-search-results');

			term = term.trim();

			if (term.length < 2) {
				box.style.display = 'none';

				return;
			}

			const seq = this.search_seq = (this.search_seq ?? 0) + 1;
			const result = await this.request('mibbrowser.tree', {search: term});

			if (seq !== this.search_seq) {
				return;
			}

			box.innerHTML = '';
			box.style.display = '';

			if (!result.results || !result.results.length) {
				box.append(this.el('div', {class: 'mibb-search-empty', text: <?= json_encode(I18n::t('No results')) ?>}));

				return;
			}

			for (const item of result.results) {
				box.append(this.el('div', {class: 'mibb-search-item', dataset: {oid: item.oid}},
					this.kindBadge(item.kind),
					this.el('span', {class: 'mibb-node-name', text: item.name}),
					this.el('span', {class: 'mibb-search-meta', text: item.module + ' — ' + item.oid})
				));
			}
		}

		/* ------------------------------------------------------------------ */
		/* Details                                                              */
		/* ------------------------------------------------------------------ */

		renderDetails(node) {
			const panel = this.$('mibb-panel-details');
			panel.innerHTML = '';

			const rows = [
				[<?= json_encode(_('Name')) ?>, node.label ?? node.name],
				['OID', node.oid],
				[<?= json_encode(_('Type')) ?>, this.KIND_LABELS[node.kind] ?? node.kind],
				[<?= json_encode(I18n::t('Syntax')) ?>, node.syntax && node.syntax !== node.base_type
					? node.syntax + ' (' + node.base_type + ')'
					: node.syntax],
				[<?= json_encode(I18n::t('Access')) ?>, node.access],
				[<?= json_encode(_('Status')) ?>, node.status],
				[<?= json_encode(_('Units')) ?>, node.units],
				[<?= json_encode(I18n::t('Index')) ?>, node.index ? node.index.join(', ')
					+ (node.index_from ? ' (' + <?= json_encode(_('via')) ?> + ' ' + node.index_from + ')' : '')
					: null],
				['Display hint', node.display_hint],
				[<?= json_encode(I18n::t('Objects')) ?>, node.objects ? node.objects.join(', ') : null]
			];

			const table = this.el('table', {class: 'mibb-details-table'});

			for (const [label, value] of rows) {
				if (value === null || value === undefined || value === '') {
					continue;
				}

				table.append(this.el('tr', {},
					this.el('th', {text: label}),
					this.el('td', {class: label === 'OID' ? 'mibb-mono' : '', text: value})
				));
			}

			if (node.enum) {
				const list = this.el('div', {class: 'mibb-enum'});

				for (const [value, label] of Object.entries(node.enum)) {
					list.append(this.el('span', {class: 'mibb-enum-item', text: label + ' (' + value + ')'}));
				}

				table.append(this.el('tr', {}, this.el('th', {text: <?= json_encode(I18n::t('Values')) ?>}),
					this.el('td', {}, list)
				));
			}

			const buttons = this.el('div', {class: 'mibb-detail-buttons'},
				this.el('button', {type: 'button', text: 'Get',
					onclick: () => this.runQuery('get', node.label ?? node.oid)
				}),
				this.el('button', {type: 'button', text: 'Walk',
					onclick: () => this.runQuery('walk', node.label ?? node.oid)
				}),
				node.can_template
					? this.el('button', {type: 'button', class: 'btn-alt', text: <?= json_encode(I18n::t('Generate a template')) ?>,
						onclick: () => this.openTemplateDialog([node.oid], node)
					})
					: null,
				node.kind === 'scalar' && node.can_create_item
					? this.el('button', {type: 'button', class: 'btn-alt', text: <?= json_encode(I18n::t('Create item')) ?>,
						onclick: () => this.openItemDialog({
							oid: node.oid + '.0', node_oid: node.oid, index: '0', label: node.label + '.0'
						})
					})
					: null
			);

			panel.append(buttons, table);

			if (node.kind === 'column' && node.can_create_item) {
				panel.append(this.el('div', {class: 'mibb-hint',
					text: <?= json_encode(I18n::t('To create an item on a column, run a Walk then choose the instance ("+ Item") in the results, or generate a template with discovery.')) ?>
				}));
			}

			if (node.description) {
				panel.append(this.el('div', {class: 'mibb-description', text: node.description}));
			}
		}

		/* ------------------------------------------------------------------ */
		/* Loaded MIBs                                                           */
		/* ------------------------------------------------------------------ */

		async loadMibs() {
			const panel = this.$('mibb-panel-mibs');
			panel.innerHTML = '';
			panel.append(this.el('div', {class: 'mibb-loading', text: '…'}));

			const result = await this.request('mibbrowser.mibs');
			panel.innerHTML = '';

			if (result.error) {
				this.message(panel, result.error);

				return;
			}

			this.mibs_loaded = true;

			if (this.is_super_admin) {
				const input = this.el('input', {type: 'file', multiple: true, accept: '.mib,.txt,.my,.smi,text/plain',
					id: 'mibb-upload-input'
				});
				const status = this.el('div', {class: 'mibb-upload-status'});

				panel.append(this.el('div', {class: 'mibb-upload'},
					this.el('label', {for: 'mibb-upload-input', text: <?= json_encode(I18n::t('Add MIB files:')) ?>}),
					input,
					this.el('button', {type: 'button', text: <?= json_encode(I18n::t('Upload')) ?>,
						onclick: () => this.uploadMibs(input, status)
					}),
					status
				));
			}
			else {
				panel.append(this.el('div', {class: 'mibb-hint',
					text: <?= json_encode(I18n::t('Only a Super admin can add or delete MIBs.')) ?>
				}));
			}

			if (result.storage_problems && result.storage_problems.length) {
				panel.append(this.el('div', {class: 'mibb-msg mibb-msg-warning',
					text: this.fmt(<?= json_encode(I18n::t('Directory not writable: %1$s')) ?>, result.storage_problems.join(', '))
				}));
			}

			const tbody = this.el('tbody');

			for (const module of result.modules) {
				tbody.append(this.el('tr', {},
					this.el('td', {},
						module.root_oid
							? this.el('a', {href: 'javascript:void(0)', text: module.name,
								onclick: () => this.revealInTree(module.root_oid)
							})
							: module.name
					),
					this.el('td', {class: 'mibb-mono', text: module.file}),
					this.el('td', {text: module.source === 'user' ? <?= json_encode(I18n::t('Added')) ?> : <?= json_encode(I18n::t('Standard')) ?>}),
					this.el('td', {text: module.objects}),
					this.el('td', {class: module.missing.length ? 'mibb-warn-text' : '',
						text: module.missing.length
							? this.fmt(<?= json_encode(I18n::t('Missing dependencies: %1$s')) ?>, module.missing.join(', '))
							: (module.unresolved ? this.fmt(<?= json_encode(I18n::t('%1$s unresolved object(s)')) ?>, module.unresolved) : 'OK')
					}),
					this.el('td', {class: 'mibb-actions'},
						this.el('button', {type: 'button', class: 'btn-link', text: <?= json_encode(I18n::t('Template')) ?>,
							onclick: () => this.openTemplateDialog(['module:' + module.name], {name: module.name, module: module.name})
						}),
						this.is_super_admin && module.source === 'user'
							? this.el('button', {type: 'button', class: 'btn-link mibb-danger', text: <?= json_encode(_('Delete')) ?>,
								onclick: () => this.deleteMib(module.name)
							})
							: null
					)
				));
			}

			panel.append(this.el('table', {class: 'list-table'},
				this.el('thead', {}, this.el('tr', {},
					this.el('th', {text: <?= json_encode(_('Module')) ?>}),
					this.el('th', {text: <?= json_encode(I18n::t('File')) ?>}),
					this.el('th', {text: <?= json_encode(_('Source')) ?>}),
					this.el('th', {text: <?= json_encode(I18n::t('Objects')) ?>}),
					this.el('th', {text: <?= json_encode(I18n::t('State')) ?>}),
					this.el('th', {text: ''})
				)),
				tbody
			));
		}

		async loadSecurity() {
			const panel = this.$('mibb-panel-security');
			panel.innerHTML = '';
			panel.append(this.el('div', {class: 'mibb-loading', text: '…'}));

			const result = await this.request('mibbrowser.security');
			panel.innerHTML = '';

			if (result.error) {
				this.message(panel, result.error);

				return;
			}

			this.policy = result.policy;

			const cards = this.el('div', {class: 'mibb-sec-cards'});

			for (const level of ['encrypted', 'weak', 'auth_only', 'none']) {
				cards.append(this.el('div', {class: 'mibb-sec-card mibb-sec-card-' + level},
					this.el('div', {class: 'mibb-sec-count', text: result.counts[level]}),
					this.el('div', {text: this.SECURITY_LABELS[level]})
				));
			}

			panel.append(cards);

			// Policy.
			const policy = this.el('div', {class: 'mibb-policy'},
				this.el('b', {text: <?= json_encode(I18n::t('Module policy:')) ?>})
			);

			if (result.can_edit) {
				const select = this.el('select', {class: 'mibb-select', id: 'mibb-policy-select'},
					this.el('option', {value: 'warn', text: <?= json_encode(I18n::t('Warn for unencrypted interfaces')) ?>}),
					this.el('option', {value: 'require_encryption', text: <?= json_encode(I18n::t('Require encrypted SNMPv3 (authPriv)')) ?>})
				);
				const status = this.el('span', {class: 'mibb-policy-status'});

				select.value = result.policy;
				policy.append(select,
					this.el('button', {type: 'button', text: <?= json_encode(_('Save')) ?>,
						onclick: async () => {
							const saved = await this.request('mibbrowser.settings', {snmp_security: select.value});

							if (saved.error) {
								this.message(status, saved.error);

								return;
							}

							this.policy = saved.policy;
							this.updateSecurityBadge();
							status.innerHTML = '';
							status.append(this.el('span', {class: 'mibb-ok-text', text: <?= json_encode(I18n::t('Saved.')) ?>}));
						}
					}),
					status
				);
			}
			else {
				policy.append(this.el('span', {text: result.policy === 'require_encryption'
					? <?= json_encode(I18n::t('Encrypted SNMPv3 required')) ?>
					: <?= json_encode(I18n::t('warning')) ?>
				}));
			}

			policy.append(this.el('div', {class: 'mibb-hint',
				text: <?= json_encode(I18n::t('"Require" denies the module Get/Walk requests on SNMPv1, SNMPv2c and unencrypted SNMPv3 interfaces. Normal Zabbix data collection is not affected.')) ?>
			}));

			panel.append(policy);

			if (!result.rows.length) {
				panel.append(this.el('div', {class: 'mibb-empty', text: <?= json_encode(I18n::t('No SNMP interface.')) ?>}));

				return;
			}

			const tbody = this.el('tbody');

			for (const row of result.rows) {
				tbody.append(this.el('tr', {},
					this.el('td', {}, this.securityBadge(row)),
					this.el('td', {},
						this.el('a', {href: 'zabbix.php?action=popup&popup=host.edit&hostid=' + encodeURIComponent(row.hostid),
							target: '_blank', text: row.host
						}),
						row.enabled ? null : this.el('span', {class: 'mibb-unknown', text: ' (' + <?= json_encode(I18n::t('disabled')) ?> + ')'})
					),
					this.el('td', {class: 'mibb-mono', text: row.address}),
					this.el('td', {text: row.summary}),
					this.el('td', {text: row.issues.join(' ')})
				));
			}

			panel.append(this.el('table', {class: 'list-table'},
				this.el('thead', {}, this.el('tr', {},
					this.el('th', {text: <?= json_encode(I18n::t('Security')) ?>}),
					this.el('th', {text: <?= json_encode(_('Host')) ?>}),
					this.el('th', {text: <?= json_encode(_('Interface')) ?>}),
					this.el('th', {text: <?= json_encode(_('Configuration')) ?>}),
					this.el('th', {text: <?= json_encode(I18n::t('To fix')) ?>})
				)),
				tbody
			));

			if (result.limited) {
				panel.append(this.el('div', {class: 'mibb-hint', text: <?= json_encode(I18n::t('List limited to the first 5000 interfaces.')) ?>}));
			}
		}

		async uploadMibs(input, status) {
			if (!input.files.length) {
				return;
			}

			for (const file of input.files) {
				if (file.size > this.max_upload_size) {
					this.message(status, this.fmt(<?= json_encode(I18n::t('%1$s: file too large.')) ?>, file.name));

					return;
				}
			}

			this.message(status, <?= json_encode(I18n::t('Uploading…')) ?>, 'info');

			const result = await this.request('mibbrowser.mib.upload', {}, input.files);

			if (result.error) {
				this.message(status, result.error);

				return;
			}

			const lines = result.results.map((r) => r.error
				? r.file + ' : ' + r.error
				: this.fmt(<?= json_encode(I18n::t('%1$s: %2$s (%3$s objects)')) ?>, r.file, r.modules.join(', '), r.objects)
			);

			this.mibs_loaded = false;
			await this.loadMibs();
			await this.loadRoots();

			const new_status = this.$('mibb-panel-mibs').querySelector('.mibb-upload-status');

			if (new_status) {
				this.message(new_status, lines.join('\n'), result.results.some((r) => r.error) ? 'warning' : 'success');
			}
		}

		async deleteMib(name) {
			if (!confirm(this.fmt(<?= json_encode(I18n::t('Delete MIB %1$s?')) ?>, name))) {
				return;
			}

			const result = await this.request('mibbrowser.mib.delete', {module: name});

			if (result.error) {
				alert(result.error);

				return;
			}

			this.mibs_loaded = false;
			await this.loadMibs();
			await this.loadRoots();
		}

		/* ------------------------------------------------------------------ */
		/* Dialogs                                                              */
		/* ------------------------------------------------------------------ */

		openDialog(dialog) {
			if (!this.backdrop) {
				this.backdrop = this.el('div', {class: 'mibb-backdrop'});
				document.body.append(this.backdrop);
			}

			this.backdrop.style.display = '';
			dialog.show();
		}

		closeDialog(dialog) {
			dialog.close();
			this.backdrop.style.display = 'none';
		}

		initItemDialog() {
			const dialog = this.$('mibb-item-dialog');

			this.$('mibb-item-cancel').addEventListener('click', () => this.closeDialog(dialog));
			this.$('mibb-item-submit').addEventListener('click', () => this.submitItem());
			this.$('mibb-item-form').addEventListener('submit', (e) => e.preventDefault());

			for (const id of ['mibb-item-target-host', 'mibb-item-target-template']) {
				this.$(id).addEventListener('change', () => {
					this.$('mibb-item-template-row').style.display =
						this.$('mibb-item-target-template').checked ? '' : 'none';
				});
			}
		}

		async openItemDialog(row) {
			const dialog = this.$('mibb-item-dialog');
			const result = await this.request('mibbrowser.node', {oid: row.node_oid});

			if (result.error) {
				alert(result.error);

				return;
			}

			const node = result.node;
			const index = row.index ?? '';
			const suffix = index !== '' && index !== '0' ? '[' + index + ']' : '';

			this.item_row = {oid: row.oid, node};

			this.$('mibb-item-oid').textContent = row.label + '  —  get[' + row.oid + ']';
			this.$('mibb-item-name').value = node.suggested.name + (index !== '' && index !== '0' ? ' [' + index + ']' : '');
			this.$('mibb-item-key').value = node.name + suffix;
			this.$('mibb-item-value-type').value = node.suggested.value_type;
			this.$('mibb-item-units').value = node.suggested.units;
			this.$('mibb-item-cps').checked = node.suggested.change_per_second;
			this.$('mibb-item-cps-row').style.display = node.suggested.change_per_second ? '' : 'none';
			this.$('mibb-item-valuemap-row').style.display = node.enum ? '' : 'none';
			this.$('mibb-item-message').innerHTML = '';

			const host_label = this.$('mibb-item-target-host-label');
			const has_host = this.host !== null;

			host_label.textContent = has_host ? this.fmt(<?= json_encode(I18n::t('Host %1$s')) ?>, this.host.name) : <?= json_encode(I18n::t('Host (none selected)')) ?>;
			this.$('mibb-item-target-host').disabled = !has_host;
			this.$('mibb-item-target-host').checked = has_host;
			this.$('mibb-item-target-template').checked = !has_host;
			this.$('mibb-item-template-row').style.display = has_host ? 'none' : '';

			this.openDialog(dialog);
			this.$('mibb-item-name').focus();
		}

		async submitItem() {
			const message = this.$('mibb-item-message');
			let hostid = null;
			let interfaceid = null;

			if (this.$('mibb-item-target-template').checked) {
				const data = jQuery('#mibb_item_templateid').multiSelect('getData');

				if (!data.length) {
					this.message(message, <?= json_encode(I18n::t('Choose a template.')) ?>);

					return;
				}

				hostid = data[0].id;
			}
			else {
				hostid = this.host.hostid;
				interfaceid = this.$('mibb-interface').value || null;
			}

			this.$('mibb-item-submit').disabled = true;

			const result = await this.request('mibbrowser.item.create', {
				hostid,
				interfaceid,
				oid: this.item_row.oid,
				name: this.$('mibb-item-name').value,
				key: this.$('mibb-item-key').value,
				value_type: this.$('mibb-item-value-type').value,
				delay: this.$('mibb-item-delay').value,
				units: this.$('mibb-item-units').value,
				change_per_second: this.$('mibb-item-cps').checked ? 1 : 0,
				create_valuemap: this.$('mibb-item-valuemap').checked && this.item_row.node.enum ? 1 : 0
			});

			this.$('mibb-item-submit').disabled = false;

			if (result.error) {
				this.message(message, result.error);

				return;
			}

			message.innerHTML = '';
			message.append(this.el('div', {class: 'mibb-msg mibb-msg-success'},
				result.message + ' ',
				this.el('a', {href: result.url, target: '_blank', text: <?= json_encode(I18n::t('View items')) ?>})
			));
		}

		initTemplateDialog() {
			const dialog = this.$('mibb-tpl-dialog');

			this.$('mibb-tpl-cancel').addEventListener('click', () => this.closeDialog(dialog));
			this.$('mibb-tpl-form').addEventListener('submit', (e) => e.preventDefault());
			this.$('mibb-tpl-preview-btn').addEventListener('click', () => this.generateTemplate('preview'));
			this.$('mibb-tpl-yaml').addEventListener('click', () => this.generateTemplate('yaml'));
			this.$('mibb-tpl-import').addEventListener('click', () => this.generateTemplate('import'));
		}

		openTemplateDialog(roots, node = null) {
			const dialog = this.$('mibb-tpl-dialog');
			const label = node ? (node.module && node.name !== node.module ? node.module + '::' + node.name : node.name) : roots.join(', ');
			const base = node ? (node.name === node.module ? node.module : node.name) : 'SNMP device';

			this.template_roots = roots;
			this.$('mibb-tpl-roots').textContent = this.fmt(<?= json_encode(I18n::t('Source: %1$s')) ?>, label);
			this.$('mibb-tpl-name').value = base + ' by SNMP';
			this.$('mibb-tpl-preview').innerHTML = '';
			this.$('mibb-tpl-message').innerHTML = '';

			const has_host = this.host !== null;

			this.$('mibb-tpl-link').disabled = !has_host;
			this.$('mibb-tpl-link').checked = false;
			this.$('mibb-tpl-link-label').textContent = has_host
				? this.fmt(<?= json_encode(I18n::t('Link the template to host %1$s after import')) ?>, this.host.name)
				: <?= json_encode(I18n::t('Link the template to a host (select a host first)')) ?>;

			this.openDialog(dialog);
			this.$('mibb-tpl-name').focus();
		}

		async generateTemplate(mode) {
			const message = this.$('mibb-tpl-message');
			const params = {
				roots: this.template_roots,
				template: this.$('mibb-tpl-name').value.trim(),
				group: this.$('mibb-tpl-group').value.trim(),
				key_prefix: this.$('mibb-tpl-prefix').value.trim(),
				delay: this.$('mibb-tpl-delay').value.trim(),
				walk_delay: this.$('mibb-tpl-walk-delay').value.trim(),
				include_scalars: this.$('mibb-tpl-scalars').checked ? 1 : 0,
				include_tables: this.$('mibb-tpl-tables').checked ? 1 : 0,
				change_per_second: this.$('mibb-tpl-cps').checked ? 1 : 0,
				mode
			};

			if (mode === 'import' && this.$('mibb-tpl-link').checked && this.host !== null) {
				params.link_hostid = this.host.hostid;
			}

			this.message(message, <?= json_encode(I18n::t('Generating…')) ?>, 'info');

			const result = await this.request('mibbrowser.template.generate', params);

			if (result.error) {
				this.message(message, result.error);

				return;
			}

			this.renderTemplateSummary(result.summary);

			if (mode === 'yaml') {
				this.download(result.filename, result.content, result.mime);
				this.message(message, this.fmt(<?= json_encode(I18n::t('File downloaded: %1$s')) ?>, result.filename), 'success');
			}
			else if (mode === 'import') {
				message.innerHTML = '';
				message.append(this.el('div', {class: 'mibb-msg mibb-msg-success'},
					result.message + ' ',
					this.el('a', {href: result.url, target: '_blank', text: <?= json_encode(I18n::t('Open template')) ?>})
				));
			}
			else {
				message.innerHTML = '';
			}
		}

		renderTemplateSummary(summary) {
			const box = this.$('mibb-tpl-preview');
			box.innerHTML = '';

			box.append(this.el('div', {class: 'mibb-tpl-counts', text:
				this.fmt(<?= json_encode(I18n::t('%1$s item(s), %2$s discovery rule(s), %3$s prototype(s), %4$s value map(s)')) ?>,
					summary.items.length, summary.discovery_rules.length, summary.prototype_count, summary.valuemaps
				)
			}));

			const list = this.el('ul', {class: 'mibb-tpl-list'});

			for (const item of summary.items) {
				list.append(this.el('li', {}, this.el('b', {text: item.name}), ' — ',
					this.el('span', {class: 'mibb-mono', text: item.key + '  ' + item.oid})
				));
			}

			for (const rule of summary.discovery_rules) {
				const sub = this.el('ul');

				for (const prototype of rule.prototypes) {
					sub.append(this.el('li', {}, prototype.name + ' — ',
						this.el('span', {class: 'mibb-mono', text: prototype.key})
					));
				}

				list.append(this.el('li', {}, this.el('b', {text: rule.name}), ' — ',
					this.el('span', {class: 'mibb-mono', text: rule.key}), sub
				));
			}

			box.append(list);
		}
	};
</script>
