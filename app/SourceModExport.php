<?php
declare(strict_types=1);

/**
 * Erzeugt die Admin-Dateien von SourceMod (addons/sourcemod/configs) aus den SQL-Admins. Das Format folgt den Parsern
 * des Plugins admin-flatfile (admin-users.sp, admin-groups.sp, admin-overrides.sp, admin-simple.sp).
 *
 * Gegenüber dem alten SMWA korrigiert: IP-Admins in admins_simple.ini mit "!", Passwörter in admins_simple.ini,
 * Befehlsgruppen in Gruppen-Overrides mit "@" (nicht ":"), Immunität gegenüber anderen Gruppen wird mit exportiert.
 */
final class SourceModExport
{
    public const FILES = ['admins.cfg', 'admin_groups.cfg', 'admin_overrides.cfg', 'admins_simple.ini'];

    /** Erste SteamID64 (Account-ID 0). */
    private const STEAM64_BASE = 76561197960265728;

    private const TEMPLATES = __DIR__ . '/../templates/export/';

    public function __construct(private readonly PDO $pdo, private readonly SourceMod $sourcemod)
    {
    }

    /**
     * Inhalt einer Datei und Hinweise dazu (Sprachschlüssel mit Platzhaltern).
     *
     * @return array{content: string, notes: list<array{key: string, replace: array<string, string|int>}>}
     */
    public function generate(string $file): array
    {
        $notes = [];
        $body = match ($file) {
            'admins.cfg' => $this->admins(),
            'admin_groups.cfg' => $this->groups(),
            'admin_overrides.cfg' => $this->overrides(),
            'admins_simple.ini' => $this->adminsSimple($notes),
            default => throw new InvalidArgumentException('Unbekannte Exportdatei: ' . $file),
        };
        $template = (string) file_get_contents(self::TEMPLATES . $file);

        return [
            'content' => strtr($template, ['%date%' => gmdate('Y-m-d H:i') . ' UTC', '%insert%' => $body]),
            'notes' => $notes,
        ];
    }

    private function admins(): string
    {
        $groupsByAdmin = $this->groupNamesByAdmin();
        $lines = ['Admins', '{'];
        foreach ($this->adminRows() as $admin)
        {
            $lines[] = "\t" . self::quote($admin['name']);
            $lines[] = "\t{";
            $lines[] = "\t\t\"auth\"\t\t" . self::quote($admin['authtype']);
            $lines[] = "\t\t\"identity\"\t" . self::quote($admin['identity']);
            if ($admin['password'] !== '')
            {
                $lines[] = "\t\t\"password\"\t" . self::quote($admin['password']);
            }
            foreach ($groupsByAdmin[$admin['id']] ?? [] as $group)
            {
                $lines[] = "\t\t\"group\"\t\t" . self::quote($group);
            }
            if ($admin['flags'] !== '')
            {
                $lines[] = "\t\t\"flags\"\t\t" . self::quote($admin['flags']);
            }
            if ($admin['immunity'] > 0)
            {
                $lines[] = "\t\t\"immunity\"\t" . self::quote((string) $admin['immunity']);
            }
            $lines[] = "\t}";
        }
        $lines[] = '}';

        return implode("\n", $lines) . "\n";
    }

    private function groups(): string
    {
        $groups = $this->sourcemod->groups();
        $names = array_column($groups, 'name', 'id');

        $immunity = [];
        foreach ($this->pdo->query('SELECT group_id, other_id FROM ' . $this->sourcemod->table('group_immunity')) as $row)
        {
            if (isset($names[(int) $row['other_id']]))
            {
                $immunity[(int) $row['group_id']][] = $names[(int) $row['other_id']];
            }
        }
        $overrides = [];
        foreach ($this->pdo->query(
            'SELECT group_id, type, name, access FROM ' . $this->sourcemod->table('group_overrides') . ' ORDER BY type DESC, name ASC'
        ) as $row)
        {
            $overrides[(int) $row['group_id']][] = [($row['type'] === 'group' ? '@' : '') . $row['name'], (string) $row['access']];
        }

        // Höhere Immunität zuerst, wie im alten SMWA.
        usort($groups, static fn (array $a, array $b): int => [$b['immunity_level'], $a['name']] <=> [$a['immunity_level'], $b['name']]);
        $lines = ['Groups', '{'];
        foreach ($groups as $group)
        {
            $lines[] = "\t" . self::quote($group['name']);
            $lines[] = "\t{";
            if ($group['flags'] !== '')
            {
                $lines[] = "\t\t\"flags\"\t\t" . self::quote($group['flags']);
            }
            $lines[] = "\t\t\"immunity\"\t" . self::quote((string) $group['immunity_level']);
            foreach ($immunity[$group['id']] ?? [] as $other)
            {
                $lines[] = "\t\t\"immunity\"\t" . self::quote('@' . $other);
            }
            if (isset($overrides[$group['id']]))
            {
                $lines[] = '';
                $lines[] = "\t\tOverrides";
                $lines[] = "\t\t{";
                foreach ($overrides[$group['id']] as [$name, $access])
                {
                    $lines[] = "\t\t\t" . self::quote($name) . "\t" . self::quote($access);
                }
                $lines[] = "\t\t}";
            }
            $lines[] = "\t}";
        }
        $lines[] = '}';

        return implode("\n", $lines) . "\n";
    }

    private function overrides(): string
    {
        $lines = ['Overrides', '{'];
        foreach ($this->pdo->query(
            'SELECT type, name, flags FROM ' . $this->sourcemod->table('overrides') . ' ORDER BY type DESC, name ASC'
        ) as $row)
        {
            $name = ($row['type'] === 'group' ? '@' : '') . $row['name'];
            $lines[] = "\t" . self::quote($name) . "\t" . self::quote(SourceMod::normalizeFlags((string) $row['flags']));
        }
        $lines[] = '}';

        return implode("\n", $lines) . "\n";
    }

    /**
     * admins_simple.ini: je Admin eine Zeile. Die Datei kennt nur Flags ODER eine Gruppe; Admins mit eigenen Flags
     * behalten die Flags, sonst wird die erste Gruppe verwendet. Was dabei wegfällt, steht in den Hinweisen.
     *
     * @param list<array{key: string, replace: array<string, string|int>}> $notes
     */
    private function adminsSimple(array &$notes): string
    {
        $groupsByAdmin = $this->groupNamesByAdmin();
        $lines = [];
        $incomplete = 0;
        $skipped = 0;
        foreach ($this->adminRows() as $admin)
        {
            $identity = match ($admin['authtype']) {
                'ip' => '!' . $admin['identity'],
                'steam' => self::simpleSteamId($admin['identity']),
                default => $admin['identity'],
            };
            $groups = $groupsByAdmin[$admin['id']] ?? [];
            if ($admin['flags'] !== '' || $groups === [])
            {
                $permissions = $admin['flags'];
                $incomplete += $groups !== [] ? 1 : 0;
            }
            else
            {
                $permissions = '@' . $groups[0];
                $incomplete += count($groups) > 1 ? 1 : 0;
            }
            if ($admin['immunity'] > 0)
            {
                $permissions = $admin['immunity'] . ':' . $permissions;
            }

            // Die Datei kennt keine Escape-Zeichen: Anführungszeichen und Zeilenumbrüche (z. B. aus SourceBans oder
            // sm_sql_addadmin) lassen sich nicht darstellen und würden die Zeile aufbrechen.
            $values = $identity . $permissions . $admin['password'];
            if (str_contains($values, '"') || SourceMod::hasControlChars($values))
            {
                $skipped++;
                continue;
            }
            $line = '"' . $identity . "\"\t\"" . $permissions . '"';
            if ($admin['password'] !== '')
            {
                $line .= "\t\"" . $admin['password'] . '"';
            }
            $lines[] = $line . "\t// " . str_replace(["\r", "\n"], ' ', $admin['name']);
        }
        if ($incomplete > 0)
        {
            $notes[] = ['key' => 'sm_export.note_simple_groups', 'replace' => ['count' => $incomplete]];
        }
        if ($skipped > 0)
        {
            $notes[] = ['key' => 'sm_export.note_simple_skipped', 'replace' => ['count' => $skipped]];
        }

        return implode("\n", $lines) . "\n";
    }

    /** @return list<array{id: int, authtype: string, identity: string, password: string, flags: string, name: string, immunity: int}> */
    private function adminRows(): array
    {
        $rows = [];
        foreach ($this->pdo->query(
            'SELECT id, authtype, identity, password, flags, name, immunity FROM ' . $this->sourcemod->table('admins') . ' ORDER BY name ASC, id ASC'
        ) as $row)
        {
            $rows[] = [
                'id' => (int) $row['id'],
                'authtype' => (string) $row['authtype'],
                'identity' => (string) $row['identity'],
                'password' => (string) ($row['password'] ?? ''),
                'flags' => SourceMod::normalizeFlags((string) $row['flags']),
                'name' => (string) $row['name'],
                'immunity' => (int) $row['immunity'],
            ];
        }

        return $rows;
    }

    /** @return array<int, list<string>> Gruppennamen je Admin in der Reihenfolge inherit_order */
    private function groupNamesByAdmin(): array
    {
        $result = [];
        foreach ($this->pdo->query(
            'SELECT ag.admin_id, g.name FROM ' . $this->sourcemod->table('admins_groups') . ' ag JOIN '
            . $this->sourcemod->table('groups') . ' g ON g.id = ag.group_id ORDER BY ag.inherit_order ASC, g.name ASC'
        ) as $row)
        {
            $result[(int) $row['admin_id']][] = (string) $row['name'];
        }

        return $result;
    }

    /** admins_simple.ini erkennt nur STEAM_X:Y:Z und [U:1:Z]; SteamID64 wird umgerechnet. */
    private static function simpleSteamId(string $identity): string
    {
        if (preg_match('/^7656119\d{10}$/', $identity) === 1)
        {
            return '[U:1:' . ((int) $identity - self::STEAM64_BASE) . ']';
        }

        return $identity;
    }

    /** Wert in Anführungszeichen für KeyValues-Dateien (SMC-Parser: \" und \\ als Escape). */
    private static function quote(string $value): string
    {
        return '"' . strtr($value, ['\\' => '\\\\', '"' => '\\"', "\r" => '', "\n" => ' ']) . '"';
    }
}
