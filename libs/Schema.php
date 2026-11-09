<?php
declare(strict_types=1);
namespace WBM;

/** Single source for properties, forms, validation and documentation. */
final class Schema
{
    public static function global(): array
    {
        return [
            'Enabled' => [false, 'Manager bei Erstkonfiguration aktivieren', 'Allgemein'],
            'WallboxCount' => [4, 'Anzahl Wallboxen', 'Allgemein', [1 => '1 Wallbox', 2 => '2 Wallboxen', 3 => '3 Wallboxen', 4 => '4 Wallboxen']],
            'BudgetVar' => [0, 'Absolutes Grundbudget ohne Speicher', 'Allgemein', 'read', [1, 2], true],
            'BudgetUnit' => ['W', 'Einheit Grundbudget', 'Allgemein', ['W', 'kW']],
            'PVModeVar' => [0, 'PV-Überschuss aktiv (true = PV-Budget; optional)', 'PV-Überschuss', 'read', [0], false],
            'PVBudgetVar' => [0, 'Verfügbares PV-Leistungsbudget', 'PV-Überschuss', 'read', [1, 2], false],
            'PVBudgetUnit' => ['W', 'Einheit PV-Leistungsbudget', 'PV-Überschuss', ['W', 'kW']],
            'PVBudgetMaxAge' => [0, 'PV-Budgetüberwachung (s; 0 = aus)', 'PV-Überschuss'],
            'Interval' => [5, 'Regelintervall (s)', 'Allgemein'],
            'Hysteresis' => [200.0, 'Globale Hysterese', 'Allgemein'],
            'HysteresisUnit' => ['W', 'Einheit Hysterese', 'Allgemein', ['W', 'kW']],
            'HardLimitW' => [0.0, 'Harter Gesamtgrenzwert (W; 0 = kein zusätzlicher)', 'Allgemein'],
            'BudgetMaxAge' => [0, 'Budgetüberwachung (s; 0 = aus)', 'Allgemein'],
            'Mode' => [0, 'Anfängliche Verteilung (0 = gleichmäßig, 1 = Priorität)', 'Verteilungsmodus', [0 => 'Gleichmäßig', 1 => 'Priorität']],
            'Rotation' => [900, 'Rotation bei knappem Budget (s; 0 = aus)', 'Verteilungsmodus'],
            'StrictBudget' => [true, 'Budget strikt einhalten', 'Verteilungsmodus'],
            'OverrunW' => [0.0, 'Maximal erlaubte zeitweilige Überschreitung (W)', 'Verteilungsmodus'],
            'OverrunSeconds' => [120, 'Maximale Dauer einer Überschreitung (s)', 'Verteilungsmodus'],
            'Simulation' => [false, 'Simulation: SetValue bei Zielen ohne Aktion erlauben', 'Diagnose'],
            'Watchdog' => [0, 'Sollwert-Wiederholung (s; 0 = aus)', 'Diagnose'],
            'FeedbackTimeout' => [30, 'Timeout Lastabbau / Reduzierungsrückmeldung (s)', 'Diagnose'],
            'FeedbackToleranceW' => [100.0, 'Toleranz Lastabbau (W)', 'Diagnose'],
            'EnergyMaxGap' => [120, 'Maximale Integrationslücke (s)', 'Energiezählung'],
            'MeterJumpFactor' => [2.0, 'Plausibilitätsfaktor für Zählersprünge', 'Energiezählung'],
            'MeterJumpSlack' => [0.02, 'Zählersprung-Toleranz (kWh)', 'Energiezählung'],
            'BillingTimezone' => ['Europe/Berlin', 'Zeitzone der Ladeabrechnungen', 'Abrechnung']
        ] + self::invoice() + self::shared();
    }

    /** New common property => former per-wallbox property (kept for migration). */
    public static function sharedMap(): array
    {
        return [
            'WallboxStartDelay' => 'StartDelay', 'WallboxMinRun' => 'MinRun', 'WallboxStopDelay' => 'StopDelay',
            'WallboxMinPause' => 'MinPause', 'WallboxHysteresisW' => 'HysteresisW', 'WallboxCommandGap' => 'CommandGap',
            'BillingEnabled' => 'BillingEnabled', 'BillingPVVar' => 'BillingPVVar', 'BillingPVPrice' => 'BillingPVPrice',
            'BillingNormalPrice' => 'BillingNormalPrice', 'BillingIdleSeconds' => 'BillingIdleSeconds', 'BillingMinPowerW' => 'BillingMinPowerW'
        ] + array_combine(array_keys(self::invoiceRecipient()), array_keys(self::invoiceRecipient()));
    }

    public static function shared(): array
    {
        $definitions = self::wallbox(); $out = [];
        foreach (self::sharedMap() as $name => $former) {
            $out[$name] = $definitions[$former];
            if (str_starts_with($name, 'Invoice')) $out[$name][2] = 'Rechnung: Empfänger';
        }
        $out['BillingEnabled'][1] = 'Ladeabrechnung für alle Wallboxen aktivieren';
        $out['WallboxHysteresisW'][1] = 'Gemeinsame Wallbox-Hysterese (W; -1 = Budget-Hysterese)';
        return $out;
    }

    public static function visibleWallbox(): array
    {
        return array_diff_key(self::wallbox(), array_flip(array_values(self::sharedMap())));
    }

    public static function isLegacy(string $name): bool
    {
        return $name === 'BillingIdleSeconds' || preg_match('/^W[1-4](.+)$/', $name, $match) && in_array($match[1], self::sharedMap(), true);
    }

    public static function invoice(): array
    {
        return [
            'InvoiceSenderName' => ['', 'Name / Firma', 'Rechnung: Rechnungssteller'],
            'InvoiceSenderStreet' => ['', 'Straße / Hausnummer', 'Rechnung: Rechnungssteller'],
            'InvoiceSenderZip' => ['', 'Postleitzahl', 'Rechnung: Rechnungssteller'],
            'InvoiceSenderCity' => ['', 'Ort', 'Rechnung: Rechnungssteller'],
            'InvoiceSenderCountry' => ['', 'Land', 'Rechnung: Rechnungssteller'],
            'InvoiceLogoText' => ['', 'Logo-Kürzel (optional)', 'Rechnung: Rechnungssteller'],
            'InvoicePhone' => ['', 'Telefon', 'Rechnung: Kontakt'],
            'InvoiceEmail' => ['', 'E-Mail', 'Rechnung: Kontakt'],
            'InvoiceWebsite' => ['', 'Webseite', 'Rechnung: Kontakt'],
            'InvoiceBankName' => ['', 'Name der Bank', 'Rechnung: Zahlung'],
            'InvoiceAccountHolder' => ['', 'Kontoinhaber', 'Rechnung: Zahlung'],
            'InvoiceIBAN' => ['', 'IBAN', 'Rechnung: Zahlung'],
            'InvoiceBIC' => ['', 'BIC', 'Rechnung: Zahlung'],
            'InvoicePaymentMethod' => ['Überweisung', 'Zahlungsmethode', 'Rechnung: Zahlung'],
            'InvoicePaymentDays' => [14, 'Zahlungsfrist (Tage; 0 = sofort)', 'Rechnung: Zahlung'],
            'InvoicePaymentText' => ['', 'Zahlungshinweis (leer = automatisch)', 'Rechnung: Zahlung'],
            'InvoiceFooterText' => ['', 'Zusätzlicher Fußtext (optional)', 'Rechnung: Kontakt'],
            'InvoicePrefix' => ['RE', 'Rechnungsnummer-Präfix', 'Rechnung: Nummer und Steuer'],
            'InvoiceStartNumber' => [1, 'Startnummer (bereits vergebene Nummern bleiben reserviert)', 'Rechnung: Nummer und Steuer'],
            'InvoiceTaxMode' => ['none', 'Umsatzsteuerdarstellung', 'Rechnung: Nummer und Steuer', ['none' => 'Umsatzsteuer ignorieren (kein gesonderter Ausweis)', 'included' => 'Umsatzsteuer einbeziehen (im Endpreis enthalten)', 'small' => 'Kleinunternehmerregelung (§ 19 UStG)']],
            'InvoiceTaxRate' => [19.0, 'Umsatzsteuersatz (%)', 'Rechnung: Nummer und Steuer'],
            'InvoiceTaxNumber' => ['', 'Steuernummer (optional)', 'Rechnung: Nummer und Steuer'],
            'InvoiceVATID' => ['', 'USt-IdNr. (optional)', 'Rechnung: Nummer und Steuer']
        ];
    }

    public static function invoiceRecipient(): array
    {
        return [
            'InvoiceRecipientName' => ['', 'Rechnungsempfänger: Name / Firma', 'Rechnung'],
            'InvoiceRecipientStreet' => ['', 'Straße / Hausnummer', 'Rechnung'],
            'InvoiceRecipientZip' => ['', 'Postleitzahl', 'Rechnung'],
            'InvoiceRecipientCity' => ['', 'Ort', 'Rechnung'],
            'InvoiceRecipientCountry' => ['', 'Land', 'Rechnung'],
            'InvoiceCustomerNumber' => ['', 'Kundennummer (optional)', 'Rechnung'],
            'InvoiceReference' => ['', 'Referenz / Kennzeichen (optional)', 'Rechnung']
        ];
    }

    public static function wallbox(): array
    {
        return [
            'Enabled' => [false, 'Platz aktiviert', 'Allgemein'],
            'Name' => ['', 'Name', 'Allgemein'],
            'DeviceKey' => ['', 'Gerätekennung (bei Gerätetausch ändern)', 'Allgemein'],
            'Priority' => [1, 'Anfängliche Priorität (1 = höchste)', 'Allgemein'],
            'ExternalVar' => [0, 'Externe Freigabebedingung (optional)', 'Allgemein', 'read', [0], false],
            'DemandVar' => [0, 'Fahrzeug verbunden / Ladebedarf (optional)', 'Allgemein', 'read', [0], false],
            'ErrorVar' => [0, 'Gerätefehler (true = Fehler; optional)', 'Allgemein', 'read', [0], false],
            'SetpointVar' => [0, 'Gerätesollwert', 'Sollwertausgabe', 'write', [1, 2], true],
            'SetpointUnit' => ['A', 'Sollwerteinheit', 'Sollwertausgabe', ['A', 'W', 'kW']],
            'MinA' => [6.0, 'Minimaler Strom je Phase (A)', 'Sollwertausgabe'],
            'MaxA' => [16.0, 'Maximaler Strom je Phase (A)', 'Sollwertausgabe'],
            'MinKW' => [1.38, 'Mindestladeleistung (kW)', 'Sollwertausgabe'],
            'MaxKW' => [11.04, 'Maximale Ladeleistung (kW)', 'Sollwertausgabe'],
            'Step' => [1.0, 'Sollwertraster in Sollwerteinheit (ab 0)', 'Sollwertausgabe'],
            'Stop' => [0.0, 'Individueller Ausschaltwert in Sollwerteinheit', 'Sollwertausgabe'],
            'DeviceEnableVar' => [0, 'Separate Geräte-Ladefreigabe (optional)', 'Sollwertausgabe', 'write', [0], false],
            'EnablePolarity' => [true, 'Gerätefreigabe: true erlaubt Laden', 'Sollwertausgabe'],
            'PowerVar' => [0, 'Tatsächliche Ladeleistung', 'Messwerte', 'read', [1, 2], true],
            'PowerUnit' => ['W', 'Einheit Ladeleistungsmessung', 'Messwerte', ['W', 'kW']],
            'EnergyVar' => [0, 'Kumulativer Geräte-Energiezähler (optional)', 'Messwerte', 'read', [1, 2], false],
            'EnergyUnit' => ['kWh', 'Einheit Geräte-Energiezähler', 'Messwerte', ['Wh', 'kWh']],
            'BillingEnabled' => [false, 'Abrechnung für diese Wallbox aktivieren', 'Abrechnung'],
            'BillingPVVar' => [0, 'PV-Überschussladen (true = PV, false = Normal; optional)', 'Abrechnung', 'read', [0], false],
            'BillingPVPrice' => [0.0, 'PV-Preis (EUR/kWh)', 'Abrechnung'],
            'BillingNormalPrice' => [0.0, 'Normalpreis (EUR/kWh)', 'Abrechnung'],
            'BillingIdleSeconds' => [600, 'Ladepause bis zum Abschluss (s)', 'Abrechnung'],
            'BillingMinPowerW' => [50.0, 'Leistungsschwelle für die Abrechnungsenergie (W)', 'Abrechnung'],
            'MaxAge' => [0, 'Messwertalter / Heartbeat-Timeout (s; 0 = aus)', 'Messwerte'],
            'HeartbeatVar' => [0, 'Geräte-Heartbeat (optional; Aktualisierung zählt)', 'Messwerte', 'read', [0, 1, 2, 3], false],
            'Voltage' => [230.0, 'Spannung Außenleiter–Neutralleiter (V)', 'Phasen'],
            'PhaseMode' => ['3', 'Phasenbetrieb', 'Phasen', ['1' => 'Fest einphasig', '3' => 'Fest dreiphasig', 'dynamic' => 'Dynamisch (nur geeignete Geräte)']],
            'PhaseCommandVar' => [0, 'Phasenwechsel: Befehlsvariable', 'Dynamische Phasenumschaltung', 'write', [1, 2], true],
            'PhaseFeedbackVar' => [0, 'Phasenwechsel: tatsächliche Rückmeldung', 'Dynamische Phasenumschaltung', 'read', [1, 2], true],
            'Phase1Command' => [1.0, 'Befehlswert eine Phase', 'Dynamische Phasenumschaltung'],
            'Phase3Command' => [3.0, 'Befehlswert drei Phasen', 'Dynamische Phasenumschaltung'],
            'Phase1Feedback' => [1.0, 'Rückmeldewert eine Phase', 'Dynamische Phasenumschaltung'],
            'Phase3Feedback' => [3.0, 'Rückmeldewert drei Phasen', 'Dynamische Phasenumschaltung'],
            'PhaseUpW' => [6000.0, 'Schwelle auf drei Phasen (W)', 'Dynamische Phasenumschaltung'],
            'PhaseDownW' => [4000.0, 'Schwelle auf eine Phase (W)', 'Dynamische Phasenumschaltung'],
            'PhaseHysteresisW' => [200.0, 'Phasenschwellen-Hysterese (W)', 'Dynamische Phasenumschaltung'],
            'PhaseDelay' => [30, 'Schwelle dauerhaft erfüllt (s)', 'Dynamische Phasenumschaltung'],
            'PhaseDwell' => [300, 'Mindestverweildauer (s)', 'Dynamische Phasenumschaltung'],
            'PhaseRestW' => [50.0, 'Zulässiger Restverbrauch (W)', 'Dynamische Phasenumschaltung'],
            'PhaseZeroSeconds' => [3, 'Restverbrauch dauerhaft unterschritten (s)', 'Dynamische Phasenumschaltung'],
            'PhaseBefore' => [5, 'Wartezeit vor Phasenbefehl (s)', 'Dynamische Phasenumschaltung'],
            'PhaseAfter' => [5, 'Wartezeit nach bestätigtem Wechsel (s)', 'Dynamische Phasenumschaltung'],
            'PhaseTimeout' => [60, 'Stillstands-/Rückmeldetimeout (s)', 'Dynamische Phasenumschaltung'],
            'StartDelay' => [10, 'Einschaltverzögerung (s)', 'Zeitbedingungen'],
            'MinRun' => [120, 'Mindestlaufzeit (s)', 'Zeitbedingungen'],
            'StopDelay' => [30, 'Nachlaufzeit bei Leistungsmangel (s)', 'Zeitbedingungen'],
            'MinPause' => [60, 'Mindestpause (s)', 'Zeitbedingungen'],
            'HysteresisW' => [-1.0, 'Eigene Hysterese (W; -1 = global)', 'Zeitbedingungen'],
            'CommandGap' => [0, 'Mindestabstand für Erhöhungsbefehle (s)', 'Zeitbedingungen']
        ] + self::invoiceRecipient();
    }

    public static function battery(): array
    {
        return [
            'Enabled' => [false, 'Speicherfunktion aktiviert', 'Batteriespeicher'],
            'CapacityKWh' => [10.0, 'Nutzbare Kapazität (kWh)', 'Batteriespeicher'],
            'MaxPower' => [4000.0, 'Maximale Entladeleistung', 'Batteriespeicher'],
            'MaxPowerUnit' => ['W', 'Einheit maximale Entladeleistung', 'Batteriespeicher', ['W', 'kW']],
            'SocVar' => [0, 'Aktueller SoC (%)', 'Batteriespeicher', 'read', [1, 2], true],
            'MinSoc' => [20.0, 'Mindest-SoC / Reserve (%)', 'Batteriespeicher'],
            'SocHysteresis' => [5.0, 'SoC-Hysterese (%)', 'Batteriespeicher'],
            'ExternalVar' => [0, 'Externe Speicherfreigabe (optional)', 'Batteriespeicher', 'read', [0], false],
            'SetpointVar' => [0, 'Entladeleistung: Geräte-Sollwert', 'Batteriespeicher', 'write', [1, 2], true],
            'SetpointUnit' => ['W', 'Einheit Entladesollwert', 'Batteriespeicher', ['W', 'kW']],
            'Sign' => [1, 'Vorzeichen der Entladung', 'Batteriespeicher', [1 => 'Positiv', -1 => 'Negativ']],
            'Stop' => [0.0, 'Neutral-/Stoppwert in Sollwerteinheit', 'Batteriespeicher'],
            'PowerVar' => [0, 'Tatsächliche Speicherleistung (optional)', 'Batteriespeicher', 'read', [1, 2], false],
            'PowerUnit' => ['W', 'Einheit Speichermessung', 'Batteriespeicher', ['W', 'kW']],
            'PowerSign' => [1, 'Vorzeichen der gemessenen Entladung', 'Batteriespeicher', [1 => 'Positiv', -1 => 'Negativ']],
            'LimitVar' => [0, 'Aktuelle Entladeleistungsgrenze (optional)', 'Batteriespeicher', 'read', [1, 2], false],
            'LimitUnit' => ['W', 'Einheit Entladeleistungsgrenze', 'Batteriespeicher', ['W', 'kW']],
            'MaxAge' => [120, 'SoC-/Messwertüberwachung (s; 0 = aus)', 'Batteriespeicher'],
            'StartDelay' => [10, 'Konservative Anlaufwartezeit ohne Rückmeldung (s)', 'Batteriespeicher'],
            'NoFeedbackFraction' => [0.5, 'Nutzbarer Anteil ohne Rückmeldung (0 bis 1)', 'Batteriespeicher'],
            'ResponseTimeout' => [30, 'Timeout bei unzureichender Entladung (s)', 'Batteriespeicher'],
            'ToleranceW' => [100.0, 'Speicherrückmeldungs-Toleranz (W)', 'Batteriespeicher']
        ];
    }

    public static function definitions(): array
    {
        $all = self::global();
        for ($i = 1; $i <= 4; $i++) {
            foreach (self::wallbox() as $key => $def) {
                if ($key === 'Name') $def[0] = 'Wallbox ' . $i;
                if ($key === 'Priority') $def[0] = $i;
                $all['W' . $i . $key] = $def;
            }
        }
        foreach (self::battery() as $key => $def) $all['B' . $key] = $def;
        return $all;
    }

    public static function defaults(): array
    {
        return array_map(static fn(array $def) => $def[0], self::definitions());
    }

    public static function unpack(array $flat): array
    {
        $provided = $flat;
        $flat = array_replace(self::defaults(), $flat);
        $out = ['global' => [], 'boxes' => [], 'battery' => []];
        foreach (self::global() as $key => $_) $out['global'][$key] = $flat[$key];
        for ($i = 1; $i <= 4; $i++) {
            foreach (self::wallbox() as $key => $_) $out['boxes'][$i][$key] = $flat['W' . $i . $key];
            // Old persisted snapshots retain their original tariffs at the edit boundary.
            foreach (self::sharedMap() as $name => $former) {
                if (array_key_exists($name, $provided)) $out['boxes'][$i][$former] = $flat[$name];
            }
            // Preserve stored settings; places above the selected count are inactive.
            $out['boxes'][$i]['Enabled'] = $out['boxes'][$i]['Enabled'] && $i <= $flat['WallboxCount'];
        }
        foreach (self::battery() as $key => $_) $out['battery'][$key] = $flat['B' . $key];
        return $out;
    }

    public static function links(array $flat): array
    {
        $links = [];
        foreach (self::definitions() as $key => $def) {
            if (self::isLegacy($key)) continue;
            if (!isset($def[4])) continue;
            $active = true;
            if ($key === 'PVBudgetVar') $active = ($flat['PVModeVar'] ?? 0) > 0;
            if (preg_match('/^W([1-4])(.+)$/', $key, $m)) {
                $active = (bool)$flat['W' . $m[1] . 'Enabled'] && (int)$m[1] <= ($flat['WallboxCount'] ?? 4);
                if (str_starts_with($m[2], 'Phase') && in_array($m[2], ['PhaseCommandVar', 'PhaseFeedbackVar'], true)) {
                    $active = $active && $flat['W' . $m[1] . 'PhaseMode'] === 'dynamic';
                }
            }
            if (str_starts_with($key, 'B') && isset(self::battery()[substr($key, 1)])) $active = (bool)$flat['BEnabled'];
            if ($key === 'BillingPVVar') {
                $boxes = self::unpack($flat)['boxes'];
                $active = ($flat['BillingEnabled'] ?? false) && count(array_filter($boxes, static fn($box) => $box['Enabled'])) > 0;
            }
            if ($active) $links[$key] = ['id' => (int)$flat[$key], 'write' => $def[3] === 'write', 'types' => $def[4], 'required' => $def[5], 'label' => $def[1]];
        }
        return $links;
    }
}
