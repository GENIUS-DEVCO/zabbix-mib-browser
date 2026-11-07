<?php declare(strict_types = 0);
/*
** MIB Browser module for Zabbix 8.0
** Reading of archives in pure PHP: .zip (stored or deflated), .tar, .tar.gz / .tgz and single .gz files.
** Sizes are limited to protect against archive bombs.
*/

namespace Modules\MibBrowser\Lib;

class ArchiveReader {

	public const MAX_ENTRIES = 2000;

	private int $max_file_size;
	private int $max_total_size;
	private array $warnings = [];

	/**
	 * @param int $max_file_size   Maximum uncompressed size of one file.
	 * @param int $max_total_size  Maximum uncompressed size of all files.
	 */
	public function __construct(int $max_file_size, int $max_total_size) {
		$this->max_file_size = $max_file_size;
		$this->max_total_size = $max_total_size;
	}

	/**
	 * @return array  [['file' => name, 'reason' => zlib|encrypted|method|corrupted|too_large|total_too_large|too_many]]
	 */
	public function getWarnings(): array {
		return $this->warnings;
	}

	/**
	 * Archive type from the content (magic bytes), or null for a plain file.
	 */
	public static function detect(string $data): ?string {
		if (strncmp($data, "PK\x03\x04", 4) == 0 || strncmp($data, "PK\x05\x06", 4) == 0) {
			return 'zip';
		}

		if (strncmp($data, "\x1f\x8b", 2) == 0) {
			return 'gzip';
		}

		if (strlen($data) >= 512 && substr($data, 257, 5) === 'ustar') {
			return 'tar';
		}

		return null;
	}

	/**
	 * Files of an archive.
	 *
	 * @param string $data  Archive content.
	 * @param string $name  Archive file name (used to name the content of a single .gz file).
	 *
	 * @return array|null  [path => content], or null if the data is not an archive or is unreadable.
	 */
	public function read(string $data, string $name = ''): ?array {
		switch (self::detect($data)) {
			case 'zip':
				return $this->readZip($data);

			case 'tar':
				return $this->readTar($data);

			case 'gzip':
				if (!function_exists('gzdecode')) {
					$this->warnings[] = ['file' => $name, 'reason' => 'zlib'];

					return null;
				}

				$inner = @gzdecode($data, $this->max_total_size + 1);

				if ($inner === false || strlen($inner) > $this->max_total_size) {
					return null;
				}

				if (self::detect($inner) === 'tar') {
					return $this->readTar($inner);
				}

				$inner_name = preg_replace('/\.(gz|tgz)$/i', '', basename($name));

				return strlen($inner) <= $this->max_file_size ? [$inner_name !== '' ? $inner_name : 'file' => $inner] : null;
		}

		return null;
	}

	private function readTar(string $data): ?array {
		$files = [];
		$total = 0;
		$offset = 0;
		$length = strlen($data);
		$long_name = null;

		while ($offset + 512 <= $length) {
			$header = substr($data, $offset, 512);

			if (trim($header, "\0") === '') {
				break;
			}

			$name = rtrim(substr($header, 0, 100), "\0");
			$size = octdec(trim(substr($header, 124, 12), "\0 ")) ?: 0;
			$type = $header[156];
			$prefix = rtrim(substr($header, 345, 155), "\0");
			$offset += 512;
			$content = substr($data, $offset, $size);
			$offset += (int) ceil($size / 512) * 512;

			// GNU long name.
			if ($type === 'L') {
				$long_name = rtrim($content, "\0");
				continue;
			}

			if ($long_name !== null) {
				$name = $long_name;
				$long_name = null;
			}
			elseif ($prefix !== '' && substr($header, 257, 5) === 'ustar') {
				$name = $prefix.'/'.$name;
			}

			// Regular files only.
			if ($type !== '0' && $type !== "\0") {
				continue;
			}

			$accept = $this->accept($files, $name, $size, $total);

			if ($accept < 0) {
				break;
			}

			if ($accept > 0) {
				$files[$name] = $content;
			}
		}

		return $files;
	}

	private function readZip(string $data): ?array {
		// End of central directory (at the end, before an optional comment).
		$eocd = strrpos(substr($data, -65557), "PK\x05\x06");

		if ($eocd === false) {
			return null;
		}

		$eocd += max(0, strlen($data) - 65557);
		$end = unpack('vdisk/vcd_disk/ventries_disk/ventries/Vcd_size/Vcd_offset', substr($data, $eocd + 4, 16));

		if ($end === false || $end['entries'] > self::MAX_ENTRIES) {
			return null;
		}

		$files = [];
		$total = 0;
		$offset = $end['cd_offset'];

		for ($i = 0; $i < $end['entries']; $i++) {
			if (substr($data, $offset, 4) !== "PK\x01\x02") {
				return null;
			}

			$cd = unpack('vmade/vneed/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vsize/vname_len/vextra_len/vcomment_len/'.
				'vdisk/vint_attr/Vext_attr/Vlocal', substr($data, $offset + 4, 42)
			);
			$name = substr($data, $offset + 46, $cd['name_len']);
			$offset += 46 + $cd['name_len'] + $cd['extra_len'] + $cd['comment_len'];

			if (substr($name, -1) === '/') {
				continue;
			}

			if ($cd['flags'] & 0x1) {
				$this->warnings[] = ['file' => $name, 'reason' => 'encrypted'];
				continue;
			}

			$accept = $this->accept($files, $name, $cd['size'], $total);

			if ($accept < 0) {
				break;
			}

			if ($accept == 0) {
				continue;
			}

			$local = unpack('vname_len/vextra_len', substr($data, $cd['local'] + 26, 4));

			if ($local === false) {
				return null;
			}

			$compressed = substr($data, $cd['local'] + 30 + $local['name_len'] + $local['extra_len'], $cd['csize']);

			if ($cd['method'] == 0) {
				$content = $compressed;
			}
			elseif ($cd['method'] == 8 && function_exists('gzinflate')) {
				$content = @gzinflate($compressed, $this->max_file_size + 1);
			}
			else {
				$this->warnings[] = ['file' => $name, 'reason' => 'method'];
				continue;
			}

			if ($content === false || strlen($content) != $cd['size'] || crc32($content) !== $cd['crc']) {
				$this->warnings[] = ['file' => $name, 'reason' => 'corrupted'];
				continue;
			}

			$files[$name] = $content;
		}

		return $files;
	}

	/**
	 * Check limits before reading an entry.
	 *
	 * @return int  1: read the entry, 0: skip it, -1: stop reading the archive.
	 */
	private function accept(array $files, string $name, int $size, int &$total): int {
		if (count($files) >= self::MAX_ENTRIES) {
			$this->warnings[] = ['file' => $name, 'reason' => 'too_many'];

			return -1;
		}

		if ($size > $this->max_file_size) {
			$this->warnings[] = ['file' => $name, 'reason' => 'too_large'];

			return 0;
		}

		$total += $size;

		if ($total > $this->max_total_size) {
			$this->warnings[] = ['file' => $name, 'reason' => 'total_too_large'];

			return -1;
		}

		return 1;
	}
}
