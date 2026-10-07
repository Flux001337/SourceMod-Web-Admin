<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/SourceQuery/autoload.php';

use xPaw\SourceQuery\SourceQuery;
use xPaw\SourceQuery\Exception\SourceQueryException;

/**
 * Abfrage und RCON von Source-Servern über die Bibliothek xPaw SourceQuery (lib/SourceQuery, wie die
 * Keks-Brigarde-Homepage). Fehler der Bibliothek werden hier abgefangen; die Seiten bekommen nur Ergebnisse.
 */
final class ServerQuery
{
    /**
     * A2S_INFO. null, wenn der Server nicht (rechtzeitig) antwortet.
     *
     * @return array{name: string, map: string, folder: string, game: string, players: int, max_players: int, bots: int,
     *     os: string, password: bool, vac: bool, version: string, ping: int}|null
     */
    public static function info(string $host, int $port, int $timeout): ?array
    {
        $sourceQuery = new SourceQuery();
        try
        {
            $start = hrtime(true);
            $sourceQuery->Connect($host, $port, $timeout);
            $info = $sourceQuery->GetInfo();
            $ping = (int) round((hrtime(true) - $start) / 1e6);
        }
        catch (SourceQueryException)
        {
            return null;
        }
        finally
        {
            $sourceQuery->Disconnect();
        }

        return [
            'name' => mb_scrub((string) ($info['HostName'] ?? ''), 'UTF-8'),
            'map' => mb_scrub((string) ($info['Map'] ?? ''), 'UTF-8'),
            'folder' => (string) ($info['ModDir'] ?? ''),
            'game' => mb_scrub((string) ($info['ModDesc'] ?? ''), 'UTF-8'),
            'players' => (int) ($info['Players'] ?? 0),
            'max_players' => (int) ($info['MaxPlayers'] ?? 0),
            'bots' => (int) ($info['Bots'] ?? 0),
            'os' => (string) ($info['Os'] ?? ''),
            'password' => (bool) ($info['Password'] ?? false),
            'vac' => (bool) ($info['Secure'] ?? false),
            'version' => (string) ($info['Version'] ?? ''),
            'ping' => $ping,
        ];
    }

    /**
     * Führt einen RCON-Befehl aus. Liefert die Antwort oder die Fehlermeldung (z. B. falsches Passwort).
     *
     * @return array{response: string|null, error: string|null}
     */
    public static function rcon(string $host, int $port, string $password, string $command, int $timeout): array
    {
        $sourceQuery = new SourceQuery();
        try
        {
            $sourceQuery->Connect($host, $port, $timeout, SourceQuery::SOURCE);
            $sourceQuery->SetRconPassword($password);

            return ['response' => (string) $sourceQuery->Rcon($command), 'error' => null];
        }
        catch (SourceQueryException $exception)
        {
            return ['response' => null, 'error' => $exception->getMessage()];
        }
        finally
        {
            $sourceQuery->Disconnect();
        }
    }
}
