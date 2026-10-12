<?php
declare(strict_types=1);
$root = dirname(__DIR__);
$doc = file_get_contents($root . '/.research/symcon-llms-full.txt');
$kernel = ['IPS_GetKernelRunlevel','IPS_SemaphoreEnter','IPS_SemaphoreLeave','IPS_VariableExists','IPS_GetVariable','IPS_GetObject',
'IPS_GetInstanceListByModuleID','IPS_GetChildrenIDs','HasAction','RequestAction','GetValue','SetValue','IPS_VariableProfileExists',
'IPS_CreateVariableProfile','IPS_SetVariableProfileDigits','IPS_SetVariableProfileText','IPS_SetVariableProfileValues','IPS_SetVariableProfileAssociation'];
$sdk = ['Create','ApplyChanges','Destroy','MessageSink','RequestAction','GetConfigurationForm','GetVisualizationTile','SetVisualizationType',
'RegisterAttributeString','ReadAttributeString','WriteAttributeString','RegisterTimer','SetTimerInterval','RegisterMessage','UnregisterMessage',
'GetMessageList','RegisterReference','UnregisterReference','GetReferenceList','SetBuffer','GetBuffer','GetValue','SetValue','SetStatus','SendDebug','UpdateVisualizationValue',
'RegisterPropertyBoolean','RegisterPropertyInteger','RegisterPropertyFloat','RegisterPropertyString','ReadPropertyBoolean','ReadPropertyInteger','ReadPropertyFloat','ReadPropertyString',
'RegisterVariableBoolean','RegisterVariableInteger','RegisterVariableFloat','RegisterVariableString','EnableAction'];
$t = chr(96);
$out = "# API-Nachweise und Mindestversion\n\nPrüfgrundlage: offizielle llms-full.txt, heruntergeladen am 12.10.2026. Index: https://www.symcon.de/de/llms.txt.\n";
$out .= "API-Signaturen und Versionsangaben wurden aus den relevanten Dokumentationsabschnitten geprüft. Keine höhere als Symcon 7.1 wird verwendet.\n";
$out .= "Array-Variablendarstellungen (8.0), IPSModuleStrict (8.1), openObject (8.2) und Vollbildtyp (9.1) werden nicht benötigt. Legacy-Profile und IPSModule erhalten die 7.1-Kompatibilität.\n\n";
foreach (['Kernfunktionen' => $kernel, 'PHP-Modul-SDK' => $sdk, 'HTML-SDK' => ['handleMessage','requestAction']] as $caption => $names) {
    $out .= "## $caption\n\n| API | Geprüfte Signatur | Dokumentierte Mindestversion | Quelle |\n|---|---|---|---|\n";
    foreach ($names as $name) {
        preg_match_all('/^## ' . preg_quote($name, '/') . '\r?\n(.*?)(?=^## |\z)/ms', $doc, $matches);
        $section = null;
        foreach ($matches[1] as $candidate) {
            if ($caption === 'PHP-Modul-SDK' && !str_contains($candidate, '/sdk-php/module/')) continue;
            if ($caption === 'Kernfunktionen' && !str_contains($candidate, '/befehlsreferenz/')) continue;
            if ($caption === 'HTML-SDK' && !str_contains($candidate, '/html-sdk/')) continue;
            $section = $candidate; break;
        }
        if ($section === null) throw new RuntimeException('API nicht dokumentiert: ' . $name);
        preg_match('/Quelle: (\S+)/', $section, $url);
        $parts = explode($t, $section);
        preg_match('/Benötigt Symcon >= ([\d.]+)/', $section, $version);
        $min = $version[1] ?? 'keine höhere Version angegeben';
        if (isset($version[1]) && version_compare($version[1], '7.1', '>')) throw new RuntimeException('Mindestversion zu hoch: ' . $name);
        $sig = $parts[1] ?? $name;
        $out .= "| $t$name$t | $t$sig$t | $min | [Dokumentation]({$url[1]}) |\n";
    }
}
$out .= "\n## Auslegungsdetails\n\n";
$out .= "- RegisterVariable nutzt bei IPSModule Profilnamen als String; Array-Darstellungen setzen 8.0 voraus.\n";
$out .= "- MessageSink-Zeitstempel ist ein Nachrichten-Zähler. VariableUpdated ist der Unix-Messzeitpunkt; Sekundenauflösung begrenzt die Integrationspräzision.\n";
$out .= "- HasAction prüft das Vorhandensein. RequestAction wird auf false, Warnungen und Exceptions geprüft; fehlende Aktion nutzt nur im ausdrücklichen Simulationsmodus SetValue.\n";
$out .= "- HTML-Nachrichten sind frei kodierbar. Das Modul sendet Datenobjekte; die Kachel akzeptiert zusätzlich JSON-Strings. Initialdaten sind gegen HTML-/Script-Einschleusung JSON-kodiert.\n";
$out .= "- WBM_ControlTargets ist eine eigene öffentliche Modulfunktion mit automatisch durch Symcon erzeugtem Präfixwrapper. Sie ist keine behauptete Kern-API.\n";
$out .= "- Hysterese, Mindestlaufzeit und Nachlauf sind mit der [Energie-Manager-Dokumentation](https://www.symcon.de/de/llms/modules/energy-manager.md) abgeglichen. Budgetkonflikte und Sperren werden gemäß Auftrag explizit strenger aufgelöst.\n";
file_put_contents($root . '/docs/API.md', $out);
echo (count($kernel) + count($sdk) + 2) . " API-Einträge anhand der offiziellen Dokumentation geprüft\n";