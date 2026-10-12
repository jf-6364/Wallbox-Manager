param()
$ErrorActionPreference = 'Stop'
$taskRoot = [IO.Path]::GetFullPath((Split-Path -Parent $PSScriptRoot))
$taskStagingRoot = [IO.Path]::GetFullPath((Join-Path $taskRoot ('.dist/package-' + [DateTime]::UtcNow.Ticks)))
$taskBundle = Join-Path $taskStagingRoot 'Wallbox-Manager'
if (!$taskStagingRoot.StartsWith($taskRoot + [IO.Path]::DirectorySeparatorChar)) { throw 'Staging liegt außerhalb des Projekts' }
New-Item -ItemType Directory -Path $taskBundle -Force | Out-Null
foreach ($taskFile in @('library.json', 'README.md', 'LICENSE', '.gitignore')) {
    Copy-Item -LiteralPath (Join-Path $taskRoot $taskFile) -Destination $taskBundle -Force
}
foreach ($taskDirectory in @('WallboxManager', 'libs', 'docs', '.dev')) {
    Copy-Item -LiteralPath (Join-Path $taskRoot $taskDirectory) -Destination $taskBundle -Recurse -Force
}
New-Item -ItemType Directory -Path (Join-Path $taskBundle 'tests') -Force | Out-Null
Get-ChildItem -LiteralPath (Join-Path $taskRoot 'tests') -File | Where-Object { $_.Extension -in @('.php', '.cjs') } | ForEach-Object {
    Copy-Item -LiteralPath $_.FullName -Destination (Join-Path $taskBundle 'tests') -Force
}
$taskZip = Join-Path $taskRoot 'Wallbox-Manager-1.0.zip'
Compress-Archive -LiteralPath $taskBundle -DestinationPath $taskZip -Force
$taskHash = (Get-FileHash -LiteralPath $taskZip -Algorithm SHA256).Hash.ToLowerInvariant()
[IO.File]::WriteAllText(($taskZip + '.sha256'), ($taskHash + '  Wallbox-Manager-1.0.zip' + [Environment]::NewLine), [Text.UTF8Encoding]::new($false))
Add-Type -AssemblyName System.IO.Compression.FileSystem
$taskArchive = [IO.Compression.ZipFile]::OpenRead($taskZip)
try {
    $taskEntries = @($taskArchive.Entries | ForEach-Object { $_.FullName.Replace('\', '/') })
    foreach ($taskRequired in @('Wallbox-Manager/library.json', 'Wallbox-Manager/WallboxManager/module.php', 'Wallbox-Manager/WallboxManager/tile.js')) {
        if ($taskRequired -notin $taskEntries) { throw "Pflichtdatei fehlt im Paket: $taskRequired" }
    }
    if (@($taskEntries | Where-Object { $_ -match '/\.(tools|research)/' }).Count -ne 0) { throw 'Entwicklungsdownload im Paket' }
    Write-Output ("Paket erstellt und geprüft: {0} ({1} Dateien, {2} Byte)" -f $taskZip, $taskEntries.Count, (Get-Item -LiteralPath $taskZip).Length)
    Write-Output ("SHA256: " + $taskHash)
} finally { $taskArchive.Dispose() }