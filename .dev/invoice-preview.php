<?php
declare(strict_types=1);
require __DIR__ . '/../libs/bootstrap.php';
// Fictitious sample for the invoice layout; never used by the installed module.
$p = array_replace(WBM\Invoice::profile([], []), [
    'SenderName'=>'Solar & Energie Mustermann', 'SenderStreet'=>'Sonnenstraße 12', 'SenderZip'=>'90443', 'SenderCity'=>'Nürnberg',
    'RecipientName'=>'Max Beispiel', 'RecipientStreet'=>'Mauerstraße 14', 'RecipientZip'=>'90443', 'RecipientCity'=>'Nürnberg',
    'CustomerNumber'=>'K-1001', 'Reference'=>'Fahrzeug / Garage', 'LogoText'=>'SM',
    'BankName'=>'Beispielbank', 'AccountHolder'=>'Solar & Energie Mustermann', 'IBAN'=>'DE00 0000 0000 0000 0000 00', 'BIC'=>'BEISPIELXXX',
    'Phone'=>'+49 123 456789', 'Email'=>'kontakt@example.org', 'Website'=>'www.example.org', 'TaxNumber'=>'123/456/78900',
    'Prefix'=>'MUSTER', 'TaxMode'=>'included', 'TaxRate'=>19.0, 'FooterText'=>'Vielen Dank für Ihr Vertrauen.'
]);
$r = ['id'=>'BEISPIEL', 'wallbox'=>1, 'name'=>'Wallbox 1 – Garage', 'started'=>1793354400.0, 'ended'=>1793361600.0, 'timezone'=>'Europe/Berlin',
    'reason'=>'Eingestellte Ladepause erreicht', 'lines'=>[['mode'=>'pv','energy'=>8.5,'price'=>0.10,'cost'=>0.85],['mode'=>'normal','energy'=>3.25,'price'=>0.32,'cost'=>1.04]],
    'totalEnergy'=>11.75,'totalCost'=>1.89,'pvEnergy'=>8.5,'normalEnergy'=>3.25,'unassignedEnergy'=>0.0,'warnings'=>[]];
$sequence = 0;
$r = WBM\Invoice::issue($r, $p, $sequence, 1793362200);
file_put_contents(__DIR__ . '/../docs/examples/rechnung-beispiel.pdf', WBM\BillingPdf::create($r));
echo "Beispielrechnung: docs/examples/rechnung-beispiel.pdf\n";
