<?php
declare(strict_types=1);

// Übernimmt Server und Games aus dem alten SMWA (Tabellen smwa_server und smwa_mods) in die neue Oberfläche.
// Eine ganze 2.x-Installation (auch Benutzer, Rechte, Einstellungen) übernimmt der Installer (install/).
//
//   php tools/import_smwa2.php [--source-db=<datenbank>] [--source-prefix=smwa_] [--old-path=<ordner>] [--dry-run]
//
// --source-db      Datenbank des alten SMWA auf demselben Server (Standard: die Datenbank aus config.php)
// --source-prefix  Tabellen-Präfix des alten SMWA (Standard: smwa_, alte config: $table = "smwa")
// --old-path       Ordner des alten SMWA für die Game-Icons (Standard: old/)
// --dry-run        nur anzeigen, nichts speichern
//
// Das alte SMWA wird nur gelesen. Vorhandene Einträge (gleiche Adresse bzw. gleicher Spielordner) werden übersprungen,
// der Import darf also mehrfach laufen. RCON- und FTP-Passwörter werden mit security.master_key verschlüsselt.

if (PHP_SAPI !== 'cli')
{
    http_response_code(404);
    exit;
}

require __DIR__ . '/../config/config.php';
foreach (['Database', 'SecretCipher', 'GameIcon', 'Smwa2Import'] as $class)
{
    require __DIR__ . '/../app/' . $class . '.php';
}

$options = getopt('', ['source-db:', 'source-prefix:', 'old-path:', 'dry-run']);
$oldPath = rtrim((string) ($options['old-path'] ?? dirname(__DIR__) . '/old'), '/');
$dryRun = isset($options['dry-run']);

$db = new Database($config['db']);
try
{
    $import = new Smwa2Import($db, (string) ($options['source-db'] ?? ''), (string) ($options['source-prefix'] ?? 'smwa_'));
}
catch (InvalidArgumentException)
{
    fwrite(STDERR, "Ungültiger Datenbankname oder Präfix.\n");
    exit(1);
}

echo $dryRun ? "Probelauf, es wird nichts gespeichert.\n" : '';

try
{
    foreach ($import->servers(new SecretCipher((string) ($config['security']['master_key'] ?? '')), $dryRun) as $server)
    {
        echo $server['imported']
            ? "Server {$server['address']} ({$server['name']}) übernommen" . ($server['rcon'] ? ', mit RCON-Passwort' : '') . ".\n"
            : "Server {$server['address']} gibt es schon, übersprungen.\n";
    }
}
catch (Smwa2ImportException)
{
    fwrite(STDERR, "security.master_key fehlt oder ist ungültig, Passwörter können nicht verschlüsselt werden.\n");
    exit(1);
}

foreach ($import->games($oldPath, $dryRun) as $game)
{
    if (!$game['imported'])
    {
        echo "Game {$game['folder']} gibt es schon, übersprungen.\n";
        continue;
    }
    echo $game['icon_failed'] ? "  Icon für {$game['folder']} konnte nicht übernommen werden.\n" : '';
    echo "Game {$game['folder']} ({$game['name']}) übernommen" . ($game['icon'] ? ', mit Icon' : '') . ".\n";
}
