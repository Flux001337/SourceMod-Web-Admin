<?php
declare(strict_types=1);

/**
 * FTP-Zugang eines Servers (für den Export der Admin-Dateien). Mit $tls wird FTPS (explizit, AUTH TLS) verwendet.
 */
final class FtpClient
{
    /**
     * Meldet sich an und wechselt in den Pfad. Liefert null bei Erfolg oder den Sprachschlüssel des Fehlers.
     */
    public static function test(string $host, int $port, bool $tls, string $username, string $password, string $path, int $timeout): ?string
    {
        $connection = self::connect($host, $port, $tls, $username, $password, $timeout, $error);
        if ($connection === null)
        {
            return $error;
        }
        try
        {
            if ($path !== '' && !@ftp_chdir($connection, $path))
            {
                return 'servers.test_ftp_path';
            }

            return null;
        }
        finally
        {
            @ftp_close($connection);
        }
    }

    /**
     * Lädt Dateien (Name => Inhalt) in den Pfad hoch und überschreibt vorhandene. Liefert null bei Erfolg oder den
     * Sprachschlüssel des ersten Fehlers.
     *
     * @param array<string, string> $files
     */
    public static function upload(string $host, int $port, bool $tls, string $username, string $password, string $path, array $files, int $timeout): ?string
    {
        $connection = self::connect($host, $port, $tls, $username, $password, $timeout, $error);
        if ($connection === null)
        {
            return $error;
        }
        try
        {
            if ($path !== '' && !@ftp_chdir($connection, $path))
            {
                return 'servers.test_ftp_path';
            }
            foreach ($files as $name => $content)
            {
                $stream = fopen('php://temp', 'r+b');
                fwrite($stream, $content);
                rewind($stream);
                $uploaded = @ftp_fput($connection, basename($name), $stream, FTP_BINARY);
                fclose($stream);
                if (!$uploaded)
                {
                    return 'sm_export.error_ftp_write';
                }
            }

            return null;
        }
        finally
        {
            @ftp_close($connection);
        }
    }

    /**
     * Verbindung mit Anmeldung im passiven Modus. null bei einem Fehler, dann steht der Sprachschlüssel in $error.
     */
    public static function connect(string $host, int $port, bool $tls, string $username, string $password, int $timeout, ?string &$error): ?FTP\Connection
    {
        $error = null;
        $connection = $tls ? @ftp_ssl_connect($host, $port, $timeout) : @ftp_connect($host, $port, $timeout);
        if ($connection === false)
        {
            $error = 'servers.test_ftp_connect';
            return null;
        }
        if (!@ftp_login($connection, $username, $password))
        {
            @ftp_close($connection);
            $error = 'servers.test_ftp_login';
            return null;
        }
        ftp_pasv($connection, true);

        return $connection;
    }
}
