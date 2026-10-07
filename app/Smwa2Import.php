<?php
declare(strict_types=1);

/**
 * Übernahme aus SMWA 2.x (Tabellen <präfix>users, settings, server, mods; alte config: $table = "smwa"). Das alte SMWA
 * wird nur gelesen. Der Installer übernimmt damit eine ganze 2.x-Installation, tools/import_smwa2.php nur Server und Games.
 *
 * Die alten Tabellen liegen in derselben oder einer anderen Datenbank auf demselben Server (gleicher Benutzer). Gelesen
 * wird jede 2.x-Datenbankversion (2 bis 5); Tabellen und Spalten, die es dort nicht gibt, werden übergangen.
 */
final class Smwa2Import
{
    // Rechte-Spalten in <präfix>users => Rechte der neuen Oberfläche (app/Permissions.php). "console" gab es nicht.
    private const PERMISSIONS = [
        'UserEditUsers' => 'users',
        'UserEditPermissions' => 'permissions',
        'UserEditInterfacesettings' => 'settings',
        'UserSQLAdmins' => 'sqladmins',
        'UserServersettings' => 'servers',
        'UserEditMods' => 'games',
        'UserPlugincontrol' => 'plugincontrol',
    ];
    // Einstellungen: alter Name => neuer Name, kleinster und größter Wert (wie pages/settings.php).
    private const SETTINGS = [
        'show_users' => ['users_per_page', 5, 200],
        'show_clients' => ['sm_per_page', 5, 200],
        'server_timeout' => ['server_query_timeout', 1, 10],
    ];

    /**
     * @param string $sourceDatabase Datenbank des alten SMWA; leer = die der Oberfläche
     * @param string $sourcePrefix   Präfix der alten Tabellen mit "_", z. B. "smwa_"
     */
    public function __construct(
        private readonly Database $db,
        private readonly string $sourceDatabase,
        private readonly string $sourcePrefix
    ) {
        if (preg_match('/^[A-Za-z0-9_\-]*$/', $sourceDatabase) !== 1 || preg_match('/^[A-Za-z0-9_]*$/', $sourcePrefix) !== 1)
        {
            throw new InvalidArgumentException('Ungültiger Datenbankname oder Präfix.');
        }
    }

    /** Datenbankversion des alten SMWA (Einstellung "db", 0 = unbekannt) oder null, wenn dort kein SMWA 2.x liegt. */
    public function version(): ?int
    {
        if (!$this->exists('users') || !$this->exists('settings'))
        {
            return null;
        }
        $value = $this->db->pdo()->query('SELECT Value FROM ' . $this->source('settings') . " WHERE Name = 'db'")->fetchColumn();

        return (int) $value;
    }

    /**
     * Benutzer mit ihren Rechten, nur in eine leere Benutzertabelle (Installer). Owner wird der Owner mit der kleinsten
     * ID; weitere Owner des alten SMWA erhalten alle Rechte. Die MD5-Passwörter bleiben gültig (Auth::legacyHash()).
     *
     * @param list<string> $languages verfügbare Sprachen; andere (z. B. "default") werden zur Standardsprache der Seite
     * @return list<array{name: string, owner: bool, password: bool}> password = false: kein gültiges altes Passwort
     * @throws Smwa2ImportException "no_owner", wenn das alte SMWA keinen Owner hat
     */
    public function users(array $languages): array
    {
        $rows = $this->db->pdo()->query('SELECT * FROM ' . $this->source('users') . ' ORDER BY UserID')->fetchAll();
        $owners = array_filter($rows, static fn (array $row): bool => (int) ($row['UserOwner'] ?? 0) === 1);
        if ($owners === [])
        {
            throw new Smwa2ImportException('no_owner');
        }
        $ownerId = (int) reset($owners)['UserID'];

        $insertUser = $this->db->pdo()->prepare(
            'INSERT INTO ' . $this->db->table('users') . ' (username, email, password_hash, language, is_owner, created_at)'
            . ' VALUES (:username, :email, :hash, :language, :owner, FROM_UNIXTIME(:created))'
        );
        $insertPermission = $this->db->pdo()->prepare(
            'INSERT INTO ' . $this->db->table('user_permissions') . ' (user_id, permission) VALUES (:user_id, :permission)'
        );

        $result = [];
        foreach ($rows as $row)
        {
            $md5 = (string) $row['UserPass'];
            $hasPassword = preg_match('/^[a-f0-9]{32}$/i', $md5) === 1;
            $isOwner = (int) $row['UserID'] === $ownerId;
            // UserDatum: Unix-Zeit der Anlage.
            $created = ctype_digit((string) $row['UserDatum']) && (int) $row['UserDatum'] > 0 ? (int) $row['UserDatum'] : time();

            $insertUser->execute([
                'username' => (string) $row['UserName'],
                'email' => (string) $row['UserMail'],
                // Ohne gültiges altes Passwort ein unbekanntes Zufallspasswort: ein Admin muss ein neues setzen.
                'hash' => $hasPassword ? Auth::legacyHash($md5) : password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
                'language' => in_array((string) $row['UserLanguage'], $languages, true) ? (string) $row['UserLanguage'] : null,
                'owner' => $isOwner ? 1 : 0,
                'created' => $created,
            ]);
            $userId = (int) $this->db->pdo()->lastInsertId();

            if (!$isOwner)
            {
                $granted = (int) ($row['UserOwner'] ?? 0) === 1 ? Permissions::ALL : array_values(array_filter(
                    self::PERMISSIONS,
                    static fn (string $column): bool => (int) ($row[$column] ?? 0) === 1,
                    ARRAY_FILTER_USE_KEY
                ));
                foreach ($granted as $permission)
                {
                    $insertPermission->execute(['user_id' => $userId, 'permission' => $permission]);
                }
            }
            $result[] = ['name' => (string) $row['UserName'], 'owner' => $isOwner, 'password' => $hasPassword];
        }

        return $result;
    }

    /**
     * Einstellungen, die es in beiden gibt: Einträge je Seite und Timeout der Serverabfrage. Titel und Standardsprache
     * kommen aus dem Installer, das alte Design (style) passt nicht zu den neuen Themes.
     *
     * @return int Anzahl übernommener Einstellungen
     */
    public function settings(Settings $settings): int
    {
        $count = 0;
        $values = $this->db->pdo()->query('SELECT Name, Value FROM ' . $this->source('settings'))->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach (self::SETTINGS as $old => [$new, $min, $max])
        {
            $value = filter_var($values[$old] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]);
            if ($value !== false)
            {
                $settings->set($new, (string) $value);
                $count++;
            }
        }

        return $count;
    }

    /**
     * Server. Vorhandene (gleiche Adresse) werden übersprungen. RCON- und FTP-Passwörter werden verschlüsselt.
     *
     * @return list<array{name: string, address: string, imported: bool, rcon: bool}>
     * @throws Smwa2ImportException "no_key", wenn Passwörter zu verschlüsseln sind, aber security.master_key fehlt
     */
    public function servers(SecretCipher $secrets, bool $dryRun = false): array
    {
        if (!$this->exists('server'))
        {
            return [];
        }
        $encrypt = static function (?string $password) use ($secrets): ?string {
            if ($password === null || $password === '')
            {
                return null;
            }
            if (!$secrets->isConfigured())
            {
                throw new Smwa2ImportException('no_key');
            }

            return $secrets->encrypt($password);
        };

        $exists = $this->db->pdo()->prepare('SELECT COUNT(*) FROM ' . $this->db->table('servers') . ' WHERE host = ? AND port = ?');
        $insert = $this->db->pdo()->prepare(
            'INSERT INTO ' . $this->db->table('servers') . ' (name, host, port, rcon_password, ftp_host, ftp_port, ftp_tls,'
            . ' ftp_username, ftp_password, ftp_path) VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, ?)'
        );
        $result = [];
        foreach ($this->db->pdo()->query('SELECT * FROM ' . $this->source('server') . ' ORDER BY ID') as $row)
        {
            [$host, $port] = self::splitAddress((string) $row['Ip'], 27015);
            [$ftpHost, $ftpPort] = self::splitAddress((string) ($row['ftp_ip'] ?? ''), 21);
            $name = mb_substr(trim((string) $row['Name_Short']) ?: $host, 0, 64);
            $exists->execute([$host, $port]);
            $imported = (int) $exists->fetchColumn() === 0;
            if ($imported && !$dryRun)
            {
                $insert->execute([
                    $name, $host, $port, $encrypt($row['rcon'] ?? null), $ftpHost, $ftpPort,
                    (string) ($row['ftp_username'] ?? ''), $encrypt($row['ftp_pw'] ?? null), (string) ($row['ftp_path'] ?? ''),
                ]);
            }
            $result[] = ['name' => $name, 'address' => $host . ':' . $port, 'imported' => $imported,
                'rcon' => ($row['rcon'] ?? '') !== ''];
        }

        return $result;
    }

    /**
     * Games (alte Tabelle mods). Vorhandene (gleicher Spielordner) werden übersprungen. Icons: das mitgelieferte zum
     * Ordner, sonst das gleichnamige mitgelieferte, sonst das alte Bild aus <oldPath>/inc/pics/games/ neu als PNG.
     *
     * @param string|null $oldPath Ordner des alten SMWA; null = alte Bilder nicht übernehmen
     * @return list<array{name: string, folder: string, imported: bool, icon: bool, icon_failed: bool}>
     */
    public function games(?string $oldPath = null, bool $dryRun = false): array
    {
        if (!$this->exists('mods'))
        {
            return [];
        }
        $exists = $this->db->pdo()->prepare('SELECT COUNT(*) FROM ' . $this->db->table('games') . ' WHERE folder = ?');
        $insert = $this->db->pdo()->prepare('INSERT INTO ' . $this->db->table('games') . ' (name, folder, icon) VALUES (?, ?, ?)');
        $result = [];
        foreach ($this->db->pdo()->query('SELECT name, folder, icon FROM ' . $this->source('mods') . ' ORDER BY ID') as $row)
        {
            $folder = (string) $row['folder'];
            $name = mb_substr((string) $row['name'], 0, 100);
            $exists->execute([$folder]);
            if ((int) $exists->fetchColumn() > 0)
            {
                $result[] = ['name' => $name, 'folder' => $folder, 'imported' => false, 'icon' => false, 'icon_failed' => false];
                continue;
            }

            $icon = GameIcon::shipped($folder);
            $iconFailed = false;
            $file = basename((string) ($row['icon'] ?? ''));
            if ($icon === null && $file !== '' && is_file(__DIR__ . '/../assets/images/games/' . $file))
            {
                $icon = 'images/games/' . $file;
            }
            elseif ($icon === null && $file !== '' && $oldPath !== null && is_file($oldPath . '/inc/pics/games/' . $file) && !$dryRun)
            {
                try
                {
                    $icon = GameIcon::storeFile($oldPath . '/inc/pics/games/' . $file);
                }
                catch (GameIconException)
                {
                    $iconFailed = true;
                }
            }
            if (!$dryRun)
            {
                $insert->execute([$name, $folder, $icon]);
            }
            $result[] = ['name' => $name, 'folder' => $folder, 'imported' => true, 'icon' => $icon !== null,
                'icon_failed' => $iconFailed];
        }

        return $result;
    }

    /** Vollständiger Name einer alten Tabelle, z. B. `keks_smwa`.`smwa_users`. */
    private function source(string $table): string
    {
        return ($this->sourceDatabase !== '' ? '`' . $this->sourceDatabase . '`.' : '') . '`' . $this->sourcePrefix . $table . '`';
    }

    private function exists(string $table): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = COALESCE(NULLIF(:database, \'\'), DATABASE())'
            . ' AND TABLE_NAME = :table'
        );
        $stmt->execute(['database' => $this->sourceDatabase, 'table' => $this->sourcePrefix . $table]);

        return (int) $stmt->fetchColumn() > 0;
    }

    /** "host:port" aus dem alten SMWA aufteilen. */
    private static function splitAddress(string $address, int $defaultPort): array
    {
        $address = str_replace(' ', '', $address);
        if (preg_match('/^(.+):(\d{1,5})$/', $address, $match) === 1)
        {
            return [$match[1], (int) $match[2]];
        }

        return [$address, $defaultPort];
    }
}

/** Übernahme nicht möglich; die Meldung ist ein Schlüssel (install.error_migrate_<meldung>). */
final class Smwa2ImportException extends RuntimeException
{
}
