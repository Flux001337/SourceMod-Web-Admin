<?php
declare(strict_types=1);

// Router für den PHP-Entwicklungsserver, der keine .htaccess-Dateien kennt:
//
//   php -S localhost:8080 router.php
//
// Er sperrt dieselben Pfade wie die .htaccess-Dateien (Quelltext, Konfiguration, Schema, Sprachdateien, .git ...).

$path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));

$blockedDirectory = preg_match('~(^|/)(\.[^/]+|app|config|console|database|templates|pages|tools|lang|lib)(/|$)~i', $path) === 1;
// Hochgeladene Dateien: nur die von der Oberfläche erzeugten PNG-Icons (wie assets/uploads/.htaccess).
$blockedUpload = preg_match('~^/assets/uploads/~i', $path) === 1 && preg_match('~^/assets/uploads/games/[a-f0-9]{32}\.png$~', $path) !== 1;
$blockedFile = preg_match('~\.(sql|md|json|jsonc|example|log|lock|bak|dist|ini|sh)$~i', $path) === 1;
// Erlaubt sind nur die Einstiegspunkte der Oberfläche und des Installers.
$otherScript = str_ends_with(strtolower($path), '.php') && !in_array($path, ['/index.php', '/install/index.php'], true);

if ($blockedDirectory || $blockedFile || $otherScript || $blockedUpload)
{
    http_response_code(404);
    echo 'Not found';
    return true;
}

return false;
