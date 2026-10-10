-- =============================================================================
-- SourceMod Web Admin - Datenbankschema (Neuinstallation)
-- =============================================================================
--
-- Legt die Tabellen der Oberfläche an. Die Tabellen von SourceMod (sm_*) werden hier nicht angelegt oder verändert.
--
-- * Standard-Präfix "wa_" (config/config.php: db.prefix). Bei einem anderen Präfix "wa_" vorher ersetzen.
-- * Die Datei darf nach einem Update erneut importiert werden: sie legt nur fehlende Tabellen und Einstellungen an.
-- * Einfacher: der Installer (install/index.php) spielt diese Datei ein und legt den Owner an.
-- * Sonst danach den ersten Benutzer (Owner) anlegen:  php tools/create_owner.php
-- =============================================================================

CREATE TABLE IF NOT EXISTS `wa_users` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `username` VARCHAR(30) NOT NULL,
    `email` VARCHAR(150) NOT NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    -- NULL = Standard der Seite (settings.default_language / settings.site_theme)
    `language` VARCHAR(8) NULL DEFAULT NULL,
    `theme` VARCHAR(40) NULL DEFAULT NULL,
    -- Der Owner hat immer alle Rechte und ist vor Änderungen durch andere geschützt.
    `is_owner` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_login_at` DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_username` (`username`),
    UNIQUE KEY `uniq_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rechte je Benutzer (wie im alten SMWA). Mögliche Werte: app/Permissions.php (Permissions::ALL).
CREATE TABLE IF NOT EXISTS `wa_user_permissions` (
    `user_id` INT UNSIGNED NOT NULL,
    `permission` VARCHAR(40) NOT NULL,
    PRIMARY KEY (`user_id`, `permission`),
    CONSTRAINT `fk_wa_user_permissions_user` FOREIGN KEY (`user_id`) REFERENCES `wa_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- "Passwort merken": je Anmeldung ein Token, im Cookie steht selector:validator, hier nur der Hash des validators.
CREATE TABLE IF NOT EXISTS `wa_remember_tokens` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `selector` CHAR(24) NOT NULL,
    `token_hash` CHAR(64) NOT NULL,
    `expires_at` DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_selector` (`selector`),
    KEY `idx_user` (`user_id`),
    CONSTRAINT `fk_wa_remember_tokens_user` FOREIGN KEY (`user_id`) REFERENCES `wa_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rate-Limits (z. B. Anmeldeversuche). subject ist ein HMAC, nie der Klartext.
CREATE TABLE IF NOT EXISTS `wa_rate_limits` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `bucket` VARCHAR(32) NOT NULL,
    `subject` CHAR(64) NOT NULL,
    `created_at` INT UNSIGNED NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_lookup` (`bucket`, `subject`, `created_at`),
    KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wa_settings` (
    `setting_key` VARCHAR(64) NOT NULL,
    `setting_value` TEXT NOT NULL,
    PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Games: Spielordner (wie ihn der Server bei der Abfrage meldet, z. B. "cstrike") mit Name und Icon.
CREATE TABLE IF NOT EXISTS `wa_games` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(100) NOT NULL,
    `folder` VARCHAR(64) NOT NULL,
    -- Pfad unter assets/: "images/games/<datei>" (mitgeliefert) oder "uploads/games/<datei>" (hochgeladen); NULL = keins.
    `icon` VARCHAR(120) NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_folder` (`folder`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Standard-Games, nur wenn die Tabelle noch leer ist (gelöschte Einträge kommen bei einem erneuten Import nicht wieder).
INSERT INTO `wa_games` (`name`, `folder`, `icon`)
SELECT d.name, d.folder, d.icon FROM (
    SELECT 'Age of Chivalry' AS name, 'ageofchivalry' AS folder, 'images/games/ageofchivalry.png' AS icon
    UNION ALL SELECT 'Alien Swarm', 'alienswarm', 'images/games/alienswarm.png'
    UNION ALL SELECT 'Black Mesa', 'bms', 'images/games/bms.svg'
    UNION ALL SELECT 'Counter-Strike: Global Offensive', 'csgo', 'images/games/csgo.png'
    UNION ALL SELECT 'Counter-Strike: Source', 'cstrike', 'images/games/cstrike.svg'
    UNION ALL SELECT 'CSPromod', 'cspromod', 'images/games/cspromod.png'
    UNION ALL SELECT 'Day of Defeat: Source', 'dod', 'images/games/dods.png'
    UNION ALL SELECT 'Dystopia', 'dystopia_v1', 'images/games/dystopia_v1.png'
    UNION ALL SELECT 'E.Y.E: Divine Cybermancy', 'eye', 'images/games/eye.png'
    UNION ALL SELECT 'Fortress Forever', 'FortressForever', 'images/games/FortressForever.png'
    UNION ALL SELECT 'Garry''s Mod', 'garrysmod', 'images/games/garrysmod.png'
    UNION ALL SELECT 'Half-Life 2 Capture the Flag', 'hl2ctf', 'images/games/hl2ctf.png'
    UNION ALL SELECT 'Half-Life 2 Deathmatch', 'hl2mp', 'images/games/hl2mp.png'
    UNION ALL SELECT 'Hidden: Source', 'hidden', 'images/games/hidden.png'
    UNION ALL SELECT 'Insurgency: Source', 'insurgency', 'images/games/insurgency.png'
    UNION ALL SELECT 'Left 4 Dead', 'left4dead', 'images/games/l4d.png'
    UNION ALL SELECT 'Left 4 Dead 2', 'left4dead2', 'images/games/l4d2.png'
    UNION ALL SELECT 'Nuclear Dawn', 'nucleardawn', 'images/games/nucleardawn.png'
    UNION ALL SELECT 'Perfect Dark: Source', 'pdark', 'images/games/pdark.png'
    UNION ALL SELECT 'Pirates, Vikings and Knights II', 'pvkii', 'images/games/pvkii.svg'
    UNION ALL SELECT 'Synergy', 'synergy', 'images/games/synergy.png'
    UNION ALL SELECT 'Team Fortress 2', 'tf', 'images/games/tf2.png'
    UNION ALL SELECT 'The Ship', 'ship', 'images/games/ship.png'
    UNION ALL SELECT 'Zombie Panic! Source', 'zps', 'images/games/zps.png'
) AS d
WHERE NOT EXISTS (SELECT 1 FROM `wa_games`);

-- Gameserver. Passwörter sind mit security.master_key verschlüsselt (app/SecretCipher.php), NULL = keins gespeichert.
CREATE TABLE IF NOT EXISTS `wa_servers` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(64) NOT NULL,
    `host` VARCHAR(255) NOT NULL,
    `port` SMALLINT UNSIGNED NOT NULL,
    -- Zugewiesenes Game; NULL = automatisch über den Spielordner, den der Server bei der Abfrage meldet.
    `game_id` INT UNSIGNED NULL DEFAULT NULL,
    -- Zuletzt gemeldeter Spielordner, damit das Game auch offline angezeigt wird; '' = noch nie erreicht.
    `detected_folder` VARCHAR(64) NOT NULL DEFAULT '',
    `rcon_password` TEXT NULL DEFAULT NULL,
    -- FTP-Zugang für den Export der Admin-Dateien; leerer Host = kein FTP.
    `ftp_host` VARCHAR(255) NOT NULL DEFAULT '',
    `ftp_port` SMALLINT UNSIGNED NOT NULL DEFAULT 21,
    `ftp_tls` TINYINT(1) NOT NULL DEFAULT 0,
    `ftp_username` VARCHAR(255) NOT NULL DEFAULT '',
    `ftp_password` TEXT NULL DEFAULT NULL,
    -- Pfad vom FTP-Login zum Ordner addons/sourcemod/configs, z. B. "cstrike/addons/sourcemod/configs".
    `ftp_path` VARCHAR(255) NOT NULL DEFAULT '',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_address` (`host`, `port`),
    CONSTRAINT `fk_wa_servers_game` FOREIGN KEY (`game_id`) REFERENCES `wa_games` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Update älterer Installationen: game_id nachrüsten (nur wenn die Spalte fehlt).
SET @wa_sql = (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE `wa_servers` ADD COLUMN `game_id` INT UNSIGNED NULL DEFAULT NULL AFTER `port`, ADD CONSTRAINT `fk_wa_servers_game` FOREIGN KEY (`game_id`) REFERENCES `wa_games` (`id`) ON DELETE SET NULL',
    'DO 0')
    FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wa_servers' AND COLUMN_NAME = 'game_id');
PREPARE wa_stmt FROM @wa_sql;
EXECUTE wa_stmt;
DEALLOCATE PREPARE wa_stmt;

-- Update älterer Installationen: detected_folder nachrüsten (nur wenn die Spalte fehlt).
SET @wa_sql = (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE `wa_servers` ADD COLUMN `detected_folder` VARCHAR(64) NOT NULL DEFAULT '''' AFTER `game_id`',
    'DO 0')
    FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wa_servers' AND COLUMN_NAME = 'detected_folder');
PREPARE wa_stmt FROM @wa_sql;
EXECUTE wa_stmt;
DEALLOCATE PREPARE wa_stmt;

-- version / db_version: app/Version.php (der Installer setzt sie; ein Update ändert sie).
INSERT IGNORE INTO `wa_settings` (`setting_key`, `setting_value`) VALUES
    ('version', '3.0.1-dev'),
    ('db_version', '5'),
    ('site_title', 'SourceMod Web Admin'),
    ('site_subtitle', ''),
    ('default_language', 'en'),
    ('site_theme', 'Midnight'),
    ('users_per_page', '15'),
    ('sm_per_page', '15'),
    ('server_query_timeout', '2'),
    ('sql_admins_enabled', '1');
