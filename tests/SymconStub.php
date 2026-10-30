<?php
declare(strict_types=1);
/** Minimal kernel testdouble. It is never included by the installed module. */
const KR_READY = 10103;
const IPS_KERNELMESSAGE = 10100;
const VM_UPDATE = 10603;
const VM_DELETE = 10602;

final class Kernel
{
    public static array $variables = [];
    public static array $objects = [];
    public static array $instances = [];
    public static array $profiles = [];
    public static array $calls = [];
    public static array $failures = [];
    public static array $locks = [];
    public static array $media = [];
    public static int $next = 1000;
    public static function reset(): void
    {
        self::$variables = self::$objects = self::$instances = self::$profiles = self::$calls = self::$failures = self::$locks = self::$media = [];
        self::$next = 1000;
    }
    public static function variable($value, bool $action = false, int $parent = 0): int
    {
        $id = self::$next++;
        $type = is_bool($value) ? 0 : (is_int($value) ? 1 : (is_float($value) ? 2 : 3));
        self::$variables[$id] = ['VariableType' => $type, 'VariableUpdated' => time(), 'VariableAction' => $action ? 900 : 0, 'VariableCustomAction' => 0, 'VariableIsLocked' => false, 'value' => $value];
        self::$objects[$id] = ['ParentID' => $parent, 'ObjectType' => 2, 'ObjectIdent' => '', 'ObjectIsReadOnly' => false];
        return $id;
    }
    public static function update(int $id, $value, ?float $time = null): void
    {
        self::$variables[$id]['value'] = $value;
        self::$variables[$id]['VariableUpdated'] = $time ?? time();
    }
}

class IPSModule
{
    public int $InstanceID;
    public array $properties = [];
    public array $attributes = [];
    public array $buffers = [];
    public array $references = [];
    public array $messages = [];
    public array $timers = [];
    public array $idents = [];
    public array $debug = [];
    public array $visualUpdates = [];
    public array $formUpdates = [];
    public int $status = 0;
    public int $visualType = 0;
    public function __construct(int $id = 50)
    {
        $this->InstanceID = $id;
        Kernel::$instances[$id] = $this;
        Kernel::$objects[$id] = ['ParentID' => 0, 'ObjectType' => 1, 'ObjectIdent' => '', 'ObjectIsReadOnly' => false];
    }
    public function Create() {}
    public function ApplyChanges() {}
    public function Destroy() {}
    public function __call(string $name, array $args)
    {
        if (str_starts_with($name, 'RegisterProperty')) { $this->properties[$args[0]] ??= $args[1]; return true; }
        if (str_starts_with($name, 'ReadProperty')) return $this->properties[$args[0]];
        if (str_starts_with($name, 'RegisterVariable')) {
            if (!isset($this->idents[$args[0]])) {
                $kind = substr($name, strlen('RegisterVariable'));
                $value = match ($kind) { 'Boolean' => false, 'Integer' => 0, 'Float' => 0.0, default => '' };
                $id = Kernel::variable($value, false, $this->InstanceID);
                $this->idents[$args[0]] = $id;
                Kernel::$objects[$id]['ObjectIdent'] = $args[0];
            }
            return $this->idents[$args[0]];
        }
        throw new RuntimeException('Unknown SDK method: ' . $name);
    }
    public function RegisterAttributeString($name, $default) { $this->attributes[$name] ??= $default; }
    public function ReadAttributeString($name) { return $this->attributes[$name]; }
    public function WriteAttributeString($name, $value) { $this->attributes[$name] = $value; }
    public function RegisterTimer($name, $interval, $code) { $this->timers[$name] = ['interval' => $interval, 'code' => $code]; }
    public function SetTimerInterval($name, $interval) { $this->timers[$name]['interval'] = $interval; }
    public function SetBuffer($name, $value) { $this->buffers[$name] = $value; }
    public function GetBuffer($name) { return $this->buffers[$name] ?? ''; }
    public function SetValue($ident, $value) { return SetValue($this->idents[$ident], $value); }
    public function GetValue($ident) { return GetValue($this->idents[$ident]); }
    public function GetIDForIdent($ident) { return $this->idents[$ident]; }
    public function EnableAction($ident) { Kernel::$variables[$this->idents[$ident]]['VariableAction'] = $this->InstanceID; }
    public function RegisterReference($id) { $this->references[$id] = $id; }
    public function UnregisterReference($id) { unset($this->references[$id]); }
    public function GetReferenceList() { return array_values($this->references); }
    public function RegisterMessage($id, $message) { $this->messages[$id][$message] = $message; }
    public function UnregisterMessage($id, $message) { unset($this->messages[$id][$message]); }
    public function GetMessageList() { return array_map('array_values', $this->messages); }
    public function SetStatus($status) { $this->status = $status; }
    public function SendDebug($name, $data, $format) { $this->debug[] = [$name, $data]; }
    public function SetVisualizationType($type) { $this->visualType = $type; }
    public function UpdateFormField($field, $parameter, $value) { $this->formUpdates[] = ['field' => $field, 'parameter' => $parameter, 'value' => $value]; return true; }
    public function UpdateVisualizationValue($value)
    {
        if (!is_scalar($value)) throw new TypeError('Cannot auto-convert value for parameter Value (Type does not match)');
        $this->visualUpdates[] = $value;
        return true;
    }
}
function IPS_GetKernelRunlevel() { return KR_READY; }
function IPS_VariableExists($id) { return isset(Kernel::$variables[$id]); }
function IPS_GetVariable($id) { return Kernel::$variables[$id]; }
function IPS_GetObject($id) { return Kernel::$objects[$id]; }
function IPS_GetChildrenIDs($parent) { return array_keys(array_filter(Kernel::$objects, fn($v) => $v['ParentID'] === $parent)); }
function IPS_CreateCategory() {
    $id = Kernel::$next++;
    Kernel::$objects[$id] = ['ParentID' => 0, 'ObjectType' => 0, 'ObjectIdent' => '', 'ObjectName' => ''];
    return $id;
}
function IPS_CreateMedia(int $type) {
    if ($type !== 5) throw new RuntimeException('Expected document media type');
    $id = Kernel::$next++;
    Kernel::$objects[$id] = ['ParentID' => 0, 'ObjectType' => 5, 'ObjectIdent' => '', 'ObjectName' => ''];
    Kernel::$media[$id] = ['MediaType' => $type, 'MediaFile' => '', 'content' => ''];
    return $id;
}
function IPS_SetParent(int $id, int $parent) { Kernel::$objects[$id]['ParentID'] = $parent; return true; }
function IPS_SetIdent(int $id, string $ident) { Kernel::$objects[$id]['ObjectIdent'] = $ident; return true; }
function IPS_SetName(int $id, string $name) { Kernel::$objects[$id]['ObjectName'] = $name; return true; }
function IPS_MediaExists(int $id) { return isset(Kernel::$media[$id]); }
function IPS_GetMedia(int $id) { return Kernel::$media[$id]; }
function IPS_SetMediaFile(int $id, string $file, bool $mustExist) { Kernel::$media[$id]['MediaFile'] = $file; return true; }
function IPS_SetMediaContent(int $id, string $content) {
    if ((Kernel::$failures['media'] ?? '') === 'false') return false;
    if ((Kernel::$failures['media'] ?? '') === 'warning') { trigger_error('Dateizugriff verweigert', E_USER_WARNING); return false; }
    if (isset(Kernel::$failures['media'])) throw new RuntimeException(Kernel::$failures['media']);
    if (base64_decode($content, true) === false) throw new RuntimeException('Expected base64 media content');
    Kernel::$media[$id]['content'] = $content;
    if (Kernel::$failures['mediaAfterWrite'] ?? false) { unset(Kernel::$failures['mediaAfterWrite']); throw new RuntimeException('Unterbrechung nach PDF-Schreiben'); }
    return true;
}
function IPS_GetMediaContent(int $id) { return Kernel::$media[$id]['content']; }
function IPS_GetInstanceListByModuleID($id) { return array_keys(Kernel::$instances); }
function IPS_GetProperty($id, $name) { return Kernel::$instances[$id]->properties[$name]; }
function GetValue($id) { return Kernel::$variables[$id]['value']; }
function SetValue($id, $value) { Kernel::update($id, $value); return true; }
function HasAction($id)
{
    $v = Kernel::$variables[$id];
    return $v['VariableCustomAction'] !== 1 && ($v['VariableCustomAction'] > 1 || $v['VariableAction'] > 0);
}
function RequestAction($id, $value)
{
    Kernel::$calls[] = ['target' => $id, 'value' => $value];
    if (isset(Kernel::$failures[$id])) {
        if (Kernel::$failures[$id] === 'false') return false;
        throw new RuntimeException(Kernel::$failures[$id]);
    }
    if (!HasAction($id)) throw new RuntimeException('No action');
    SetValue($id, $value);
    return true;
}
function IPS_SemaphoreEnter($name, $timeout)
{
    if (Kernel::$locks[$name] ?? false) return false;
    Kernel::$locks[$name] = true;
    return true;
}
function IPS_SemaphoreLeave($name) { Kernel::$locks[$name] = false; return true; }
function IPS_VariableProfileExists($name) { return isset(Kernel::$profiles[$name]); }
function IPS_CreateVariableProfile($name, $type) { Kernel::$profiles[$name] = ['type' => $type]; }
function IPS_SetVariableProfileDigits($name, $digits) { Kernel::$profiles[$name]['digits'] = $digits; }
function IPS_SetVariableProfileText(...$args) {}
function IPS_SetVariableProfileValues(...$args) {}
function IPS_SetVariableProfileAssociation(...$args) {}function WBM_ControlTargets($id) { return Kernel::$instances[$id]->ControlTargets(); }
