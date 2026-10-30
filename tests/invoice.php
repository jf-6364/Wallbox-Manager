<?php
declare(strict_types=1);
use WBM\{Invoice, BillingPdf, Schema, Form};

function invoiceProfile(array $changes = []): array {
    return array_replace(Invoice::profile([], []), ['SenderName' => 'Stromservice Süd', 'SenderStreet' => 'Solarweg 12', 'SenderZip' => '01234',
        'SenderCity' => 'Musterstadt', 'RecipientName' => 'Musterkunde', 'RecipientStreet' => 'Ladeweg 7', 'RecipientZip' => '54321',
        'RecipientCity' => 'Beispielstadt', 'CustomerNumber' => 'K-100', 'BankName' => 'Beispielbank', 'AccountHolder' => 'Stromservice Süd',
        'IBAN' => 'DE00 0000 0000 0000 0000 00', 'BIC' => 'BEISPIELXXX', 'Phone' => '+49 123 456789', 'Email' => 'kontakt@example.org',
        'Website' => 'https://example.org', 'LogoText' => 'SS'], $changes);
}

test('Rechnung: Umsatzsteuer einbeziehen oder ignorieren verändert keinen Endpreis', function () {
    $amounts = Invoice::amounts(1.89, 'included', 19.0);
    closeTo($amounts['net'], 1.59); closeTo($amounts['tax'], .30); closeTo($amounts['gross'], 1.89);
    foreach (['none', 'small'] as $mode) {
        $amounts = Invoice::amounts(1.89, $mode, 19.0);
        closeTo($amounts['net'], 1.89); closeTo($amounts['tax'], 0.0); closeTo($amounts['gross'], 1.89);
    }
    foreach ([0.0, .01, .02, .70, 1.89, 5.01, 119.0, 999.99] as $gross) foreach ([0.0, 7.0, 19.0, 100.0] as $rate) {
        $a = Invoice::amounts($gross, 'included', $rate); closeTo($a['net'] + $a['tax'], $gross);
    }
});
test('Rechnung: Nummernkreis zählt über alle Wallboxen fort und verwendet reservierte Nummern nicht erneut', function () {
    $r = finishedBill()['last']; $profile = invoiceProfile(['Prefix' => 'WB', 'StartNumber' => 42]); $sequence = 0;
    $one = Invoice::issue($r, $profile, $sequence, 1793358000); check($one['invoice']['number'] === 'WB-2026-000042');
    $two = Invoice::issue($r, $profile, $sequence, 1793358000); check($two['invoice']['number'] === 'WB-2026-000043');
    $profile['StartNumber'] = 1; $three = Invoice::issue($r, $profile, $sequence, 1793358000);
    check($three['invoice']['number'] === 'WB-2026-000044');
    $retry = Invoice::issue($one, invoiceProfile(['SenderName' => 'NEU']), $sequence, 1893358000);
    check($retry['invoice'] === $one['invoice']); check($sequence === 44);
});
test('Rechnung: Zahlungsfrist folgt Kalendertagen auch beim Wechsel der Sommerzeit', function () {
    $now = (new DateTimeImmutable('2026-10-24 12:00:00', new DateTimeZone('Europe/Berlin')))->getTimestamp();
    $sequence = 0; $record = Invoice::issue(finishedBill()['last'], invoiceProfile(['PaymentDays' => 2]), $sequence, $now);
    check(BillingPdf::date($record['invoice']['dueAt'], 'Europe/Berlin') === '26.10.2026 12:00:00');
});
test('Rechnung: Rechnungseinstellungen sind unter Abrechnung verschachtelt; Empfänger bleiben je Wallbox getrennt', function () {
    $flat = Schema::defaults(); $flat['W1InvoiceRecipientName'] = 'Kunde A'; $flat['W2InvoiceRecipientName'] = 'Kunde B';
    $form = Form::build($flat, [], '');
    $billing = array_values(array_filter($form['elements'], fn($g) => ($g['caption'] ?? '') === 'Abrechnung'))[0];
    $invoices = array_values(array_filter($billing['items'], fn($g) => ($g['caption'] ?? '') === 'Rechnung'));
    check(count($invoices) === 1); check(str_contains(json_encode($invoices[0]), 'InvoiceSenderName'));
    check(!str_contains(json_encode($invoices[0]), 'W1InvoiceRecipientName'));
    foreach ([1, 2] as $id) {
        $wallbox = array_values(array_filter($form['elements'], fn($g) => ($g['name'] ?? '') === 'WallboxPanel' . $id))[0];
        $boxBilling = array_values(array_filter($wallbox['items'], fn($g) => $g['caption'] === 'Abrechnung'))[0];
        $boxInvoice = array_values(array_filter($boxBilling['items'], fn($g) => ($g['caption'] ?? '') === 'Rechnung'))[0];
        check(str_contains(json_encode($boxInvoice), 'W' . $id . 'InvoiceRecipientName'));
        check(Invoice::profile(Schema::unpack($flat)['global'], Schema::unpack($flat)['boxes'][$id])['RecipientName'] === ($id === 1 ? 'Kunde A' : 'Kunde B'));
    }
});
test('Rechnung: PDF enthält Adressen, Bank, Nummer und nur den ausgewählten Steuerhinweis', function () {
    foreach (['none', 'included', 'small'] as $mode) {
        $seq = 0; $r = Invoice::issue(finishedBill()['last'], invoiceProfile(['TaxMode' => $mode]), $seq, 1793358000);
        $pdf = BillingPdf::create($r);
        foreach (['RECHNUNG', 'RE-2026-000001', 'Solarweg 12', '01234 Musterstadt', 'Musterkunde', 'Ladeweg 7', 'Beispielbank', 'BEISPIELXXX', 'K-100', 'DANKE!'] as $text) check(str_contains($pdf, $text), $text);
        check(str_contains($pdf, chr(167) . ' 19 UStG') === ($mode === 'small'));
        check(str_contains($pdf, 'USt 19,00 %') === ($mode === 'included'));
        if ($mode === 'included') { check(str_contains($pdf, '0,59 ' . chr(128))); check(str_contains($pdf, '0,11 ' . chr(128))); }
    }
});
test('Rechnung: lange Adressen und Zahlungstexte bleiben vollständig auf zusätzlichen PDF-Seiten erhalten', function () {
    $seq = 0; $r = Invoice::issue(finishedBill()['last'], invoiceProfile(['RecipientName' => str_repeat('Langer Kundenname ', 150) . 'KUNDENENDE',
        'PaymentText' => str_repeat('Ausführlicher Zahlungshinweis ', 150) . 'ZAHLUNGSENDE']), $seq, 1793358000);
    $pdf = BillingPdf::create($r); check(str_contains($pdf, 'KUNDENENDE')); check(str_contains($pdf, 'ZAHLUNGSENDE'));
    preg_match('/\/Type \/Pages \/Count ([0-9]+)/', $pdf, $m); check((int)$m[1] > 1);
});
test('Adapter Rechnung: Daten und Nummer bleiben bei Schreibfehler / späteren Änderungen unverändert; PDFs sammeln sich', function () {
    [$m] = billingFixture();
    $m->properties['InvoiceSenderName'] = 'Absender ALT'; $m->properties['W1InvoiceRecipientName'] = 'Empfänger ALT';
    $m->properties['InvoiceTaxMode'] = 'included'; $m->properties['InvoiceTaxRate'] = 19.0; $m->ApplyChanges();
    seedIdleBill($m); Kernel::$failures['media'] = 'Datenträger voll'; $m->Control();
    $s = runtimeOf($m); $frozen = $s['billing'][1]['last']['invoice'];
    check($frozen['profile']['SenderName'] === 'Absender ALT'); check($frozen['sequence'] === 1); check($s['invoiceSequence'] === 1);
    $m->properties['InvoiceSenderName'] = 'Absender NEU'; $m->properties['W1InvoiceRecipientName'] = 'Empfänger NEU'; $m->properties['InvoiceTaxRate'] = 7.0;
    $deviceCalls = count(Kernel::$calls);
    $m->ApplyChanges(); check(count(Kernel::$calls) === $deviceCalls, 'Rechnungsdaten dürfen keine Gerätebefehle auslösen');
    unset(Kernel::$failures['media']); $m->Control();
    $s = runtimeOf($m); check($s['billing'][1]['last']['invoice'] === $frozen); check($s['invoiceSequence'] === 1);
    $oldMedia = $s['billing'][1]['last']['mediaID']; $oldPDF = IPS_GetMediaContent($oldMedia);
    $m->ResetEnergy('ENERGIE ZURÜCKSETZEN'); check(runtimeOf($m)['invoiceSequence'] === 1);
    seedIdleBill($m); $m->Control(); $s = runtimeOf($m);
    check(count(Kernel::$media) === 2); check(IPS_GetMediaContent($oldMedia) === $oldPDF);
    check($s['billing'][1]['last']['invoice']['sequence'] === 2);
    check($s['billing'][1]['last']['invoice']['profile']['SenderName'] === 'Absender NEU');
    closeTo($s['billing'][1]['last']['invoice']['profile']['TaxRate'], 7.0);
    check($s['billing'][1]['last']['invoice']['profile']['RecipientName'] === 'Empfänger NEU');
});
