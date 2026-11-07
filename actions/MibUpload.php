<?php declare(strict_types = 0);
/*
** MIB Browser module for Zabbix 8.0
** Upload of MIB files (Super admin only).
*/

namespace Modules\MibBrowser\Actions;

use Modules\MibBrowser\Lib\I18n;

use Modules\MibBrowser\Lib\MibParser,
	Modules\MibBrowser\Lib\Storage;

class MibUpload extends BaseJson {

	protected int $min_user_type = USER_TYPE_SUPER_ADMIN;

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
		$parser = new MibParser();
		$results = [];
		$dir = Storage::userMibDir();

		foreach ($files as $file) {
			$name = $file['name'];

			if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
				$results[] = ['file' => $name, 'error' => I18n::t('Upload failed.')];
				continue;
			}

			if ($file['size'] > $max_size) {
				$results[] = ['file' => $name, 'error' => I18n::t('File too large.')];
				continue;
			}

			$text = file_get_contents($file['tmp_name']);

			if ($text === false || strpos($text, "\0") !== false) {
				$results[] = ['file' => $name, 'error' => I18n::t('Not a text file.')];
				continue;
			}

			$modules = $parser->parse($text);

			if (!$modules) {
				$results[] = ['file' => $name, 'error' => I18n::t('No MIB module found ("DEFINITIONS ::= BEGIN").')];
				continue;
			}

			// File is stored under the name of its first module.
			$module_name = $modules[0]['name'];
			$target = $dir.'/'.preg_replace('/[^A-Za-z0-9_.-]/', '_', $module_name).'.mib';

			if (file_put_contents($target, $text, LOCK_EX) === false) {
				$results[] = ['file' => $name, 'error' => I18n::t('Cannot write the file.')];
				continue;
			}

			$results[] = [
				'file' => $name,
				'modules' => array_column($modules, 'name'),
				'objects' => array_sum(array_map(static fn($m) => count($m['nodes']), $modules)),
				'errors' => array_merge(...array_column($modules, 'errors'))
			];
		}

		Storage::resetRepository();
		Storage::repository()->getIndex();

		$this->respond(['results' => $results]);
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
