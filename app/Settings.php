<?php
declare(strict_types=1);

/**
 * Einstellungen der Oberfläche (Tabelle settings, Schlüssel/Wert). Einmal je Aufruf komplett geladen.
 */
final class Settings
{
    /** Standardwerte für Einstellungen, die (noch) nicht in der Datenbank stehen. */
    public const DEFAULTS = [
        'site_title' => 'SourceMod Web Admin',
        'site_subtitle' => '',
        'default_language' => 'en',
        'site_theme' => '',
        'users_per_page' => '15',
        'sm_per_page' => '15',
        'server_query_timeout' => '2',
    ];

    /** @var array<string, string>|null */
    private ?array $values = null;

    public function __construct(private readonly Database $db)
    {
    }

    public function get(string $key): string
    {
        $this->values ??= $this->load();

        return $this->values[$key] ?? (self::DEFAULTS[$key] ?? '');
    }

    public function int(string $key, int $min, int $max): int
    {
        return max($min, min($max, (int) $this->get($key)));
    }

    public function set(string $key, string $value): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO ' . $this->db->table('settings') . ' (setting_key, setting_value) VALUES (:key, :value)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        )->execute(['key' => $key, 'value' => $value]);

        if ($this->values !== null)
        {
            $this->values[$key] = $value;
        }
    }

    /** @return array<string, string> */
    private function load(): array
    {
        $values = [];
        foreach ($this->db->pdo()->query('SELECT setting_key, setting_value FROM ' . $this->db->table('settings')) as $row)
        {
            $values[(string) $row['setting_key']] = (string) $row['setting_value'];
        }

        return $values;
    }
}
