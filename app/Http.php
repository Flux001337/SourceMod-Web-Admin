<?php
declare(strict_types=1);

/**
 * Kleine Helfer für Angaben zur Anfrage. Weiterleitungs-Header werden nur
 * ausgewertet, wenn die Anfrage von einem konfigurierten Proxy stammt
 * (config: security.trusted_proxies), damit Besucher sie nicht fälschen können.
 */
final class Http
{
    /** @var list<string> */
    private static array $trustedProxies = [];

    public static function configure(array $trustedProxies): void
    {
        self::$trustedProxies = array_values(array_filter($trustedProxies, 'is_string'));
    }

    public static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        {
            return true;
        }
        if ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443)
        {
            return true;
        }

        return self::viaTrustedProxy()
            && strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }

    public static function clientIp(): string
    {
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        if (!self::viaTrustedProxy())
        {
            return $remote;
        }

        // Von rechts nach links: die erste Adresse, die kein vertrauenswürdiger Proxy ist.
        $chain = array_map('trim', explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '')));
        foreach (array_reverse($chain) as $address)
        {
            if (filter_var($address, FILTER_VALIDATE_IP) === false)
            {
                return $remote;
            }
            if (!in_array($address, self::$trustedProxies, true))
            {
                return $address;
            }
        }

        return $remote;
    }

    /**
     * Adresse der Seite, so wie der Browser sie gerade aufruft (Schema, Host und Verzeichnis von index.php, ohne "/" am
     * Ende), z. B. "http://localhost:8080/keks". Null, wenn der Host-Header nicht wie ein Hostname aussieht.
     */
    public static function baseUrl(): ?string
    {
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        if (preg_match('/^(?:[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)*|\[[0-9A-Fa-f:.]+\])(?::\d{1,5})?$/', $host) !== 1)
        {
            return null;
        }
        $directory = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'))), '/');

        return (self::isHttps() ? 'https' : 'http') . '://' . $host . $directory;
    }

    private static function viaTrustedProxy(): bool
    {
        return in_array((string) ($_SERVER['REMOTE_ADDR'] ?? ''), self::$trustedProxies, true);
    }
}
