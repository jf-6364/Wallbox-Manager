# API-Nachweise und Mindestversion

Prüfgrundlage: offizielle llms-full.txt, heruntergeladen am 12.10.2026. Index: https://www.symcon.de/de/llms.txt.
API-Signaturen und Versionsangaben wurden aus den relevanten Dokumentationsabschnitten geprüft. Keine höhere als Symcon 7.1 wird verwendet.
Array-Variablendarstellungen (8.0), IPSModuleStrict (8.1), openObject (8.2) und Vollbildtyp (9.1) werden nicht benötigt. Legacy-Profile und IPSModule erhalten die 7.1-Kompatibilität.

## Kernfunktionen

| API | Geprüfte Signatur | Dokumentierte Mindestversion | Quelle |
|---|---|---|---|
| `IPS_GetKernelRunlevel` | `int IPS_GetKernelRunlevel()` | 4.0 | [Dokumentation](https://www.symcon.de/de/service/dokumentation/befehlsreferenz/programminformationen/ips-getkernelrunlevel/) |
| `IPS_SemaphoreEnter` | `bool IPS_SemaphoreEnter(string $Name, int $Wartezeit)` | keine höhere Version angegeben | [Dokumentation](https://www.symcon.de/de/service/dokumentation/befehlsreferenz/ablaufsteuerung/ips-semaphoreenter/) |
| `IPS_SemaphoreLeave` | `bool IPS_SemaphoreLeave(string $Name)` | keine höhere Version angegeben | [Dokumentation](https://www.symcon.de/de/service/dokumentation/befehlsreferenz/ablaufsteuerung/ips-semaphoreleave/) |
| `IPS_VariableExists` | `bool IPS_VariableExists(int $VariablenID)` | keine höhere Version angegeben | [Dokumentation](https://www.symcon.de/de/service/dokumentation/befehlsreferenz/variablenverwaltung/ips-variableexists/) |
| `IPS_GetVariable` | `array IPS_GetVariable(int $VariablenID)` | keine höhere Version angegeben | [Dokumentation](https://www.symcon.de/de/service/dokumentation/befehlsreferenz/variablenverwaltung/ips-getvariable/) |
| `IPS_GetObject` | `array IPS_GetObject(int $ObjektID)` | keine höhere Version angegeben | [Dokumentation](https://www.symcon.de/de/service/dokumentation/befehlsreferenz/objektverwaltung/ips-getobject/) |
| `IPS_GetInstanceListByModuleID` | `array IPS_GetInstanceListByModuleID(string $ModulID)` | keine höhere Version angegeben | [Dokumentation](https://www.symcon.de/de/service/dokumentation/befehlsreferenz/instanzenverwaltung/ips-getinstancelistbymoduleid/) |
| `IPS_GetChildrenIDs` | `array IPS_GetChildrenIDs(int $ObjektID)` | keine höhere Version angegeben | [Dokumentation](https://www.symcon.de/de/service/dokumentation/befehlsreferenz/objektverwaltung/ips-getchildrenids/) |
| `HasAction` | `bool HasAction(int $VariablenID)` | 5.3 | [Dokumentation](https://www.symcon.de/de/service/dokumentation/befehlsreferenz/variablenzugriff/hasaction/) |
| `RequestAction` | `bool RequestAction(int $VariablenID, mixed $Wert)` | 5.0 | [Dokumentation](https://www.symcon.de/de/service/dokumentation/befehlsreferenz/variablenzugriff/requestaction/) |
| `GetValue` | `mixed GetValue(int $VariablenID)` | keine höhere Version angegeben | [Dokumentation](https://www.symcon.de/de/service/dokumentation/befehlsreferenz/variablenzugriff/getvalue/) |
| `SetValue` | `bool SetValue(int $VariablenID, mixed $Wert)` | keine höhere Version angegeben | [Dokumentation](https://www.symcon.de/de/service/dokumentation/befehlsreferenz/variablenzugriff/setvalue/) |
| `IPS_VariableProfileExists` | `bool IPS_VariableProfileExists(string $ProfilName)` | keine höhere Version angegeben | [Dokumentation](https://www.symcon.de/de/service/dokumentation/befehlsreferenz/variablenverwaltung/variablenprofile/ips-variableprofileexists/) |
| `IPS_CreateVariableProfile` | `bool IPS_CreateVariableProfile(string $ProfilName, int $VariablenTyp)` | keine höhere Version angegeben | [Dokumentation](https://www.symcon.de/de/service/dokumentation/befehlsreferenz/variablenverwaltung/variablenprofile/ips-createvariableprofile/) |
| `IPS_SetVariableProfileDigits` | `bool IPS_SetVariableProfileDigits(string $ProfilName, int $Nachkommastellen)` | keine höhere Version angegeben | [Dokumentation](https://www.symcon.de/de/service/dokumentation/befehlsreferenz/variablenverwaltung/variablenprofile/ips-setvariableprofiledigits/) |
| `IPS_SetVariableProfileText` | `bool IPS_SetVariableProfileText(string $ProfilName, string $Präfix, string $Suffix)` | keine höhere Version angegeben | [Dokumentation](https://www.symcon.de/de/service/dokumentation/befehlsreferenz/variablenverwaltung/variablenprofile/ips-setvariableprofiletext/) |
| `IPS_SetVariableProfileValues` | `bool IPS_SetVariableProfileValues(string $ProfilName, float $Minimalwert, float $Maximalwert, float $Schrittweite)` | keine höhere Version angegeben | [Dokumentation](https://www.symcon.de/de/service/dokumentation/befehlsreferenz/variablenverwaltung/variablenprofile/ips-setvariableprofilevalues/) |
| `IPS_SetVariableProfileAssociation` | `bool IPS_SetVariableProfileAssociation(string $ProfilName, mixed $Wert, string $Name, string $Icon, int $Farbe)` | keine höhere Version angegeben | [Dokumentation](https://www.symcon.de/de/service/dokumentation/befehlsreferenz/variablenverwaltung/variablenprofile/ips-setvariableprofileassociation/) |
## PHP-Modul-SDK

| API | Geprüfte Signatur | Dokumentierte Mindestversion | Quelle |
|---|---|---|---|
| `Create` | `void Create()` | 4.0 | [Dokumentation](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/module/create/) |
| `ApplyChanges` | `void ApplyChanges()` | 4.0 | [Dokumentation](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/module/applychanges/) |
| `Destroy` | `void Destroy()` | 4.1 | [Dokumentation](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/module/destroy/) |
| `MessageSink` | `void MessageSink(int $Zeitstempel, int $SenderID, int $NachrichtID, array $Daten)` | 4.1 | [Dokumentation](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/module/messagesink/) |
| `RequestAction` | `void RequestAction(string $Ident, mixed $Wert)` | keine höhere Version angegeben | [Dokumentation](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/module/requestaction/) |
| `GetConfigurationForm` | `string GetConfigurationForm()` | 4.1 | [Dokumentation](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/module/getconfigurationform/) |
| `GetVisualizationTile` | `string GetVisualizationTile()` | 7.1 | [Dokumentation](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/module/getvisualizationtile/) |
| `SetVisualizationType` | `SetVisualizationType(int $VisualisierungsTyp)` | 7.1 | [Dokumentation](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/module/setvisualizationtype/) |
| `RegisterAttributeString` | `bool RegisterAttributeString(string $Name, string $Standardwert)` | 5.1 | [Dokumentation](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/module/registerattributestring/) |
| `ReadAttributeString` | `string ReadAttributeString(string $Name)` | 5.1 | [Dokumentation](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/module/readattributestring/) |
| `WriteAttributeString` | `bool WriteAttributeString(string $Name, string $Wert)` | 5.1 | [Dokumentation](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/module/writeattributestring/) |
| `RegisterTimer` | `bool RegisterTimer(string $Name, int $Intervall, string $Skriptinhalt)` | 4.0 | [Dokumentation](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/module/registertimer/) |
| `SetTimerInterval` | `bool SetTimerInterval(string $Name, int $Intervall)` | 4.0 | [Dokumentation](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/module/settimerinterval/) |
| `RegisterMessage` | `bool RegisterMessage(int $SenderID, int $NachrichtID)` | 4.1 | [Dokumentation](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/module/registermessage/) |
| `UnregisterMessage` | `bool UnregisterMessage(int $SenderID, int $NachrichtID)` | 4.1 | [Dokumentation](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/module/unregistermessage/) |
| `GetMessageList` | `array GetMessageList()` | 5.0 | [Dokumentation](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/module/getmessagelist/) |
| `RegisterReference` | `bool RegisterReference(int $ID)` | 5.1 | [Dokumentation](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/module/registerreference/) |
| `UnregisterReference` | `bool UnregisterReference(int $ID)` | 5.1 | [Dokumentation](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/module/unregisterreference/) |
| `GetReferenceList` | `array GetReferenceList()` | 5.1 | [Dokumentation](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/module/getreferencelist/) |
| `SetBuffer` | `bool SetBuffer(string $Name, string $Daten)` | 4.1 | [Dokumentation](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/module/setbuffer/) |
| `GetBuffer` | `string GetBuffer(string $Name)` | 4.1 | [Dokumentation](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/module/getbuffer/) |
| `GetValue` | `mixed GetValue(string $Ident)` | 5.0 | [Dokumentation](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/module/getvalue/) |
| `SetValue` | `bool SetValue(string $Ident, mixed $Wert)` | 5.0 | [Dokumentation](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/module/setvalue/) |
| `SetStatus` | `bool SetStatus(int $Statuswert)` | 4.0 | [Dokumentation](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/module/setstatus/) |
| `SendDebug` | `bool SendDebug(string $Meldungsname, string $Daten, int $Format)` | 4.0 | [Dokumentation](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/module/senddebug/) |
| `UpdateVisualizationValue` | `bool UpdateVisualizationValue(mixed $Daten)` | 7.1 | [Dokumentation](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/module/updatevisualizationvalue/) |
| `RegisterPropertyBoolean` | `bool RegisterPropertyBoolean(string $Name, bool $Standardwert)` | 4.0 | [Dokumentation](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/module/registerpropertyboolean/) |
| `RegisterPropertyInteger` | `bool RegisterPropertyInteger(string $Name, int $Standardwert)` | 4.0 | [Dokumentation](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/module/registerpropertyinteger/) |
| `RegisterPropertyFloat` | `bool RegisterPropertyFloat(string $Name, float $Standardwert)` | 4.0 | [Dokumentation](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/module/registerpropertyfloat/) |
| `RegisterPropertyString` | `bool RegisterPropertyString(string $Name, string $Standardwert)` | 4.0 | [Dokumentation](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/module/registerpropertystring/) |
| `ReadPropertyBoolean` | `bool ReadPropertyBoolean(string $Name)` | 4.0 | [Dokumentation](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/module/readpropertyboolean/) |
| `ReadPropertyInteger` | `int ReadPropertyInteger(string $Name)` | 4.0 | [Dokumentation](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/module/readpropertyinteger/) |
| `ReadPropertyFloat` | `float ReadPropertyFloat(string $Name)` | 4.0 | [Dokumentation](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/module/readpropertyfloat/) |
| `ReadPropertyString` | `string ReadPropertyString(string $Name)` | 4.0 | [Dokumentation](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/module/readpropertystring/) |
| `RegisterVariableBoolean` | `mixed RegisterVariableBoolean(string $Ident, string $Name, array $Darstellung, int $Position)` | 4.0 | [Dokumentation](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/module/registervariableboolean/) |
| `RegisterVariableInteger` | `mixed RegisterVariableInteger(string $Ident, string $Name, array $Darstellung, int $Position)` | 4.0 | [Dokumentation](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/module/registervariableinteger/) |
| `RegisterVariableFloat` | `mixed RegisterVariableFloat(string $Ident, string $Name, array $Darstellung, int $Position)` | 4.0 | [Dokumentation](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/module/registervariablefloat/) |
| `RegisterVariableString` | `mixed RegisterVariableString(string $Ident, string $Name, array $Darstellung, int $Position)` | 4.0 | [Dokumentation](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/module/registervariablestring/) |
| `EnableAction` | `bool EnableAction(string $Ident)` | 4.0 | [Dokumentation](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/module/enableaction/) |
## HTML-SDK

| API | Geprüfte Signatur | Dokumentierte Mindestversion | Quelle |
|---|---|---|---|
| `handleMessage` | `void handleMessage(mixed Daten)` | 7.1 | [Dokumentation](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/html-sdk/handlemessage/) |
| `requestAction` | `void requestAction(string Ident, mixed Wert)` | 7.1 | [Dokumentation](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/html-sdk/requestaction/) |

## Auslegungsdetails

- RegisterVariable nutzt bei IPSModule Profilnamen als String; Array-Darstellungen setzen 8.0 voraus.
- MessageSink-Zeitstempel ist ein Nachrichten-Zähler. VariableUpdated ist der Unix-Messzeitpunkt; Sekundenauflösung begrenzt die Integrationspräzision.
- HasAction prüft das Vorhandensein. RequestAction wird auf false, Warnungen und Exceptions geprüft; fehlende Aktion nutzt nur im ausdrücklichen Simulationsmodus SetValue.
- HTML-Nachrichten sind frei kodierbar. Das Modul sendet Datenobjekte; die Kachel akzeptiert zusätzlich JSON-Strings. Initialdaten sind gegen HTML-/Script-Einschleusung JSON-kodiert.
- WBM_ControlTargets ist eine eigene öffentliche Modulfunktion mit automatisch durch Symcon erzeugtem Präfixwrapper. Sie ist keine behauptete Kern-API.
- Hysterese, Mindestlaufzeit und Nachlauf sind mit der [Energie-Manager-Dokumentation](https://www.symcon.de/de/llms/modules/energy-manager.md) abgeglichen. Budgetkonflikte und Sperren werden gemäß Auftrag explizit strenger aufgelöst.
