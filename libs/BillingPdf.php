<?php
declare(strict_types=1);
namespace WBM;

/** Dependency-free A4 PDF with WinAnsi base fonts, byte-accurate xref and pagination. */
final class BillingPdf
{
    public static function date(float $time, string $timezone): string
    {
        return (new \DateTimeImmutable('@' . (int)$time))->setTimezone(new \DateTimeZone($timezone))->format('d.m.Y H:i:s');
    }

    private static function encoded(string $value): string
    {
        $value = preg_replace_callback('/[^\x00-\x7f]/u', static function (array $m): string {
            $special = ['€' => "\x80", '–' => '-', '—' => '-', '•' => "\x95"];
            if (isset($special[$m[0]])) return $special[$m[0]];
            if (strlen($m[0]) === 2) {
                $point = (ord($m[0][0]) & 31) * 64 + (ord($m[0][1]) & 63);
                if ($point >= 160 && $point <= 255) return chr($point);
            }
            return '?';
        }, $value) ?? '';
        return strtr(preg_replace('/[\x00-\x1f\x7f]/', ' ', $value) ?? '', ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']);
    }

    private static function text(float $x, float $y, string $value, int $size = 11, bool $bold = false): string
    {
        return 'BT /' . ($bold ? 'F2' : 'F1') . ' ' . $size . ' Tf 1 0 0 1 ' . $x . ' ' . $y . ' Tm (' . self::encoded($value) . ") Tj ET\n";
    }

    private static function wrap(string $value, int $length = 88): array
    {
        $chars = preg_split('//u', preg_replace('/\s+/u', ' ', $value) ?? '', -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $lines = [];
        while (count($chars) > $length) {
            $cut = $length;
            for ($i = $length; $i >= (int)floor($length / 2); $i--) {
                if ($chars[$i] === ' ') { $cut = $i; break; }
            }
            $lines[] = implode('', array_slice($chars, 0, $cut));
            $chars = array_slice($chars, $cut);
            while ($chars && $chars[0] === ' ') array_shift($chars);
        }
        $lines[] = implode('', $chars);
        return $lines;
    }

    public static function create(array $record): string
    {
        $money = static fn(float $v): string => number_format($v, 2, ',', '') . ' €';
        $kwh = static fn(float $v): string => number_format($v, 2, ',', '') . ' kWh';
        $labels = ['pv' => 'PV-Überschussladen', 'normal' => 'Normalladen', 'unassigned' => 'Unzugeordnet'];
        $pages = []; $stream = ''; $y = 0;
        $newPage = static function () use (&$pages, &$stream, &$y, $record): void {
            if ($stream !== '') $pages[] = $stream;
            $stream = "0.05 0.65 0.56 RG 1 w 44 790 m 551 790 l S\n0.12 0.13 0.15 rg\n";
            $stream .= self::text(44, 809, 'Ladeabrechnung', 20);
            $stream .= self::text(44, 769, 'Wallbox Manager · ' . $record['id'], 9);
            $y = 742;
        };
        $line = static function (string $value, int $size = 11, bool $bold = false) use (&$stream, &$y, $newPage): void {
            // Even a name made entirely of wide glyphs must remain inside A4 margins.
            foreach (self::wrap($value, max(15, (int)floor(507 / $size))) as $part) {
                if ($y < 62) $newPage();
                $stream .= self::text(44, $y, $part, $size, $bold); $y -= 18;
            }
        };
        $newPage();
        $line($record['name'], 15, true); $y -= 8;
        $line('Beginn: ' . self::date($record['started'], $record['timezone']));
        $line('Ende:     ' . self::date($record['ended'], $record['timezone']));
        $line('Zeitzone: ' . $record['timezone'], 9);
        $line('Abschluss: ' . $record['reason'], 9); $y -= 18;
        $tableHeader = static function () use (&$stream, &$y): void {
            $stream .= self::text(44, $y, 'Abrechnungsmodus', 10, true) . self::text(245, $y, 'Energie', 10, true)
                . self::text(350, $y, 'Preis / kWh', 10, true) . self::text(465, $y, 'Betrag', 10, true);
            $y -= 10; $stream .= "0.75 0.75 0.75 RG 0.4 w 44 $y m 551 $y l S\n"; $y -= 22;
        };
        $tableHeader();
        foreach ($record['lines'] as $row) {
            if ($y < 95) { $newPage(); $tableHeader(); }
            $stream .= self::text(44, $y, $labels[$row['mode']], 10) . self::text(245, $y, $kwh($row['energy']), 10)
                . self::text(350, $y, $row['mode'] === 'unassigned' ? 'ungeklärt' : $money($row['price']), 10)
                . self::text(465, $y, $money($row['cost']), 10);
            $y -= 25;
        }
        $y -= 16;
        $line('PV-Energie: ' . $kwh($record['pvEnergy']) . '  |  Normalenergie: ' . $kwh($record['normalEnergy']));
        if ($record['unassignedEnergy'] > 0) $line('Unzugeordnete Energie: ' . $kwh($record['unassignedEnergy']) . ' - Betrag noch ungeklärt');
        $line('Gesamte Ladeenergie: ' . $kwh($record['totalEnergy']), 12);
        $line('Gesamtbetrag: ' . $money($record['totalCost']), 14, true); $y -= 18;
        $line('Energie aus gemessener Ladeleistung berechnet. Kurze Ladepausen gehören zum selben Ladegang.', 9);
        $line('PV-Tarif gilt bei wahrer PV-Boolvariable; sonst gilt der Normaltarif. Preiswechsel werden getrennt ausgewiesen. Beträge sind je Tarifposition auf Cent gerundet.', 9);
        foreach ($record['warnings'] as $warning) $line('Hinweis: ' . $warning, 9);
        if ($stream !== '') $pages[] = $stream;
        foreach ($pages as $index => &$page) $page .= self::text(44, 32, 'Ladeabrechnung · Seite ' . ($index + 1) . ' / ' . count($pages), 9);
        unset($page);
        $objects = [1 => '<< /Type /Catalog /Pages 2 0 R >>', 2 => '',
            3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>'];
        $kids = [];
        foreach ($pages as $index => $page) {
            $pageID = 5 + $index * 2; $contentID = $pageID + 1; $kids[] = $pageID . ' 0 R';
            $objects[$pageID] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents ' . $contentID . ' 0 R >>';
            $objects[$contentID] = '<< /Length ' . strlen($page) . ">>\nstream\n" . $page . 'endstream';
        }
        $objects[2] = '<< /Type /Pages /Count ' . count($pages) . ' /Kids [' . implode(' ', $kids) . '] >>';
        ksort($objects);
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n"; $offsets = [0];
        foreach ($objects as $id => $object) { $offsets[$id] = strlen($pdf); $pdf .= $id . " 0 obj\n" . $object . "\nendobj\n"; }
        $start = strlen($pdf); $pdf .= 'xref' . "\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
        foreach ($objects as $id => $_) $pdf .= sprintf("%010d 00000 n \n", $offsets[$id]);
        return $pdf . 'trailer' . "\n<< /Size " . (count($objects) + 1) . ' /Root 1 0 R >>' . "\nstartxref\n" . $start . "\n%%EOF\n";
    }
}
