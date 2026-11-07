<?php declare(strict_types = 0);
/*
** MIB Browser module for Zabbix 8.0
** Generate a Zabbix 8.0 template from MIB objects: preview, download (YAML/XML/JSON) or direct import.
*/

namespace Modules\MibBrowser\Actions;

use Modules\MibBrowser\Lib\I18n;

use API,
	CUrl,
	Modules\MibBrowser\Lib\Storage,
	Modules\MibBrowser\Lib\TemplateBuilder,
	Modules\MibBrowser\Lib\YamlWriter,
	Modules\MibBrowser\Lib\ZabbixXmlWriter;

class TemplateGenerate extends BaseJson {

	protected function checkInput(): bool {
		return $this->validate([
			'roots' => 'required|array',
			'template' => 'required|string|not_empty',
			'group' => 'required|string|not_empty',
			'key_prefix' => 'string',
			'delay' => 'required|string|not_empty',
			'walk_delay' => 'required|string|not_empty',
			'change_per_second' => 'in 0,1',
			'include_scalars' => 'in 0,1',
			'include_tables' => 'in 0,1',
			'mode' => 'required|in preview,yaml,xml,json,import',
			'columns' => 'array',
			'link_hostid' => 'db hosts.hostid'
		]);
	}

	protected function doAction(): void {
		$roots = array_values(array_filter(array_map('strval', $this->getInput('roots')),
			static fn($r) => preg_match('/^(\d+(\.\d+)*|module:[A-Za-z0-9_-]+)$/', $r)
		));

		if (!$roots) {
			$this->respondError(I18n::t('Select a MIB node or module.'));

			return;
		}

		$key_prefix = $this->getInput('key_prefix', '');

		if ($key_prefix !== '' && !preg_match('/^[A-Za-z0-9_.-]+$/', $key_prefix)) {
			$this->respondError(I18n::t('The key prefix may only contain letters, digits, "_", "-" and ".".'));

			return;
		}

		$builder = new TemplateBuilder(Storage::repository(), [
			'template' => $this->getInput('template'),
			'group' => $this->getInput('group'),
			'key_prefix' => $key_prefix,
			'delay' => $this->getInput('delay'),
			'walk_delay' => $this->getInput('walk_delay'),
			'change_per_second' => (bool) $this->getInput('change_per_second', 0),
			'include_scalars' => (bool) $this->getInput('include_scalars', 1),
			'include_tables' => (bool) $this->getInput('include_tables', 1)
		]);

		// Imported walk: limit tables to the columns present in the file.
		$columns = array_values(array_filter(array_map('strval', $this->getInput('columns', [])),
			static fn($c) => preg_match('/^\d+(\.\d+)*$/', $c)
		));

		$export = $builder->build($roots, $columns);
		$template = $export['zabbix_export']['templates'][0];
		$mode = $this->getInput('mode');

		if (empty($template['items'])) {
			$this->respondError(implode("\n", $builder->getWarnings() ?: [I18n::t('Nothing to generate.')]));

			return;
		}

		$summary = self::summary($template);
		$filename = preg_replace('/[^A-Za-z0-9_.-]+/', '_', $template['template']);

		switch ($mode) {
			case 'preview':
				$this->respond(['summary' => $summary, 'warnings' => $builder->getWarnings()]);
				break;

			case 'yaml':
				$this->respond([
					'summary' => $summary,
					'filename' => $filename.'.yaml',
					'mime' => 'application/yaml',
					'content' => YamlWriter::write($export)
				]);
				break;

			case 'xml':
				$this->respond([
					'summary' => $summary,
					'filename' => $filename.'.xml',
					'mime' => 'application/xml',
					'content' => ZabbixXmlWriter::write($export)
				]);
				break;

			case 'json':
				$this->respond([
					'summary' => $summary,
					'filename' => $filename.'.json',
					'mime' => 'application/json',
					'content' => json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
				]);
				break;

			case 'import':
				$this->import($export, $template, $summary);
				break;
		}
	}

	private function import(array $export, array $template, array $summary): void {
		$rules = [];

		foreach (['templates', 'template_groups', 'items', 'discoveryRules', 'valueMaps'] as $rule) {
			$rules[$rule] = ['createMissing' => true, 'updateExisting' => true];
		}

		$result = API::Configuration()->import([
			'format' => 'json',
			'source' => json_encode($export, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
			'rules' => $rules
		]);

		if (!$result) {
			$this->respondError(_('Import failed'));

			return;
		}

		$templates = API::Template()->get([
			'output' => ['templateid', 'name'],
			'filter' => ['host' => $template['template']]
		]);

		if (!$templates) {
			$this->respondError(_('Import failed'));

			return;
		}

		$templateid = $templates[0]['templateid'];
		$linked = null;

		if ($this->hasInput('link_hostid')) {
			$hosts = API::Host()->get([
				'output' => ['hostid', 'name'],
				'hostids' => [$this->getInput('link_hostid')],
				'selectParentTemplates' => ['templateid'],
				'editable' => true
			]);

			if ($hosts) {
				if (!in_array($templateid, array_column($hosts[0]['parentTemplates'], 'templateid'))) {
					API::Host()->massAdd([
						'hosts' => [['hostid' => $hosts[0]['hostid']]],
						'templates' => [['templateid' => $templateid]]
					]);
				}

				$linked = $hosts[0]['name'];
			}
		}

		$url = (new CUrl('zabbix.php'))
			->setArgument('action', 'template.list')
			->setArgument('filter_name', $template['template'])
			->setArgument('filter_set', '1')
			->getUrl();

		$message = I18n::s('Template "%1$s" imported.', $template['template']);

		if ($linked !== null) {
			$message .= ' '.I18n::s('Linked to host %1$s.', $linked);
		}

		$this->respond([
			'summary' => $summary,
			'templateid' => $templateid,
			'url' => $url,
			'message' => $message
		]);
	}

	private static function summary(array $template): array {
		$items = [];

		foreach ($template['items'] ?? [] as $item) {
			$items[] = ['name' => $item['name'], 'key' => $item['key'], 'oid' => $item['snmp_oid'] ?? ''];
		}

		$rules = [];

		foreach ($template['discovery_rules'] ?? [] as $rule) {
			$rules[] = [
				'name' => $rule['name'],
				'key' => $rule['key'],
				'prototypes' => array_map(static fn($p) => ['name' => $p['name'], 'key' => $p['key']],
					$rule['item_prototypes']
				)
			];
		}

		return [
			'template' => $template['template'],
			'items' => $items,
			'discovery_rules' => $rules,
			'valuemaps' => count($template['valuemaps'] ?? []),
			'prototype_count' => array_sum(array_map(static fn($r) => count($r['prototypes']), $rules))
		];
	}
}
