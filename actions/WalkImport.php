<?php declare(strict_types = 0);
/*
** MIB Browser module for Zabbix 8.0
** Import of a walk file (snmpwalk output or export of the module) to browse it offline. Compressed files
** (.gz, .zip) are accepted. Nothing is stored on the server.
*/

namespace Modules\MibBrowser\Actions;

use Modules\MibBrowser\Lib\ArchiveReader,
	Modules\MibBrowser\Lib\I18n,
	Modules\MibBrowser\Lib\MibRepository,
	Modules\MibBrowser\Lib\SnmpResult,
	Modules\MibBrowser\Lib\Storage,
	Modules\MibBrowser\Lib\WalkImporter;

class WalkImport extends BaseJson {

	protected function checkInput(): bool {
		return $this->validate([]);
	}

	protected function doAction(): void {
		$file = $_FILES['file'] ?? null;

		if (!is_array($file) || is_array($file['name'] ?? null)) {
			$this->respondError(I18n::t('No file received.'));

			return;
		}

		$name = basename((string) $file['name']);
		$max_size = (int) Storage::option('max_walk_size', 20971520);

		if ((int) $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
			$this->respondError(in_array((int) $file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
				? I18n::t('File too large.')
				: I18n::t('Upload failed.')
			);

			return;
		}

		if ($file['size'] > $max_size) {
			$this->respondError(I18n::t('File too large.'));

			return;
		}

		$content = (string) file_get_contents($file['tmp_name']);

		// Compressed walk: the file of the archive (the largest one if there are several).
		if (ArchiveReader::detect($content) !== null) {
			$files = (new ArchiveReader($max_size, $max_size))->read($content, $name);

			if (!$files) {
				$this->respondError(I18n::t('Cannot read the archive.'));

				return;
			}

			uasort($files, static fn($a, $b) => strlen($b) <=> strlen($a));
			$name = basename((string) array_key_first($files));
			$content = reset($files);
		}

		if (strpos($content, "\0") !== false) {
			$this->respondError(I18n::t('Not a text file.'));

			return;
		}

		$repository = Storage::repository();
		$importer = new WalkImporter($repository);
		$format = WalkImporter::detect($content);
		$records = $importer->import($content);

		if (!$records) {
			$this->respondError(I18n::t('No SNMP value found in the file.'), [
				'skipped' => $importer->getSkipped()
			]);

			return;
		}

		usort($records, static fn($a, $b) => MibRepository::compareOids($a['oid'], $b['oid']));

		$max = (int) Storage::option('max_results', 5000);
		$total = count($records);
		$rows = SnmpResult::buildRows(array_slice($records, 0, $max), $repository);

		// Objects found in the file, for a template: scalars and table rows, and the columns present.
		$roots = [];
		$columns = [];

		foreach ($records as $record) {
			$oid = $repository->translate($record['oid'])['oid'];
			$kind = $oid !== null ? ($repository->getNode($oid)['kind'] ?? '') : '';

			if ($kind === 'scalar') {
				$roots[$oid] = true;
			}
			elseif ($kind === 'column') {
				$columns[$oid] = true;
				$roots[MibRepository::parentOid($oid)] = true;
			}
		}

		$meta = $importer->getMeta();

		$this->respond([
			'operation' => 'walk',
			'source' => 'file',
			'filename' => $name,
			'format' => $format,
			'file_host' => $meta['host'] ?? '',
			'oid' => '',
			'rows' => $rows,
			'total' => $total,
			'limited' => $total > count($rows),
			'truncated' => false,
			'duration' => 0,
			'skipped' => $importer->getSkipped(),
			'skipped_count' => $importer->getSkippedCount(),
			'roots' => array_keys($roots),
			'columns' => array_keys($columns),
			'snmp_oid' => $name
		]);
	}
}
