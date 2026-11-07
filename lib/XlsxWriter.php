<?php declare(strict_types = 0);
/*
** MIB Browser module for Zabbix 8.0
** Minimal Excel (.xlsx, Office Open XML) writer: worksheets of strings and numbers, bold frozen header row with
** filter. No PHP extension needed besides the ones of Zabbix.
*/

namespace Modules\MibBrowser\Lib;

class XlsxWriter {

	private array $sheets = [];

	/**
	 * Add a worksheet.
	 *
	 * @param string $name    Sheet name (31 characters max, without []:*?/\).
	 * @param array  $rows    Rows of cells. PHP int/float become numbers, everything else text.
	 * @param bool   $header  First row is a header (bold, frozen, with filter).
	 * @param array  $widths  Column widths (characters).
	 */
	public function addSheet(string $name, array $rows, bool $header = true, array $widths = []): void {
		$name = mb_substr(trim(preg_replace('/[\[\]:*?\/\\\\]+/', ' ', $name)), 0, 31);

		$this->sheets[] = [
			'name' => $name !== '' ? $name : 'Sheet'.(count($this->sheets) + 1),
			'rows' => array_values($rows),
			'header' => $header,
			'widths' => $widths
		];
	}

	public function build(): string {
		if (!$this->sheets) {
			$this->addSheet('Sheet1', []);
		}

		$zip = new ZipWriter();
		$count = count($this->sheets);

		$types = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n"
			.'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
			.'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
			.'<Default Extension="xml" ContentType="application/xml"/>'
			.'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
			.'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
			.'<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
			.'<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>';

		for ($i = 1; $i <= $count; $i++) {
			$types .= '<Override PartName="/xl/worksheets/sheet'.$i.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
		}

		$zip->add('[Content_Types].xml', $types.'</Types>');

		$zip->add('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n"
			.'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			.'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
			.'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
			.'<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
			.'</Relationships>'
		);

		$zip->add('docProps/core.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n"
			.'<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" '
			.'xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" '
			.'xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
			.'<dc:creator>Zabbix MIB Browser</dc:creator>'
			.'<dcterms:created xsi:type="dcterms:W3CDTF">'.gmdate('Y-m-d\TH:i:s\Z').'</dcterms:created>'
			.'</cp:coreProperties>'
		);

		$zip->add('docProps/app.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n"
			.'<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties">'
			.'<Application>Zabbix MIB Browser</Application></Properties>'
		);

		$workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n"
			.'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
			.'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
		$rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n"
			.'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
		$names = '';

		foreach ($this->sheets as $i => $sheet) {
			$n = $i + 1;
			$workbook .= '<sheet name="'.self::esc($sheet['name']).'" sheetId="'.$n.'" r:id="rId'.$n.'"/>';
			$rels .= '<Relationship Id="rId'.$n.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$n.'.xml"/>';

			if ($sheet['header'] && $sheet['rows']) {
				$names .= '<definedName name="_xlnm._FilterDatabase" localSheetId="'.$i.'" hidden="1">\''
					.self::esc(str_replace("'", "''", $sheet['name'])).'\'!'.self::range($sheet).'</definedName>';
			}

			$zip->add('xl/worksheets/sheet'.$n.'.xml', self::sheetXml($sheet));
		}

		$workbook .= '</sheets>'.($names !== '' ? '<definedNames>'.$names.'</definedNames>' : '').'</workbook>';
		$rels .= '<Relationship Id="rId'.($count + 1).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
			.'</Relationships>';

		$zip->add('xl/workbook.xml', $workbook);
		$zip->add('xl/_rels/workbook.xml.rels', $rels);

		// Style 0: default, style 1: bold header.
		$zip->add('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n"
			.'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
			.'<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font>'
			.'<font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
			.'<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
			.'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
			.'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
			.'<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
			.'<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs>'
			.'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
			.'</styleSheet>'
		);

		return $zip->build();
	}

	private static function sheetXml(array $sheet): string {
		$xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n"
			.'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
			.'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';

		if ($sheet['header'] && $sheet['rows']) {
			$xml .= '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" '
				.'state="frozen"/></sheetView></sheetViews>';
		}

		if ($sheet['widths']) {
			$xml .= '<cols>';

			foreach (array_values($sheet['widths']) as $i => $width) {
				$xml .= '<col min="'.($i + 1).'" max="'.($i + 1).'" width="'.(float) $width.'" customWidth="1"/>';
			}

			$xml .= '</cols>';
		}

		$xml .= '<sheetData>';

		foreach ($sheet['rows'] as $r => $row) {
			$xml .= '<row r="'.($r + 1).'">';
			$style = ($sheet['header'] && $r == 0) ? ' s="1"' : '';

			foreach (array_values($row) as $c => $value) {
				$ref = self::column($c).($r + 1);

				if ((is_int($value) || is_float($value)) && is_finite((float) $value)) {
					$xml .= '<c r="'.$ref.'"'.$style.'><v>'.$value.'</v></c>';
				}
				elseif ($value !== null && $value !== '') {
					$xml .= '<c r="'.$ref.'"'.$style.' t="inlineStr"><is><t xml:space="preserve">'
						.self::esc(mb_substr((string) $value, 0, 32767)).'</t></is></c>';
				}
			}

			$xml .= '</row>';
		}

		$xml .= '</sheetData>';

		if ($sheet['header'] && $sheet['rows']) {
			$xml .= '<autoFilter ref="'.self::range($sheet, false).'"/>';
		}

		return $xml.'</worksheet>';
	}

	private static function range(array $sheet, bool $absolute = true): string {
		$columns = max(1, max(array_map('count', $sheet['rows'])));
		$last = self::column($columns - 1);
		$rows = count($sheet['rows']);

		return $absolute ? '$A$1:$'.$last.'$'.$rows : 'A1:'.$last.$rows;
	}

	/**
	 * Column letters: 0 => A, 25 => Z, 26 => AA.
	 */
	public static function column(int $index): string {
		$name = '';

		for ($index++; $index > 0; $index = intdiv($index - 1, 26)) {
			$name = chr(65 + ($index - 1) % 26).$name;
		}

		return $name;
	}

	/**
	 * Escape for XML, removing characters not allowed in XML 1.0.
	 */
	public static function esc(string $value): string {
		if (!mb_check_encoding($value, 'UTF-8')) {
			$value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
		}

		$value = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $value);

		return htmlspecialchars($value, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
	}
}
