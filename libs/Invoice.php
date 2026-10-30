<?php
declare(strict_types=1);
namespace WBM;

final class Invoice
{
    public static function profile(array $global, array $box): array
    {
        $out = [];
        foreach (Schema::invoice() as $key => $definition) $out[substr($key, 7)] = $global[$key] ?? $definition[0];
        foreach (Schema::invoiceRecipient() as $key => $definition) $out[substr($key, 7)] = $box[$key] ?? $definition[0];
        return $out;
    }

    public static function amounts(float $gross, string $mode, float $rate): array
    {
        if (!is_finite($gross) || $gross < 0 || !is_finite($rate) || $rate < 0 || $rate > 100 || !in_array($mode, ['none', 'small', 'included'], true)) throw new \InvalidArgumentException('Ungültige Rechnungsbeträge / Umsatzsteuerdarstellung');
        $gross = round($gross, 2);
        $net = $mode === 'included' ? round($gross / (1 + $rate / 100), 2) : $gross;
        return ['net' => $net, 'tax' => round($gross - $net, 2), 'gross' => $gross];
    }

    /** Assign once and persist before writing a PDF. Retries never change the snapshot. */
    public static function issue(array $record, array $fallback, int &$sequence, float $now): array
    {
        if (isset($record['invoice']['number'])) return $record;
        $profile = ($record['invoiceProfile'] ?? []) ?: $fallback;
        $profile = array_replace(self::profile([], []), $profile);
        $amounts = self::amounts((float)$record['totalCost'], $profile['TaxMode'], (float)$profile['TaxRate']);
        $zone = new \DateTimeZone($record['timezone']);
        $date = (new \DateTimeImmutable('@' . (int)$now))->setTimezone($zone);
        if ($profile['StartNumber'] < 1 || $profile['StartNumber'] > 999999999 || $profile['PaymentDays'] < 0 || $profile['PaymentDays'] > 3650 || trim($profile['Prefix']) === '') throw new \InvalidArgumentException('Ungültiger Nummernkreis / Zahlungsfrist');
        $sequence = max($sequence + 1, $profile['StartNumber']);
        $record['invoice'] = ['profile' => $profile, 'number' => rtrim(trim($profile['Prefix']), '-') . '-' . $date->format('Y') . '-' . str_pad((string)$sequence, 6, '0', STR_PAD_LEFT),
            'sequence' => $sequence, 'issuedAt' => $now, 'dueAt' => (float)$date->modify('+' . $profile['PaymentDays'] . ' days')->getTimestamp(), 'amounts' => $amounts];
        return $record;
    }
}
