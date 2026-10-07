<?php
declare(strict_types=1);

/**
 * Update einer bestehenden Installation (install/index.php, wenn config/config.php existiert): bringt die Datenbank von
 * der gespeicherten db_version auf Version::DB und setzt die Version der Oberfläche.
 *
 * Ablauf: zuerst die Schritte aus STEPS für jede Version über db_version, nach jedem Schritt wird db_version gespeichert
 * (ein abgebrochenes Update macht beim nächsten Aufruf mit dem fehlenden Schritt weiter). Danach database/schema.sql,
 * das fehlende Tabellen und Einstellungen anlegt. Neue Tabellen brauchen daher keinen Schritt, nur Änderungen an
 * bestehenden Tabellen und Daten.
 */
final class Updater
{
    /**
     * Schritte je Datenbankversion: neue Version => SQL-Anweisungen, Tabellen mit "wa_" wie in database/schema.sql.
     * Dazu Version::DB erhöhen und die Änderung auch in database/schema.sql eintragen (für Neuinstallationen), z. B.:
     *
     *     6 => ['ALTER TABLE `wa_users` ADD COLUMN `note` TEXT NULL DEFAULT NULL'],
     *
     * @var array<int, list<string>>
     */
    private const STEPS = [];

    /** Gespeicherte Datenbankversion (0 = keine). */
    public static function installedVersion(Database $db): int
    {
        return (int) (new Settings($db))->get('db_version');
    }

    /** Muss das Update laufen (ältere Datenbank oder andere Version der Oberfläche)? */
    public static function needed(Database $db): bool
    {
        $settings = new Settings($db);

        return (int) $settings->get('db_version') < Version::DB || $settings->get('version') !== Version::APP;
    }

    /**
     * Führt das Update aus.
     *
     * @return list<int> Datenbankversionen, deren Schritte gelaufen sind
     * @throws RuntimeException wenn die Datenbank neuer ist als diese Dateien
     */
    public static function run(Database $db): array
    {
        $settings = new Settings($db);
        $from = (int) $settings->get('db_version');
        if ($from > Version::DB)
        {
            throw new RuntimeException('Die Datenbank ist neuer als diese Version der Oberfläche.');
        }

        $done = [];
        for ($version = $from + 1; $version <= Version::DB; $version++)
        {
            if (!isset(self::STEPS[$version]))
            {
                continue;
            }
            foreach (self::STEPS[$version] as $statement)
            {
                $db->pdo()->exec(Installer::withPrefix($statement, $db->prefix()));
            }
            $settings->set('db_version', (string) $version);
            $done[] = $version;
        }

        Installer::importSchema($db, $db->prefix());
        $settings->set('db_version', (string) Version::DB);
        $settings->set('version', Version::APP);

        return $done;
    }
}
