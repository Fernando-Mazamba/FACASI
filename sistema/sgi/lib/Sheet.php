<?php
declare(strict_types=1);

/** Leitura de folhas CSV e XLSX (primeira folha) sem bibliotecas externas. */
class Sheet
{
    /** @return array<int,array<int,string>> */
    public static function read(string $path, string $originalName): array
    {
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $head = (string)file_get_contents($path, false, null, 0, 4);
        if ($ext === 'xlsx' || $head === "PK\x03\x04") {
            return self::readXlsx($path);
        }
        if ($ext === 'xls') {
            throw new HttpError(415, 'O formato .xls (Excel 97-2003) não é suportado. Guarde como .xlsx ou .csv.', 'formato');
        }
        return self::readCsv($path);
    }

    /** @return array<int,array<int,string>> */
    public static function readCsv(string $path): array
    {
        $raw = (string)file_get_contents($path);
        if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0) {
            $raw = substr($raw, 3);
        }
        if (!mb_check_encoding($raw, 'UTF-8')) {
            $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
        }
        $first = strtok($raw, "\n") ?: '';
        $delim = substr_count($first, ';') >= substr_count($first, ',') ? ';' : ',';
        if (substr_count($first, "\t") > substr_count($first, $delim)) {
            $delim = "\t";
        }
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, $raw);
        rewind($fh);
        $rows = [];
        while (($r = fgetcsv($fh, 0, $delim, '"', '\\')) !== false) {
            if ($r === [null]) {
                continue;
            }
            $rows[] = array_map(function ($v) {
                return trim((string)$v);
            }, $r);
        }
        fclose($fh);
        return $rows;
    }

    /** @return array<int,array<int,string>> */
    public static function readXlsx(string $path): array
    {
        if (!class_exists('ZipArchive')) {
            throw new HttpError(500, 'A extensão PHP "zip" é necessária para ler ficheiros .xlsx. Use .csv ou ative a extensão.', 'zip');
        }
        $z = new ZipArchive();
        if ($z->open($path) !== true) {
            throw new HttpError(422, 'Ficheiro .xlsx inválido.', 'xlsx_invalido');
        }
        $shared = [];
        $ss = $z->getFromName('xl/sharedStrings.xml');
        if ($ss !== false) {
            $x = self::xml($ss);
            foreach ($x->si as $si) {
                if (isset($si->t)) {
                    $shared[] = (string)$si->t;
                } else {
                    $s = '';
                    foreach ($si->r as $run) {
                        $s .= (string)$run->t;
                    }
                    $shared[] = $s;
                }
            }
        }
        // primeira folha segundo workbook.xml + relações
        $sheetPath = 'xl/worksheets/sheet1.xml';
        $wb = $z->getFromName('xl/workbook.xml');
        $rels = $z->getFromName('xl/_rels/workbook.xml.rels');
        if ($wb !== false && $rels !== false) {
            $wbx = self::xml($wb);
            $first = $wbx->sheets->sheet[0] ?? null;
            if ($first) {
                $rid = (string)$first->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
                foreach (self::xml($rels)->Relationship as $rel) {
                    if ((string)$rel['Id'] === $rid) {
                        $t = ltrim((string)$rel['Target'], '/');
                        $sheetPath = strpos($t, 'xl/') === 0 ? $t : 'xl/' . $t;
                    }
                }
            }
        }
        $sx = $z->getFromName($sheetPath);
        $z->close();
        if ($sx === false) {
            throw new HttpError(422, 'Não foi encontrada nenhuma folha no ficheiro .xlsx.', 'xlsx_invalido');
        }
        $rows = [];
        foreach (self::xml($sx)->sheetData->row as $row) {
            $r = [];
            foreach ($row->c as $c) {
                $ref = (string)$c['r'];
                $col = self::colIndex(preg_replace('/\d+/', '', $ref) ?: 'A');
                $t = (string)$c['t'];
                if ($t === 's') {
                    $v = $shared[(int)$c->v] ?? '';
                } elseif ($t === 'inlineStr') {
                    $v = (string)$c->is->t;
                } else {
                    $v = (string)$c->v;
                }
                $r[$col] = trim($v);
            }
            if ($r) {
                $max = max(array_keys($r));
                $full = [];
                for ($i = 0; $i <= $max; $i++) {
                    $full[] = $r[$i] ?? '';
                }
                $rows[] = $full;
            }
        }
        return $rows;
    }

    private static function xml(string $s): SimpleXMLElement
    {
        $prev = libxml_use_internal_errors(true);
        $x = simplexml_load_string($s, 'SimpleXMLElement', LIBXML_NONET);
        libxml_use_internal_errors($prev);
        if ($x === false) {
            throw new HttpError(422, 'Ficheiro .xlsx corrompido.', 'xlsx_invalido');
        }
        return $x;
    }

    private static function colIndex(string $letters): int
    {
        $n = 0;
        foreach (str_split(strtoupper($letters)) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }
        return $n - 1;
    }

    /** Converte datas: AAAA-MM-DD, DD/MM/AAAA, DD.MM.AAAA ou série numérica do Excel. */
    public static function date(string $v): ?string
    {
        $v = trim($v);
        if ($v === '') {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
            return $v;
        }
        if (preg_match('#^(\d{1,2})[/.-](\d{1,2})[/.-](\d{4})$#', $v, $m)) {
            return checkdate((int)$m[2], (int)$m[1], (int)$m[3]) ? sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]) : null;
        }
        if (is_numeric($v) && (float)$v > 20000 && (float)$v < 80000) {
            return gmdate('Y-m-d', (int)round(((float)$v - 25569) * 86400));
        }
        return null;
    }

    public static function key(string $h): string
    {
        $h = mb_strtolower(trim($h));
        $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $h);
        $h = $t !== false ? $t : $h;
        return trim((string)preg_replace('/[^a-z0-9]+/', '_', $h), '_');
    }
}
