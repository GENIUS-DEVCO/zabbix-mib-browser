<?php declare(strict_types = 0);
/*
** MIB Browser module for Zabbix 8.0
** Minimal ZIP writer in pure PHP (no "zip" extension needed). Entries are deflated when zlib is available,
** stored otherwise. Used for MIB archives and XLSX files.
*/

namespace Modules\MibBrowser\Lib;

class ZipWriter {

	private array $entries = [];

	/**
	 * Add a file. The name uses "/" as separator.
	 */
	public function add(string $name, string $content, ?int $time = null): void {
		$this->entries[] = ['name' => ltrim(str_replace('\\', '/', $name), '/'), 'content' => $content,
			'time' => $time ?? time()
		];
	}

	public function count(): int {
		return count($this->entries);
	}

	/**
	 * Build the archive.
	 */
	public function build(): string {
		$data = '';
		$central = '';

		foreach ($this->entries as $entry) {
			$content = $entry['content'];
			$crc = crc32($content);
			$method = 0;
			$stored = $content;

			if (function_exists('gzdeflate') && strlen($content) > 64) {
				$deflated = gzdeflate($content, 6);

				if ($deflated !== false && strlen($deflated) < strlen($content)) {
					$method = 8;
					$stored = $deflated;
				}
			}

			[$dos_time, $dos_date] = self::dosTime($entry['time']);
			$name = $entry['name'];
			// Bit 11: file name is UTF-8.
			$flags = preg_match('/[^\x20-\x7e]/', $name) ? 0x0800 : 0;
			$offset = strlen($data);

			$data .= pack('VvvvvvVVVvv', 0x04034b50, 20, $flags, $method, $dos_time, $dos_date, $crc, strlen($stored),
				strlen($content), strlen($name), 0
			).$name.$stored;

			$central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, $flags, $method, $dos_time, $dos_date, $crc,
				strlen($stored), strlen($content), strlen($name), 0, 0, 0, 0, 0, $offset
			).$name;
		}

		return $data.$central.pack('VvvvvVVv', 0x06054b50, 0, 0, count($this->entries), count($this->entries),
			strlen($central), strlen($data), 0
		);
	}

	private static function dosTime(int $time): array {
		$d = getdate(max($time, 315532800));

		return [
			($d['hours'] << 11) | ($d['minutes'] << 5) | intdiv($d['seconds'], 2),
			(($d['year'] - 1980) << 9) | ($d['mon'] << 5) | $d['mday']
		];
	}
}
