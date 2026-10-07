<?php
declare(strict_types=1);

/**
 * Wertet die RCON-Antworten von SourceMod zu Plugins und Extensions aus ("sm version", "sm plugins list|info",
 * "sm exts list|info"). Die Formate folgen PluginSys.cpp und ExtensionSys.cpp von SourceMod:
 *
 *   Plugins:    "  01 "Name" (Version) by Autor"            laufendes Plugin
 *               "  07 <Paused> "Name" (Version) by Autor"   Status in spitzen Klammern, wenn es nicht läuft
 *               "  12 <Failed> datei.smx"                    nicht geladen: nur der Dateiname
 *   Extensions: "[01] Name (Version): Beschreibung"         geladene Extension
 *               "[13] <OPTIONAL> file "x.ext.so": Fehler"   nicht geladen: Datei und Fehler
 *
 * Beide Listen liefern Einträge mit denselben Feldern (id, status, failed, name, version, author, description, file).
 */
final class SourceModPlugins
{
    /** Status, bei denen ein Plugin nicht läuft und ein Fehler vorliegt. */
    public const FAILED_STATUSES = ['failed', 'error'];

    /** Autor der mitgelieferten SourceMod-Plugins. */
    public const ALLIEDMODDERS = 'AlliedModders LLC';

    /** Antwort des Servers, wenn SourceMod nicht läuft. */
    public static function isMissing(string $response): bool
    {
        return stripos($response, 'Unknown command "sm"') !== false;
    }

    /**
     * Dateiname eines Plugins relativ zum Plugin-Ordner (z. B. "funvotes.smx", "disabled/test.smx"), wie ihn
     * "sm plugins info" meldet. Nur so etwas wird in "sm plugins load" eingesetzt: keine Leerzeichen, kein "..".
     */
    public static function isPluginFile(string $file): bool
    {
        // D: "$" nur am Ende, nicht vor einem abschließenden Zeilenumbruch.
        return preg_match('~^[A-Za-z0-9_\-][A-Za-z0-9_\-./]*\.smx$~D', $file) === 1 && !str_contains($file, '..');
    }

    /** Wie isPluginFile() für Extensions ("sdktools.ext.2.css.so", "geoip.ext.dll"), eingesetzt in "sm exts load". */
    public static function isExtensionFile(string $file): bool
    {
        return preg_match('~^[A-Za-z0-9_\-][A-Za-z0-9_\-./]*\.(so|dll)$~D', $file) === 1 && !str_contains($file, '..');
    }

    public static function version(string $response): ?string
    {
        return preg_match('/SourceMod Version:\s*(\S+)/i', $response, $match) === 1 ? $match[1] : null;
    }

    /** Metamod:Source-Version aus "meta version" ("Metamod:Source version 1.12.0-dev+1226"). */
    public static function metamodVersion(string $response): ?string
    {
        return preg_match('/Metamod:Source version\s+(\d\S*)/i', $response, $match) === 1 ? $match[1] : null;
    }

    /**
     * @return list<array{id: int, status: string, failed: bool, name: string, version: string, author: string, description: string, file: string}>
     */
    public static function parseList(string $response): array
    {
        $plugins = [];
        foreach (self::lines($response) as $line)
        {
            if (preg_match('/^\s*(\d+)\s+(?:<([^>]+)>\s+)?(?:"(.*?)"(?:\s+\((.*?)\))?(?:\s+by\s+(.*?))?|(\S.*?))\s*$/', $line, $m) !== 1)
            {
                continue;
            }
            $status = strtolower(trim($m[2] ?? '')) ?: 'running';
            $file = (string) ($m[6] ?? '');
            $plugins[] = [
                'id' => (int) $m[1],
                'status' => $status,
                'failed' => in_array($status, self::FAILED_STATUSES, true),
                'name' => $file !== '' ? $file : (string) $m[3],
                'version' => (string) ($m[4] ?? ''),
                'author' => (string) ($m[5] ?? ''),
                'description' => '',
                'file' => $file,
            ];
        }

        return $plugins;
    }

    /**
     * Nicht geladene Extensions (<FAILED>, <OPTIONAL> ...) gelten als fehlerhaft; statt der Beschreibung steht der Fehler.
     *
     * @return list<array{id: int, status: string, failed: bool, name: string, version: string, author: string, description: string, file: string}>
     */
    public static function parseExtensionList(string $response): array
    {
        $extensions = [];
        foreach (self::lines($response) as $line)
        {
            if (preg_match('/^\s*\[(\d+)\]\s+<([^>]+)>\s+file\s+"([^"]*)":\s*(.*?)\s*$/', $line, $m) === 1)
            {
                $extensions[] = [
                    'id' => (int) $m[1],
                    'status' => strtolower(trim($m[2])),
                    'failed' => true,
                    'name' => $m[3],
                    'version' => '',
                    'author' => '',
                    // Fehler ohne den vorangestellten Pfad ("/home/.../extensions/x.ext.so: cannot open ..."); der volle
                    // Text steht in "sm exts info".
                    'description' => (string) preg_replace('~^\S*/' . preg_quote($m[3], '~') . ':\s*~', '', $m[4]),
                    'file' => $m[3],
                ];
            }
            elseif (preg_match('/^\s*\[(\d+)\]\s+(.*?)\s+\(([^()]*)\)(?::\s*(.*?))?\s*$/', $line, $m) === 1)
            {
                $extensions[] = [
                    'id' => (int) $m[1],
                    'status' => 'running',
                    'failed' => false,
                    'name' => $m[2],
                    'version' => $m[3],
                    'author' => '',
                    'description' => (string) ($m[4] ?? ''),
                    'file' => '',
                ];
            }
        }

        return $extensions;
    }

    /**
     * Bestätigung, die SourceMod beim Entladen einer Extension verlangt, von der andere abhängen
     * ("... please use the following command: sm exts unload 5 345"): der Code, sonst null.
     */
    public static function unloadCode(string $response, int $id): ?int
    {
        return preg_match('/sm exts unload ' . $id . ' (\d+)/', $response, $m) === 1 ? (int) $m[1] : null;
    }

    /**
     * "Schlüssel: Wert"-Zeilen von "sm plugins info <id>" bzw. "sm exts info <id>" in der Reihenfolge der Antwort.
     *
     * @return array<string, string>
     */
    public static function parseInfo(string $response): array
    {
        $info = [];
        foreach (self::lines($response) as $line)
        {
            if (preg_match('/^\s*([A-Za-z][A-Za-z ]*?):\s*(.*?)\s*$/', $line, $m) === 1)
            {
                $info[$m[1]] = $m[2];
            }
        }

        return $info;
    }

    /**
     * ConVars eines Plugins aus "sm cvars <id>" (Name => Wert). Format nach PluginSys.cpp:
     *
     *   [SM] Listing 2 convars for: Name
     *     [Name]                           [Value]
     *     sm_foo_enabled                   1
     *
     * SourceMod kürzt Namen über 31 Zeichen in dieser Ausgabe.
     *
     * @return array<string, string>
     */
    public static function parseCvars(string $response): array
    {
        $cvars = [];
        foreach (self::lines($response) as $line)
        {
            if (preg_match('/^\s+(?!\[Name\])(\S+)(?:\s+(.*?))?\s*$/', $line, $m) === 1)
            {
                $cvars[$m[1]] = (string) ($m[2] ?? '');
            }
        }

        return $cvars;
    }

    /**
     * Zeilen einer Antwort. Bei langen Antworten aus mehreren RCON-Paketen stehen an den Paketgrenzen NUL-Bytes im Text.
     *
     * @return list<string>
     */
    public static function lines(string $response): array
    {
        return preg_split('/\R/', str_replace("\0", '', $response)) ?: [];
    }
}
