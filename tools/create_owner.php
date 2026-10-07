<?php
declare(strict_types=1);

// Legt den ersten Benutzer (Owner, hat immer alle Rechte) an oder setzt das Passwort eines bestehenden Owners neu.
//
//   php tools/create_owner.php <benutzername> <e-mail>
//
// Das Passwort wird abgefragt (ohne Anzeige) oder über die Umgebungsvariable SMWA_OWNER_PASSWORD übergeben.

if (PHP_SAPI !== 'cli')
{
    http_response_code(404);
    exit;
}

require __DIR__ . '/../config/config.php';
require __DIR__ . '/../app/Database.php';

[$script, $username, $email] = $argv + [null, '', ''];
if ($username === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false)
{
    fwrite(STDERR, "Aufruf: php tools/create_owner.php <benutzername> <e-mail>\n");
    exit(1);
}

$password = (string) getenv('SMWA_OWNER_PASSWORD');
if ($password === '')
{
    echo 'Passwort (mindestens 8 Zeichen): ';
    if (stream_isatty(STDIN))
    {
        shell_exec('stty -echo');
    }
    $password = trim((string) fgets(STDIN));
    if (stream_isatty(STDIN))
    {
        shell_exec('stty echo');
    }
    echo "\n";
}
if (mb_strlen($password) < 8)
{
    fwrite(STDERR, "Das Passwort muss mindestens 8 Zeichen lang sein.\n");
    exit(1);
}

$db = new Database($config['db']);
$users = $db->table('users');
$hash = password_hash($password, PASSWORD_DEFAULT);

$stmt = $db->pdo()->prepare('SELECT id, is_owner FROM ' . $users . ' WHERE username = :username');
$stmt->execute(['username' => $username]);
$existing = $stmt->fetch();

if ($existing)
{
    $db->pdo()->prepare('UPDATE ' . $users . ' SET email = :email, password_hash = :hash, is_owner = 1 WHERE id = :id')
        ->execute(['email' => $email, 'hash' => $hash, 'id' => $existing['id']]);
    echo "Benutzer \"{$username}\" ist jetzt Owner, Passwort und E-Mail wurden gesetzt.\n";
}
else
{
    $db->pdo()->prepare('INSERT INTO ' . $users . ' (username, email, password_hash, is_owner) VALUES (:username, :email, :hash, 1)')
        ->execute(['username' => $username, 'email' => $email, 'hash' => $hash]);
    echo "Owner \"{$username}\" wurde angelegt.\n";
}
