<?php
declare(strict_types=1);

/**
 * Icons der Games. Mitgelieferte liegen in assets/images/games/, hochgeladene in assets/uploads/games/. Gespeichert
 * wird der Pfad unter assets/ (games.icon).
 *
 * Hochgeladene Bilder werden nie unverändert abgelegt: GD liest sie ein und schreibt ein neues PNG mit zufälligem
 * Namen (höchstens MAX_SIZE Pixel). So kann über den Upload keine andere Datei auf den Server gelangen.
 */
final class GameIcon
{
    public const MAX_BYTES = 1024 * 1024;
    /** Wie die mitgelieferten Icons; angezeigt werden sie in 32x32, auf hochauflösenden Bildschirmen größer. */
    public const MAX_SIZE = 128;
    public const UPLOAD_DIRECTORY = 'uploads/games';

    private const ASSETS = __DIR__ . '/../assets/';
    private const PATTERN = '~^(images/games/[A-Za-z0-9_.-]+\.(svg|png|gif|jpe?g|webp)|uploads/games/[a-f0-9]{32}\.png)$~';

    /** URL für das Template oder '' (kein Icon oder Datei fehlt). */
    public static function url(?string $icon): string
    {
        if ($icon === null || preg_match(self::PATTERN, $icon) !== 1 || !is_file(self::ASSETS . $icon))
        {
            return '';
        }

        return 'assets/' . $icon;
    }

    /**
     * Mitgeliefertes Icon zum Spielordner in der besten Qualität (Schema der Keks-Brigarde-Homepage: SVG vor PNG),
     * als Pfad unter assets/ oder null.
     */
    public static function shipped(string $folder): ?string
    {
        foreach (['svg', 'png'] as $extension)
        {
            $icon = 'images/games/' . $folder . '.' . $extension;
            if (preg_match(self::PATTERN, $icon) === 1 && is_file(self::ASSETS . $icon))
            {
                return $icon;
            }
        }

        return null;
    }

    /**
     * Speichert ein hochgeladenes Bild ($_FILES-Eintrag) als PNG und gibt den Pfad unter assets/ zurück.
     *
     * @throws GameIconException mit dem Sprachschlüssel des Fehlers
     */
    public static function storeUpload(array $file): string
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($file['tmp_name'] ?? '')))
        {
            throw new GameIconException(($file['error'] ?? null) === UPLOAD_ERR_INI_SIZE ? 'games.error_icon_size' : 'games.error_icon_upload');
        }
        if ((int) ($file['size'] ?? 0) > self::MAX_BYTES)
        {
            throw new GameIconException('games.error_icon_size');
        }

        return self::store((string) file_get_contents((string) $file['tmp_name']));
    }

    /**
     * Speichert ein Bild aus einer Datei (z. B. beim Import aus dem alten SMWA) als PNG wie storeUpload().
     *
     * @throws GameIconException mit dem Sprachschlüssel des Fehlers
     */
    public static function storeFile(string $path): string
    {
        if (!is_file($path) || filesize($path) > self::MAX_BYTES)
        {
            throw new GameIconException('games.error_icon_size');
        }

        return self::store((string) file_get_contents($path));
    }

    private static function store(string $data): string
    {
        $type = getimagesizefromstring($data)[2] ?? null;
        if (!in_array($type, [IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_JPEG, IMAGETYPE_WEBP], true))
        {
            throw new GameIconException('games.error_icon_type');
        }
        $source = @imagecreatefromstring($data);
        if ($source === false)
        {
            throw new GameIconException('games.error_icon_type');
        }

        // Nur verkleinern: kleine Icons (die alten sind 16x16) würden beim Vergrößern unscharf.
        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min(1, self::MAX_SIZE / max($width, $height));
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));
        $target = imagecreatetruecolor($targetWidth, $targetHeight);
        imagealphablending($target, false);
        imagesavealpha($target, true);
        imagefill($target, 0, 0, imagecolorallocatealpha($target, 0, 0, 0, 127));
        imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        $directory = self::ASSETS . self::UPLOAD_DIRECTORY;
        if (!is_dir($directory) || !is_writable($directory))
        {
            throw new GameIconException('games.error_icon_directory');
        }
        $name = self::UPLOAD_DIRECTORY . '/' . bin2hex(random_bytes(16)) . '.png';
        if (!imagepng($target, self::ASSETS . $name))
        {
            throw new GameIconException('games.error_icon_directory');
        }

        return $name;
    }

    /** Löscht ein hochgeladenes Icon; mitgelieferte bleiben. */
    public static function delete(?string $icon): void
    {
        if ($icon !== null && str_starts_with($icon, self::UPLOAD_DIRECTORY . '/') && preg_match(self::PATTERN, $icon) === 1)
        {
            @unlink(self::ASSETS . $icon);
        }
    }
}

final class GameIconException extends RuntimeException
{
}
