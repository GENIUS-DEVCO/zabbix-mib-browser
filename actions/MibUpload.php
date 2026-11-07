<?php declare(strict_types = 0);
/*
** MIB Browser module for Zabbix 8.0
** Upload of MIB files, one by one or in archives (.zip, .tar, .tar.gz). Super admin only.
*/

namespace Modules\MibBrowser\Actions;

use Modules\MibBrowser\Lib\I18n;

use Modules\MibBrowser\Lib\ArchiveReader,
	Modules\MibBrowser\Lib\MibParser,
	Modules\MibBrowser\Lib\Storage;

class MibUpload extends BaseJson {

	protected int $min_user_type = USER_TYPE_SUPER_ADMIN;

	private MibParser $parser;
	private string $dir;

	protected function checkInput(): bool {
		return $this->validate([]);
	}

	protected function doAction(): void {
		$problems = Storage::check();

		if ($problems) {
			$this->respondError(I18n::s('The data directory is not writable: %1$s',
				implode(', ', $problems)
			));

			return;
		}

		$files = self::normalizeFiles($_FILES['mibs'] ?? []);

		if (!$files) {
			$this->respondError(I18n::t('No file received.'));

			return;
		}

		$max_size = (int) Storage::option('max_upload_size', 5242880);
		$max_archive_size = max($max_size, (int) Storage::option('max_archive_size', 52428800));
		$this->parser = new MibParser();
		$this->dir = Storage::userMibDir();
		$results = [];

		foreach ($files as $file) {
			$name = $file['name'];

			if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
				$results[] = ['file' => $name, 'error' => I18n::t('Upload failed.')];
				continue;
			}

			if ($file['size'] > $max_archive_size) {
				$results[] = ['file' => $name, 'error' => I18n::t('File too large.')];
				continue;
			}

			$text = file_get_contents($file['tmp_name']);

			if ($text !== false && ArchiveReader::detect($text) !== null) {
				array_push($results, ...$this->importArchive($name, $text, $max_size, $max_archive_size));
				continue;
			}

			if ($file['size'] > $max_size) {
				$results[] = ['file' => $name, 'error' => I18n::t('File too large.')];
				continue;
			}

			$results[] = $this->importText($name, $text === false ? null : $text);
		}

		Storage::resetRepository();
		Storage::repository()->getIndex();

		$this->respond(['results' => $results]);
	}

	/**
	 * Import the MIB files of an archive (.zip, .tar, .tar.gz). Files without MIB module (README, ...) are ignored.
	 */
	private function importArchive(string $name, string $data, int $max_size, int $max_total): array {
		$reader = new ArchiveReader($max_size, $max_total * 10);
		$files = $reader->read($data, $name);

		if ($files === null) {
			return [['file' => $name, 'error' => I18n::t('Cannot read the archive.')]];
		}

		$results = [];
		$ignored = 0;

		ksort($files, SORT_NATURAL | SORT_FLAG_CASE);

		foreach ($files as $path => $content) {
			$base = basename($path);
			$ext = strtolower(pathinfo($base, PATHINFO_EXTENSION));

			// Hidden files, macOS metadata and documents.
			if ($base === '' || $base[0] === '.' || strpos($path, '__MACOSX/') !== false
					|| in_array($ext, ['pdf', 'doc', 'docx', 'html', 'htm', 'xml', 'json', 'png', 'jpg', 'gif', 'exe', 'zip',
						'gz', 'tar', 'md'], true)
					|| strpos($content, 'DEFINITIONS') === false) {
				$ignored++;
				continue;
			}

			$results[] = $this->importText($name.' / '.$path, $content);
		}

		foreach ($reader->getWarnings() as $warning) {
			$results[] = ['file' => $name.' / '.$warning['file'], 'error' => self::archiveWarning($warning['reason'])];
		}

		if (!$results) {
			$results[] = ['file' => $name, 'error' => I18n::t('No MIB module found ("DEFINITIONS ::= BEGIN").')];
		}
		elseif ($ignored) {
			$results[] = ['file' => $name, 'info' => I18n::s('%1$s other file(s) ignored.', $ignored)];
		}

		return $results;
	}

	private static function archiveWarning(string $reason): string {
		switch ($reason) {
			case 'too_large':
				return I18n::t('File too large.');

			case 'total_too_large':
			case 'too_many':
				return I18n::t('Archive too large: the remaining files were not imported.');

			case 'encrypted':
				return I18n::t('Encrypted file, ignored.');

			default:
				return I18n::t('Cannot read the archive.');
		}
	}

	/**
	 * Parse and store one MIB file.
	 */
	private function importText(string $name, ?string $text): array {
		if ($text === null || strpos($text, "\0") !== false) {
			return ['file' => $name, 'error' => I18n::t('Not a text file.')];
		}

		$modules = $this->parser->parse($text);

		if (!$modules) {
			return ['file' => $name, 'error' => I18n::t('No MIB module found ("DEFINITIONS ::= BEGIN").')];
		}

		// File is stored under the name of its first module.
		$module_name = $modules[0]['name'];
		$target = $this->dir.'/'.preg_replace('/[^A-Za-z0-9_.-]/', '_', $module_name).'.mib';

		if (file_put_contents($target, $text, LOCK_EX) === false) {
			return ['file' => $name, 'error' => I18n::t('Cannot write the file.')];
		}

		return [
			'file' => $name,
			'modules' => array_column($modules, 'name'),
			'objects' => array_sum(array_map(static fn($m) => count($m['nodes']), $modules)),
			'errors' => array_merge(...array_column($modules, 'errors'))
		];
	}

	/**
	 * Convert PHP multi-file upload structure to a list.
	 */
	private static function normalizeFiles(array $files): array {
		if (!$files || !array_key_exists('name', $files)) {
			return [];
		}

		if (!is_array($files['name'])) {
			return [$files];
		}

		$list = [];

		foreach (array_keys($files['name']) as $i) {
			$list[] = [
				'name' => (string) $files['name'][$i],
				'tmp_name' => (string) $files['tmp_name'][$i],
				'error' => (int) $files['error'][$i],
				'size' => (int) $files['size'][$i]
			];
		}

		return $list;
	}
}
