<?php
declare(strict_types=1);

/**
 * Neuinstallation oder Übernahme von SMWA 2.x (install/index.php): Systemprüfung, Datenbankschema mit eigenem Präfix
 * einspielen, Version und erste Einstellungen setzen, Owner anlegen, config/config.php erzeugen. Das Einspielen des
 * Schemas nutzt auch das Update (app/Updater.php).
 */
final class Installer
{
    public const SCHEMA = __DIR__ . '/../database/schema.sql';
    public const CONFIG = __DIR__ . '/../config/config.php';

    /**
     * Voraussetzungen: Sprachschlüssel (install.req_*), erfüllt, zwingend nötig, Zusatzangabe.
     *
     * @return list<array{key: string, ok: bool, required: bool, detail: string}>
     */
    public static function requirements(): array
    {
        $writable = static fn (string $path): bool => is_dir($path) && is_writable($path);

        return [
            ['key' => 'php', 'ok' => PHP_VERSION_ID >= 80200, 'required' => true, 'detail' => PHP_VERSION],
            ['key' => 'pdo_mysql', 'ok' => extension_loaded('pdo_mysql'), 'required' => true, 'detail' => ''],
            ['key' => 'mbstring', 'ok' => extension_loaded('mbstring'), 'required' => true, 'detail' => ''],
            ['key' => 'sodium', 'ok' => function_exists('sodium_crypto_secretbox'), 'required' => true, 'detail' => ''],
            ['key' => 'gd', 'ok' => extension_loaded('gd'), 'required' => false, 'detail' => ''],
            ['key' => 'ftp', 'ok' => function_exists('ftp_connect'), 'required' => false, 'detail' => ''],
            ['key' => 'config_writable', 'ok' => $writable(dirname(self::CONFIG)), 'required' => false, 'detail' => 'config/'],
            ['key' => 'uploads_writable', 'ok' => $writable(__DIR__ . '/../assets/uploads/games'), 'required' => false,
                'detail' => 'assets/uploads/games/'],
        ];
    }

    /**
     * Ist in dieser Datenbank (mit diesem Präfix) schon eine Oberfläche installiert? Maßgeblich sind vorhandene
     * Benutzer: Bricht eine Installation nach dem Anlegen der Tabellen ab, lässt sie sich so wiederholen.
     */
    public static function isInstalled(Database $db): bool
    {
        try
        {
            return (int) $db->pdo()->query('SELECT COUNT(*) FROM ' . $db->table('users'))->fetchColumn() > 0;
        }
        catch (PDOException)
        {
            return false;
        }
    }

    /**
     * Tabellen von SMWA 2.x mit dem Präfix der Oberfläche, die denselben Namen wie neue Tabellen haben (users, settings;
     * erkannt an ihren alten Spalten UserID bzw. Name). Gibt es sie, darf das Präfix nicht verwendet werden.
     *
     * @return list<string>
     */
    public static function legacyTables(Database $db): array
    {
        $stmt = $db->pdo()->prepare(
            'SELECT TABLE_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()'
            . ' AND ((TABLE_NAME = :users AND COLUMN_NAME = \'UserID\') OR (TABLE_NAME = :settings AND COLUMN_NAME = \'Name\'))'
            . ' ORDER BY TABLE_NAME'
        );
        $stmt->execute(['users' => $db->prefix() . 'users', 'settings' => $db->prefix() . 'settings']);

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /** Spielt database/schema.sql mit dem Präfix der Datenbank ein. */
    public static function importSchema(Database $db, string $prefix): void
    {
        foreach (self::statements(self::withPrefix((string) file_get_contents(self::SCHEMA), $prefix)) as $statement)
        {
            $db->pdo()->exec($statement);
        }
    }

    /**
     * Ersetzt "wa_" in Tabellennamen (`wa_…`, 'wa_…' in information_schema-Abfragen) und Fremdschlüsseln (fk_wa_…)
     * durch den Präfix; Variablen wie @wa_sql bleiben unverändert.
     */
    public static function withPrefix(string $sql, string $prefix): string
    {
        return (string) preg_replace(['/(?<=[`\'])wa_/', '/\bfk_wa_/'], [$prefix, 'fk_' . $prefix], $sql);
    }

    /**
     * Einzelne Anweisungen einer SQL-Datei: Kommentarzeilen fallen weg, getrennt wird an ";" am Zeilenende (die Datei
     * enthält keine Semikolons am Zeilenende innerhalb von Zeichenketten).
     *
     * @return list<string>
     */
    public static function statements(string $sql): array
    {
        $lines = array_filter(
            preg_split('/\R/', $sql) ?: [],
            static fn (string $line): bool => !str_starts_with(ltrim($line), '--')
        );
        $statements = preg_split('/;\s*(?:\n|$)/', implode("\n", $lines)) ?: [];

        return array_values(array_filter(array_map('trim', $statements), static fn (string $s): bool => $s !== ''));
    }

    /** Version, Datenbankversion und die Einstellungen aus dem Installer setzen. */
    public static function saveSettings(Database $db, string $siteTitle, string $language): void
    {
        $settings = new Settings($db);
        $settings->set('version', Version::APP);
        $settings->set('db_version', (string) Version::DB);
        $settings->set('site_title', $siteTitle);
        $settings->set('default_language', $language);
    }

    /**
     * Legt den Owner mit der gewählten Sprache an: Ohne eigene Sprache gälte zuerst ein altes Sprach-Cookie des Browsers
     * (z. B. "en") und erst danach die Standardsprache.
     */
    public static function createOwner(Database $db, string $username, string $email, string $password, string $language): void
    {
        $db->pdo()->prepare(
            'INSERT INTO ' . $db->table('users') . ' (username, email, password_hash, language, is_owner)'
            . ' VALUES (:username, :email, :hash, :language, 1)'
        )->execute([
            'username' => $username,
            'email' => $email,
            'hash' => password_hash($password, PASSWORD_DEFAULT),
            'language' => $language,
        ]);
    }

    /** Neuer Schlüssel für security.master_key (32 Bytes, base64). */
    public static function newMasterKey(): string
    {
        return base64_encode(random_bytes(32));
    }

    /**
     * Inhalt von config/config.php, aufgebaut wie config/config.php.example.
     *
     * @param array{host: string, port: int, database: string, username: string, password: string, prefix: string} $database
     * @param array{database: string, prefix: string} $sourcemod
     */
    public static function configFile(array $database, array $sourcemod, string $masterKey): string
    {
        $value = static fn (mixed $v): string => var_export($v, true);
        $date = gmdate('Y-m-d H:i') . ' UTC';

        return <<<PHP
<?php
declare(strict_types=1);

// Erzeugt vom Installer am {$date}. Aufbau wie config/config.php.example.

\$config = [
    'db' => [
        'host' => {$value($database['host'])},
        'port' => {$value($database['port'])},
        'database' => {$value($database['database'])},
        'username' => {$value($database['username'])},
        'password' => {$value($database['password'])},
        'charset' => 'utf8mb4',
        'prefix' => {$value($database['prefix'])},
    ],
    'sourcemod' => [
        // Leer = dieselbe Datenbank wie oben.
        'database' => {$value($sourcemod['database'])},
        'prefix' => {$value($sourcemod['prefix'])},
    ],
    'debug' => [
        'enabled' => false,
    ],
    'security' => [
        // Verschlüsselt RCON- und FTP-Passwörter. Wird er geändert, müssen diese neu eingegeben werden.
        'master_key' => getenv('APP_MASTER_KEY') ?: {$value($masterKey)},
        'trusted_proxies' => [],
        'session_idle_timeout' => 7200,
    ],
];

// Lokale Abweichungen (z. B. eine Entwicklungsdatenbank) überschreiben die Werte oben.
\$localConfigPath = __DIR__ . '/config.local.php';
if (is_file(\$localConfigPath))
{
    \$localConfig = require \$localConfigPath;
    if (is_array(\$localConfig))
    {
        \$config = array_replace_recursive(\$config, \$localConfig);
    }
}

PHP;
    }

    /** Schreibt config/config.php (nur Lesen für andere). false, wenn der Ordner nicht beschreibbar ist. */
    public static function writeConfig(string $content): bool
    {
        if (is_file(self::CONFIG) || !is_writable(dirname(self::CONFIG)))
        {
            return false;
        }
        if (file_put_contents(self::CONFIG, $content, LOCK_EX) === false)
        {
            return false;
        }
        @chmod(self::CONFIG, 0640);

        return true;
    }
}
