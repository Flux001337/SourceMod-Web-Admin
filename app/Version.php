<?php
declare(strict_types=1);

/**
 * Version der Oberfläche und des Datenbankschemas. Der Installer schreibt beide in die Einstellungen ("version",
 * "db_version"); ein Update vergleicht db_version mit DB und spielt die fehlenden Schritte ein.
 *
 * DB setzt die Zählung des alten SMWA fort (dort zuletzt 5, sql/update.sql "DB 4 -> 5").
 */
final class Version
{
    public const APP = '3.0.0-dev';
    public const DB = 5;
}
