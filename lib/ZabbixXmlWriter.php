<?php declare(strict_types = 0);
/*
** MIB Browser module for Zabbix 8.0
** XML emitter for Zabbix export arrays, in the layout of Zabbix XML exports: list elements are named after the
** singular of their parent (<items><item>...</item></items>, <preprocessing><step>...).
*/

namespace Modules\MibBrowser\Lib;

class ZabbixXmlWriter {

	/** Element names of list items that are not "<plural> minus s". */
	private const LIST_ITEMS = [
		'preprocessing' => 'step',
		'parameters' => 'parameter',
		'discovery_rules' => 'discovery_rule',
		'item_prototypes' => 'item_prototype',
		'trigger_prototypes' => 'trigger_prototype',
		'graph_prototypes' => 'graph_prototype',
		'host_prototypes' => 'host_prototype',
		'lld_macro_paths' => 'lld_macro_path',
		'template_groups' => 'template_group',
		'host_groups' => 'host_group',
		'group_links' => 'group_link',
		'group_prototypes' => 'group_prototype',
		'value_maps' => 'value_map',
		'valuemaps' => 'valuemap',
		'mappings' => 'mapping',
		'dependencies' => 'dependency',
		'overrides' => 'override',
		'operations' => 'operation',
		'conditions' => 'condition',
		'headers' => 'header',
		'query_fields' => 'query_field',
		'graph_items' => 'graph_item',
		'dashboards' => 'dashboard',
		'pages' => 'page',
		'widgets' => 'widget',
		'fields' => 'field',
		'macros' => 'macro',
		'tags' => 'tag',
		'templates' => 'template',
		'groups' => 'group',
		'items' => 'item',
		'triggers' => 'trigger',
		'graphs' => 'graph',
		'hosts' => 'host',
		'interfaces' => 'interface',
		'inventory' => 'inventory',
		'httptests' => 'httptest',
		'steps' => 'step',
		'variables' => 'variable',
		'posts' => 'post_field'
	];

	public static function write(array $data): string {
		$xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";

		foreach ($data as $name => $value) {
			$xml .= self::element((string) $name, $value, 0);
		}

		return $xml;
	}

	private static function element(string $name, $value, int $depth): string {
		$pad = str_repeat('    ', $depth);

		if (!is_array($value)) {
			$value = (string) $value;

			return $value === ''
				? $pad.'<'.$name.'/>'."\n"
				: $pad.'<'.$name.'>'.XlsxWriter::esc($value).'</'.$name.'>'."\n";
		}

		if ($value === []) {
			return $pad.'<'.$name.'/>'."\n";
		}

		$xml = $pad.'<'.$name.'>'."\n";

		if (array_is_list($value)) {
			$child = self::LIST_ITEMS[$name] ?? (substr($name, -1) === 's' ? substr($name, 0, -1) : $name.'_item');

			foreach ($value as $item) {
				$xml .= self::element($child, $item, $depth + 1);
			}
		}
		else {
			foreach ($value as $key => $item) {
				$xml .= self::element((string) $key, $item, $depth + 1);
			}
		}

		return $xml.$pad.'</'.$name.'>'."\n";
	}
}
