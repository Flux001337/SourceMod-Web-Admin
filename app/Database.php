<?php
declare(strict_types=1);

/**
 * PDO-Verbindung und Tabellennamen. Die Tabellen der Oberfläche tragen einen Präfix (config: db.prefix, Standard "wa_"),
 * damit sie neben den Tabellen von SourceMod (sm_*) in derselben Datenbank liegen können.
 *
 * Die SourceMod-Tabellen (config: sourcemod.prefix, Standard "sm_") liegen in derselben Datenbank oder, mit
 * sourcemod.database, in einer anderen Datenbank auf demselben Server.
 */
final class Database
{
    private PDO $pdo;
    private string $prefix;
    private string $sourcemodPrefix;
    private string $sourcemodDatabase;

    public function __construct(array $config, array $sourcemod = [])
    {
        $prefix = (string) ($config['prefix'] ?? 'wa_');
        $sourcemodPrefix = (string) ($sourcemod['prefix'] ?? 'sm_');
        $sourcemodDatabase = (string) ($sourcemod['database'] ?? '');
        foreach ([$prefix, $sourcemodPrefix, $sourcemodDatabase] as $name)
        {
            if (preg_match('/^[A-Za-z0-9_]*$/', $name) !== 1)
            {
                throw new RuntimeException('Ungültiger Tabellen-Präfix oder Datenbankname.');
            }
        }
        $this->prefix = $prefix;
        $this->sourcemodPrefix = $sourcemodPrefix;
        $this->sourcemodDatabase = $sourcemodDatabase;

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $config['host'],
            $config['port'],
            $config['database'],
            $config['charset'] ?? 'utf8mb4'
        );

        $this->pdo = new PDO($dsn, $config['username'], $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * Vollständiger Tabellenname in Backticks, z. B. table('users') = `wa_users`.
     */
    public function table(string $name): string
    {
        return '`' . $this->prefix . $name . '`';
    }

    /** Präfix der Tabellen der Oberfläche, z. B. "wa_". */
    public function prefix(): string
    {
        return $this->prefix;
    }

    /**
     * Vollständiger Name einer SourceMod-Tabelle, z. B. sourcemodTable('admins') = `sm_admins`.
     */
    public function sourcemodTable(string $name): string
    {
        $table = '`' . $this->sourcemodPrefix . $name . '`';

        return $this->sourcemodDatabase !== '' ? '`' . $this->sourcemodDatabase . '`.' . $table : $table;
    }

    /** Tabellenname ohne Backticks und Datenbank, z. B. für information_schema. */
    public function sourcemodTableName(string $name): string
    {
        return $this->sourcemodPrefix . $name;
    }

    /** Datenbank der SourceMod-Tabellen; null = die der Oberfläche. */
    public function sourcemodDatabase(): ?string
    {
        return $this->sourcemodDatabase !== '' ? $this->sourcemodDatabase : null;
    }
}
