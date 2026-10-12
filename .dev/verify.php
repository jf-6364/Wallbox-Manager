<?php
declare(strict_types=1);
require_once __DIR__ . '/../libs/bootstrap.php';
use WBM\Schema;
$root = dirname(__DIR__);
$fail = [];
$count = ['php' => 0, 'json' => 0];
$iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($iter as $file) {
    $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
    if (preg_match('~^(\.tools|\.research|\.dist)/~', $relative)) continue;
    if ($file->getExtension() === 'php') {
        $cmd = escapeshellarg(PHP_BINARY) . ' -n -l ' . escapeshellarg($file->getPathname()) . ' 2>&1';
        exec($cmd, $output, $code);
        if ($code !== 0) $fail[] = $relative . ': PHP-Syntaxfehler ' . implode("\n", $output);
        $output = [];
        $count['php']++;
    }
    if ($file->getExtension() === 'json') {
        try { json_decode(file_get_contents($file->getPathname()), true, 512, JSON_THROW_ON_ERROR); }
        catch (Throwable $e) { $fail[] = $relative . ': ' . $e->getMessage(); }
        $count['json']++;
    }
}
$library = json_decode(file_get_contents($root . '/library.json'), true);
$module = json_decode(file_get_contents($root . '/WallboxManager/module.json'), true);
$guid = '/^\{[A-F0-9]{8}(?:-[A-F0-9]{4}){3}-[A-F0-9]{12}\}$/';
foreach (['library' => $library, 'module' => $module] as $name => $meta) if (!preg_match($guid, $meta['id'] ?? '')) $fail[] = $name . ': ungültige GUID';
if ($library['id'] === $module['id']) $fail[] = 'GUIDs nicht eindeutig';
foreach (['parentRequirements', 'childRequirements', 'implemented', 'aliases'] as $key) if (!is_array($module[$key] ?? null)) $fail[] = 'Modulmetadaten fehlen: ' . $key;
if ($module['prefix'] !== 'WBM' || $module['type'] !== 3 || $library['compatibility']['version'] !== '7.1') $fail[] = 'Modulmetadaten inkonsistent';
foreach (scandir($root) as $dir) {
    if ($dir[0] === '.' || !is_dir($root . '/' . $dir) || in_array($dir, ['libs', 'docs', 'imgs', 'tests', 'actions'], true)) continue;
    if (!is_file($root . '/' . $dir . '/module.json')) $fail[] = 'Symcon erkennt diesen Ordner als fehlerhaftes Modul: ' . $dir;
}
foreach (['two-11kw.json', 'battery-overlay.json', 'stop-1a.json'] as $name) {
    $overlay = json_decode(file_get_contents($root . '/docs/examples/' . $name), true);
    $errors = WBM\Validation::properties(array_replace(Schema::defaults(), $overlay));
    if ($errors) $fail[] = $name . ': ' . implode('; ', $errors);
}
echo "{$count['php']} PHP-Dateien syntaktisch gültig; {$count['json']} JSON-Dateien geprüft\n";
echo "Metadaten, GUIDs, Modulstruktur und Beispielkonfigurationen geprüft\n";
foreach ($fail as $error) echo "FAIL $error\n";
exit($fail ? 1 : 0);