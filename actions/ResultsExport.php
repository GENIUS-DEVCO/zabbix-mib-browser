<?php declare(strict_types = 0);
/*
** MIB Browser module for Zabbix 8.0
** Export of Get/Walk results: CSV, JSON, XML, Excel or snmpwalk text.
*/

namespace Modules\MibBrowser\Actions;

use Modules\MibBrowser\Lib\I18n,
	Modules\MibBrowser\Lib\ResultExporter;

class ResultsExport extends BaseJson {

	private const MAX_ROWS = 100000;

	protected function checkInput(): bool {
		return $this->validate([
			'format' => 'required|in '.implode(',', ResultExporter::FORMATS),
			'rows' => 'required|string',
			'host' => 'string',
			'request' => 'string'
		]);
	}

	protected function doAction(): void {
		$rows = json_decode($this->getInput('rows'), true);

		if (!is_array($rows) || !array_is_list($rows) || count($rows) > self::MAX_ROWS) {
			$this->respondError(I18n::t('Invalid input parameters.'));

			return;
		}

		$file = ResultExporter::export($this->getInput('format'), $rows, [
			'host' => $this->getInput('host', ''),
			'request' => $this->getInput('request', '')
		], [
			'name' => _('Name'),
			'type' => _('Type'),
			'value' => _('Value'),
			'raw' => I18n::t('Raw value'),
			'host' => _('Host'),
			'request' => I18n::t('Request'),
			'date' => _('Date')
		]);

		$this->respondFile($file);
	}
}
