<?php
declare(strict_types=1);

/**
 * Zugriff auf die SQL-Admins von SourceMod (Tabellen sm_admins, sm_groups, sm_admins_groups, sm_group_immunity,
 * sm_group_overrides, sm_overrides). Das Schema legt SourceMod selbst fest (configs/sql-init-scripts/mysql/
 * create_admins.sql, Schema-Version 1409); diese Oberfläche ändert es nicht.
 */
final class SourceMod
{
    /** Admin-Flags in der Reihenfolge von SourceMod (admin_levels.cfg): Buchstabe => Name. */
    public const FLAGS = [
        'a' => 'reservation', 'b' => 'generic', 'c' => 'kick', 'd' => 'ban', 'e' => 'unban', 'f' => 'slay',
        'g' => 'changemap', 'h' => 'cvar', 'i' => 'config', 'j' => 'chat', 'k' => 'vote', 'l' => 'password',
        'm' => 'rcon', 'n' => 'cheats', 'z' => 'root',
        'o' => 'custom1', 'p' => 'custom2', 'q' => 'custom3', 'r' => 'custom4', 's' => 'custom5', 't' => 'custom6',
    ];

    public const AUTH_TYPES = ['steam', 'ip', 'name'];
    public const OVERRIDE_TYPES = ['command', 'group'];
    public const OVERRIDE_ACCESS = ['allow', 'deny'];

    /** Tabellen, ohne die der Bereich nicht funktioniert. */
    public const TABLES = ['admins', 'groups', 'admins_groups', 'group_immunity', 'group_overrides', 'overrides'];

    /** Höchstwert für Immunität (Spalten sind INT UNSIGNED). */
    public const MAX_IMMUNITY = 4294967295;

    /** @var array<string, ?string> Zeichensatz je "tabelle.spalte" */
    private array $charsets = [];

    public function __construct(private readonly Database $db)
    {
    }

    public function table(string $name): string
    {
        return $this->db->sourcemodTable($name);
    }

    /**
     * SourceMod-Tabellen, die in der Datenbank fehlen.
     *
     * @return list<string>
     */
    public function missingTables(): array
    {
        $names = array_map($this->db->sourcemodTableName(...), self::TABLES);
        $stmt = $this->db->pdo()->prepare(
            'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = COALESCE(?, DATABASE())'
            . ' AND TABLE_NAME IN (' . implode(',', array_fill(0, count($names), '?')) . ')'
        );
        $stmt->execute([$this->db->sourcemodDatabase(), ...$names]);
        $found = array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));

        return array_values(array_diff($names, $found));
    }

    public function tableExists(string $name): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = COALESCE(?, DATABASE()) AND TABLE_NAME = ?'
        );
        $stmt->execute([$this->db->sourcemodDatabase(), $this->db->sourcemodTableName($name)]);

        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Prüft, ob ein Text in die Spalte passt. Die Tabellen von SourceMod sind oft noch latin1; Zeichen außerhalb des
     * Zeichensatzes würden sonst einen Datenbankfehler auslösen.
     */
    public function fitsCharset(string $table, string $column, string $value): bool
    {
        $key = $table . '.' . $column;
        if (!array_key_exists($key, $this->charsets))
        {
            $stmt = $this->db->pdo()->prepare(
                'SELECT CHARACTER_SET_NAME FROM information_schema.COLUMNS'
                . ' WHERE TABLE_SCHEMA = COALESCE(?, DATABASE()) AND TABLE_NAME = ? AND COLUMN_NAME = ?'
            );
            $stmt->execute([$this->db->sourcemodDatabase(), $this->db->sourcemodTableName($table), $column]);
            $charset = $stmt->fetchColumn();
            $this->charsets[$key] = is_string($charset) ? strtolower($charset) : null;
        }

        return match ($this->charsets[$key]) {
            // MySQL-"latin1" ist Windows-1252.
            'latin1' => mb_convert_encoding(mb_convert_encoding($value, 'Windows-1252', 'UTF-8'), 'UTF-8', 'Windows-1252') === $value,
            'ascii' => mb_check_encoding($value, 'ASCII'),
            'utf8', 'utf8mb3' => preg_match('/[\x{10000}-\x{10FFFF}]/u', $value) !== 1,
            default => true,
        };
    }

    /**
     * Flags aus einem Formular (Liste von Buchstaben) oder einem Flag-Text in der Reihenfolge von FLAGS, ohne Doppelte
     * und ohne unbekannte Zeichen.
     */
    public static function normalizeFlags(array|string $flags): string
    {
        $letters = is_string($flags) ? str_split($flags) : array_filter($flags, 'is_string');

        return implode('', array_values(array_intersect(array_keys(self::FLAGS), $letters)));
    }

    /**
     * Bereinigt eine Identität: bei Steam und IP ohne Leerzeichen (wie im alten SMWA), Steam-Präfixe in der Schreibweise
     * von SourceMod.
     */
    public static function normalizeIdentity(string $authType, string $identity): string
    {
        $identity = trim($identity);
        if ($authType === 'name')
        {
            return $identity;
        }
        $identity = preg_replace('/\s+/', '', $identity) ?? $identity;
        if ($authType === 'steam')
        {
            $identity = preg_replace('/^steam_/i', 'STEAM_', $identity) ?? $identity;
            $identity = preg_replace('/^\[u:/i', '[U:', $identity) ?? $identity;
        }

        return $identity;
    }

    /**
     * Steam: STEAM_X:Y:Z, [U:1:Z] oder SteamID64 (SourceMod erkennt alle drei Formate). IP: IPv4 ohne Port.
     * Name: 1 bis 65 Zeichen.
     */
    public static function isValidIdentity(string $authType, string $identity): bool
    {
        return match ($authType) {
            'steam' => preg_match('/^(STEAM_[0-5]:[01]:\d{1,10}|\[U:1:\d{1,10}\]|7656119\d{10})$/', $identity) === 1,
            'ip' => filter_var($identity, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false,
            'name' => $identity !== '' && mb_strlen($identity) <= 65,
            default => false,
        };
    }

    /**
     * Enthält der Text Steuerzeichen (z. B. Zeilenumbrüche)? Solche Werte würden in den exportierten Admin-Dateien
     * Zeilen aufbrechen und könnten dort eigene Einträge einschleusen.
     */
    public static function hasControlChars(string $value): bool
    {
        return preg_match('/[\x00-\x1F\x7F]/', $value) === 1;
    }

    /** Immunität aus einem Formularfeld; null bei ungültiger Eingabe. */
    public static function parseImmunity(mixed $value): ?int
    {
        $immunity = filter_var(trim((string) $value), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => self::MAX_IMMUNITY]]);

        return $immunity === false ? null : $immunity;
    }

    /**
     * Checkboxen der Flags für ein Formular.
     *
     * @return list<array{letter: string, name: string, description: string, checked: bool}>
     */
    public static function flagRows(Lang $lang, string $flags): array
    {
        $rows = [];
        foreach (self::FLAGS as $letter => $name)
        {
            $rows[] = [
                'letter' => $letter,
                'name' => $name,
                'description' => $lang->t('sm.flags.' . $letter),
                'checked' => str_contains($flags, $letter),
            ];
        }

        return $rows;
    }

    /**
     * Flags für Listen: je Flag Buchstabe und Name (für den Tooltip). "z" (root) steht für alle Flags.
     *
     * @return list<array{letter: string, name: string}>
     */
    public static function flagPills(string $flags): array
    {
        $pills = [];
        foreach (str_split(self::normalizeFlags($flags)) as $letter)
        {
            if ($letter !== '')
            {
                $pills[] = ['letter' => $letter, 'name' => self::FLAGS[$letter]];
            }
        }

        return $pills;
    }

    /** Führt $work in einer Transaktion aus (bei MyISAM-Tabellen ohne Wirkung, schadet aber nicht). */
    public function transaction(callable $work): void
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try
        {
            $work($pdo);
            $pdo->commit();
        }
        catch (Throwable $exception)
        {
            $pdo->rollBack();
            throw $exception;
        }
    }

    /** Löscht einen Admin samt Gruppenzuordnungen. */
    public function deleteAdmin(int $id): void
    {
        $this->transaction(function (PDO $pdo) use ($id): void {
            $pdo->prepare('DELETE FROM ' . $this->table('admins_groups') . ' WHERE admin_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM ' . $this->table('admins') . ' WHERE id = ?')->execute([$id]);
        });
    }

    /** Löscht eine Gruppe samt Mitgliedschaften, Immunitäten und Overrides (wie das alte SMWA). */
    public function deleteGroup(int $id): void
    {
        // Servergruppen gibt es nur bei Installationen, die sie angelegt haben.
        $hasServerGroups = $this->tableExists('servers_groups');
        $this->transaction(function (PDO $pdo) use ($id, $hasServerGroups): void {
            $pdo->prepare('DELETE FROM ' . $this->table('admins_groups') . ' WHERE group_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM ' . $this->table('group_immunity') . ' WHERE group_id = ? OR other_id = ?')->execute([$id, $id]);
            $pdo->prepare('DELETE FROM ' . $this->table('group_overrides') . ' WHERE group_id = ?')->execute([$id]);
            if ($hasServerGroups)
            {
                $pdo->prepare('DELETE FROM ' . $this->table('servers_groups') . ' WHERE group_id = ?')->execute([$id]);
            }
            $pdo->prepare('DELETE FROM ' . $this->table('groups') . ' WHERE id = ?')->execute([$id]);
        });
    }

    /**
     * Alle Gruppen nach Name, z. B. für Auswahllisten.
     *
     * @return list<array{id: int, name: string, flags: string, immunity_level: int}>
     */
    public function groups(): array
    {
        $rows = $this->db->pdo()->query(
            'SELECT id, name, flags, immunity_level FROM ' . $this->table('groups') . ' ORDER BY name ASC, id ASC'
        )->fetchAll();

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'flags' => (string) $row['flags'],
            'immunity_level' => (int) $row['immunity_level'],
        ], $rows);
    }
}
