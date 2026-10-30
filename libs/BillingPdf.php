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

    private static function width(string $value, float $size, bool $bold = false): float
    {
        $upper = array_combine(str_split('ABCDEFGHIJKLMNOPQRSTUVWXYZ'), [667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,667,778,722,667,611,722,667,944,667,667,611]);
        $lower = array_combine(str_split('abcdefghijklmnopqrstuvwxyz'), [556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,556,556,333,500,278,556,500,722,500,500,500]);
        $other = [' '=>278, '.'=>278, ','=>278, ':'=>278, ';'=>278, '-'=>333, '/'=>278, '('=>333, ')'=>333, '@'=>1015, '%'=>889,
            '€'=>556, 'ä'=>556, 'ö'=>556, 'ü'=>556, 'Ä'=>667, 'Ö'=>778, 'Ü'=>722, 'ß'=>611, '–'=>556, '—'=>1000];
        $width = 0;
        foreach (preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) $width += $upper[$ch] ?? $lower[$ch] ?? $other[$ch] ?? 600;
        return $width / 1000 * $size * ($bold ? 1.06 : 1);
    }

    private static function lines(string $value, float $width, int $size, bool $bold = false): array
    {
        $chars = preg_split('//u', trim(preg_replace('/\s+/u', ' ', $value) ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $out = []; $line = ''; $lastSpace = -1; $lineChars = [];
        foreach ($chars as $ch) {
            $lineChars[] = $ch;
            $line = implode('', $lineChars);
            if ($ch === ' ') $lastSpace = count($lineChars) - 1;
            if (self::width($line, $size, $bold) <= $width || count($lineChars) === 1) continue;
            $cut = $lastSpace > 0 ? $lastSpace : count($lineChars) - 1;
            $out[] = rtrim(implode('', array_slice($lineChars, 0, $cut)));
            $lineChars = array_slice($lineChars, $lastSpace > 0 ? $cut + 1 : $cut);
            $lastSpace = -1;
        }
        $out[] = trim(implode('', $lineChars));
        return $out;
    }

    public static function create(array $record): string
    {
        if (!isset($record['invoice']['number'])) {
            $sequence = 0;
            $record = Invoice::issue($record, Invoice::profile([], []), $sequence, $record['ended']);
        }
        $invoice = $record['invoice']; $profile = $invoice['profile'];
        $money = static fn(float $v): string => number_format($v, 2, ',', '') . ' €';
        $day = static fn(float $time): string => substr(self::date($time, $record['timezone']), 0, 10);
        $pages = [];
        $newPage = static function (bool $continuation = false) use (&$pages, $invoice, $profile): int {
            $stream = "0.949 0.937 0.929 rg 0 0 595 842 re f\n0.06 0.06 0.06 rg 0.4 0.38 0.37 RG 0.5 w\n";
            $stream .= self::text(54, 780, 'RECHNUNG', 30, true);
            if ($continuation) $stream .= self::text(54, 752, $invoice['number'] . ' · Fortsetzung', 9);
            if (trim($profile['LogoText']) !== '') {
                $stream .= "527 788 m 527 802.91 514.91 815 500 815 c 485.09 815 473 802.91 473 788 c 473 773.09 485.09 761 500 761 c 514.91 761 527 773.09 527 788 c S\n";
                $size = min(22, 54 / max(.01, self::width($profile['LogoText'], 1)));
                $x = 500 - self::width($profile['LogoText'], $size) / 2;
                $stream .= 'BT /F3 ' . $size . ' Tf 1 0 0 1 ' . $x . ' 782 Tm (' . self::encoded($profile['LogoText']) . ") Tj ET\n";
            }
            $pages[] = $stream;
            return count($pages) - 1;
        };
        $write = static function (int &$page, float $x, float &$y, string $value, float $width = 230, int $size = 10, bool $bold = false, float $floor = 180) use (&$pages, $newPage): void {
            foreach (self::lines($value, $width, $size, $bold) as $line) {
                if ($y < $floor) { $page = $newPage(true); $y = 724; }
                $pages[$page] .= self::text($x, $y, $line, $size, $bold); $y -= $size + 4;
            }
        };
        $page = $newPage(); $leftPage = $rightPage = $page; $left = $right = 734.0;
        $write($leftPage, 54, $left, 'Datum:', 235, 8); $write($leftPage, 54, $left, $day($invoice['issuedAt']), 235);
        $pages[$leftPage] .= "54 " . ($left + 2) . ' m 288 ' . ($left + 2) . " l S\n"; $left -= 14;
        $write($leftPage, 54, $left, 'Nr. Rechnung:', 235, 8); $write($leftPage, 54, $left, $invoice['number'], 235);
        $pages[$leftPage] .= "54 " . ($left + 2) . ' m 288 ' . ($left + 2) . " l S\n"; $left -= 14;
        $write($leftPage, 54, $left, 'Rechnungsempfänger', 235, 8); $left -= 4;
        foreach (['RecipientName', 'RecipientStreet'] as $key) if (trim($profile[$key]) !== '') $write($leftPage, 54, $left, $profile[$key], 235, 10, $key === 'RecipientName');
        $city = trim($profile['RecipientZip'] . ' ' . $profile['RecipientCity']);
        if ($city !== '') $write($leftPage, 54, $left, $city, 235);
        if (trim($profile['RecipientCountry']) !== '') $write($leftPage, 54, $left, $profile['RecipientCountry'], 235);
        foreach (['CustomerNumber' => 'Kundennr.: ', 'Reference' => 'Referenz: '] as $key => $label) if (trim($profile[$key]) !== '') $write($leftPage, 54, $left, $label . $profile[$key], 235, 8);
        foreach (['SenderName', 'SenderStreet'] as $key) if (trim($profile[$key]) !== '') $write($rightPage, 326, $right, $profile[$key], 215, 10, $key === 'SenderName');
        $city = trim($profile['SenderZip'] . ' ' . $profile['SenderCity']);
        if ($city !== '') $write($rightPage, 326, $right, $city, 215);
        if (trim($profile['SenderCountry']) !== '') $write($rightPage, 326, $right, $profile['SenderCountry'], 215);
        $right -= 18;
        foreach (['PaymentMethod' => 'Zahlungsmethode: ', 'BankName' => 'Bank: ', 'AccountHolder' => 'Kontoinhaber: ', 'IBAN' => 'IBAN: ', 'BIC' => 'BIC: '] as $key => $label) {
            if (trim($profile[$key]) !== '') $write($rightPage, 326, $right, $label . $profile[$key], 215, 9);
        }
        $page = max($leftPage, $rightPage);
        $y = min($leftPage === $page ? $left : 724, $rightPage === $page ? $right : 724) - 16;
        $write($page, 54, $y, 'Ladezeitraum: ' . self::date($record['started'], $record['timezone']) . ' – ' . self::date($record['ended'], $record['timezone']) . ' (' . $record['timezone'] . ')', 487, 8);
        $write($page, 54, $y, $record['name'] . ' · ' . number_format($record['totalEnergy'], 2, ',', '') . ' kWh (aus gemessener Ladeleistung berechnet)', 487, 8);
        $y -= 6;
        $columns = [54, 121, 321, 405, 475, 541];
        $tableRow = static function (int $p, float $top, array $values, bool $header = false) use (&$pages, $columns): void {
            $bottom = $top - 28;
            $pages[$p] .= '54 ' . $bottom . " 487 28 re S\n";
            foreach (array_slice($columns, 1, -1) as $x) $pages[$p] .= "$x $bottom m $x $top l S\n";
            foreach ($values as $index => $value) {
                $size = 8; $x = $columns[$index] + 7;
                if (!$header && $index >= 2) $x = $columns[$index + 1] - 7 - self::width($value, $size);
                $pages[$p] .= self::text($x, $top - 18, $value, $size, $header);
            }
        };
        if ($y < 266) { $page = $newPage(true); $y = 724; }
        $tableRow($page, $y, ['Datum', 'Artikelbeschreibung', 'Preis/kWh', 'Menge', 'Gesamt'], true); $y -= 28; $rows = 0;
        $labels = ['pv' => 'PV-Überschussladen', 'normal' => 'Normalladen', 'unassigned' => 'Tarif ungeklärt'];
        foreach ($record['lines'] as $row) {
            if ($y - 28 < 210) {
                $page = $newPage(true); $y = 724; $rows = 0;
                $tableRow($page, $y, ['Datum', 'Artikelbeschreibung', 'Preis/kWh', 'Menge', 'Gesamt'], true); $y -= 28;
            }
            $tableRow($page, $y, [$day($record['ended']), $labels[$row['mode']], $row['mode'] === 'unassigned' ? 'offen' : $money($row['price']),
                number_format($row['energy'], 2, ',', '') . ' kWh', $row['mode'] === 'unassigned' ? 'offen' : $money($row['cost'])]);
            $y -= 28; $rows++;
        }
        while ($rows < 8 && $y - 28 >= 210) { $tableRow($page, $y, ['', '', '', '', '']); $y -= 28; $rows++; }
        $taxMode = $profile['TaxMode']; $amounts = $invoice['amounts'];
        $totals = [['Zwischensumme' . ($taxMode === 'included' ? ' (netto)' : ''), $money($amounts['net'])]];
        if ($taxMode === 'included') $totals[] = ['USt ' . number_format($profile['TaxRate'], 2, ',', '') . ' %', $money($amounts['tax'])];
        $totals[] = [$record['unassignedEnergy'] > 0 ? 'Bekannter Gesamtbetrag' : 'Gesamtbetrag', $money($amounts['gross'])];
        $note = $taxMode === 'small' ? 'Gemäß § 19 UStG wird keine Umsatzsteuer ausgewiesen.' :
            ($taxMode === 'included' ? 'Alle Positionspreise und Beträge sind Endpreise inklusive Umsatzsteuer.' : 'Kein gesonderter Umsatzsteuerausweis.');
        $payment = trim($profile['PaymentText']);
        if ($payment === '') {
            if ($record['unassignedEnergy'] > 0) $payment = 'Tarifzuordnung ungeklärt. Bitte prüfen Sie die offenen Positionen vor der Zahlung.';
            elseif ($amounts['gross'] === 0.0) $payment = 'Für diesen Ladegang fällt kein zu zahlender Betrag an.';
            else $payment = ($profile['PaymentDays'] === 0 ? 'Zahlbar sofort.' : 'Zahlbar bis zum ' . $day($invoice['dueAt']) . '.') . ' Verwendungszweck: ' . $invoice['number'] . '.';
        }
        $notes = [$note, $payment];
        foreach ($record['warnings'] as $warning) $notes[] = 'Hinweis: ' . $warning;
        if ($record['unassignedEnergy'] > 0) $notes[] = 'Unzugeordnet: ' . number_format($record['unassignedEnergy'], 2, ',', '') . ' kWh. Betrag noch offen; Rechnung prüfen.';
        $contacts = [];
        foreach (['Phone' => 'Tel.: ', 'Email' => '', 'Website' => '', 'TaxNumber' => 'Steuernr.: ', 'VATID' => 'USt-IdNr.: ', 'FooterText' => ''] as $key => $label) {
            if (trim($profile[$key]) !== '') foreach (self::lines($label . $profile[$key], 235, 8) as $line) $contacts[] = $line;
        }
        $noteLines = 0; foreach ($notes as $value) $noteLines += count(self::lines($value, 210, 8)) + 1;
        $minimumTail = max(count($contacts) * 12 + 58, min(620, count($totals) * 28 + $noteLines * 12 + 30));
        if ($y - 18 - $minimumTail < 34) { $page = $newPage(true); $y = 724; }
        $tail = $y - 18;
        $thanksY = min($tail - 4, max(132, count($contacts) * 12 + 72));
        $pages[$page] .= self::text(54, $thanksY, 'DANKE!', 24);
        $contactY = $thanksY - 30; $contactPage = $page;
        foreach ($contacts as $value) $write($contactPage, 54, $contactY, $value, 235, 8, false, 36);
        foreach ($totals as $index => [$label, $value]) {
            $pages[$page] .= '321 ' . ($tail - 26) . " 220 26 re S\n";
            $bold = $index === count($totals) - 1;
            $pages[$page] .= self::text(328, $tail - 17, $label, 9, $bold)
                . self::text(534 - self::width($value, 9, $bold), $tail - 17, $value, 9, $bold);
            $tail -= 26;
        }
        $tail -= 16;
        foreach ($notes as $value) { $write($page, 326, $tail, $value, 210, 8, false, 36); $tail -= 8; }
        foreach ($pages as $index => &$stream) $stream .= self::text(54, 16, $invoice['number'] . ' · Seite ' . ($index + 1) . ' / ' . count($pages), 8);
        unset($stream);
        $objects = [1 => '<< /Type /Catalog /Pages 2 0 R >>', 2 => '',
            3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
            5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Times-Italic /Encoding /WinAnsiEncoding >>'];
        $kids = [];
        foreach ($pages as $index => $page) {
            $pageID = 6 + $index * 2; $contentID = $pageID + 1; $kids[] = $pageID . ' 0 R';
            $objects[$pageID] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R /F2 4 0 R /F3 5 0 R >> >> /Contents ' . $contentID . ' 0 R >>';
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
