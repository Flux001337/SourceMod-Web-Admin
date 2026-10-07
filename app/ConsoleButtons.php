<?php
declare(strict_types=1);

/**
 * Befehls-Buttons der RCON-Konsole aus console/*.json bzw. *.jsonc (JSON mit Kommentaren, siehe console/server.jsonc),
 * eine Datei je Gruppe (z. B. console/basebans.json für das Plugin basebans). Aufbau angelehnt an Simple-RCON-Tool
 * (label, command, confirm, {arg}, argmode):
 *
 *   {
 *     "name": {"en": "Bans", "de": "Bans"},          Überschrift der Gruppe (Text oder je Sprache)
 *     "description": "...",                          optional, Tooltip der Überschrift
 *     "order": 30,                                   optional, Reihenfolge (sonst 100, dann Dateiname)
 *     "open": true,                                  optional, Gruppe anfangs aufgeklappt (sonst zugeklappt)
 *     "requires": ["basebans.smx", "sbpp_main.smx"], optional, nur zeigen, wenn eines davon auf dem Server läuft
 *     "games": ["cstrike", "bms"],                   optional, nur für Server mit diesem Spielordner
 *     "buttons": [
 *       {
 *         "label": {"en": "Ban", "de": "Bannen"},
 *         "command": "sm_ban {target} {minutes} {reason}",
 *         "description": "...",                      optional, Tooltip
 *         "confirm": {"en": "Ban this player?"},     optional, Rückfrage vor dem Senden
 *         "argmode": "underscore",                   optional, Leerzeichen in Werten durch _ ersetzen
 *         "args": {                                  optional, Angaben zu den Platzhaltern
 *           "target": {"label": "Spieler", "placeholder": "#userid oder Name", "quote": true, "suggest": "player"},
 *           "minutes": {"label": "Minuten", "default": "0", "type": "number", "options": ["0", "60", "1440"]},
 *           "reason": {"label": "Grund", "required": false}
 *         }
 *       }
 *     ]
 *   }
 *
 * Jeder Platzhalter {name} im Befehl wird vor dem Senden abgefragt; ohne Angaben unter "args" mit dem Namen als
 * Beschriftung. "quote" setzt den Wert in Anführungszeichen (Namen mit Leerzeichen). "required": false erlaubt leere Werte.
 * "suggest" bietet die Spieler aus "status" als Vorschläge an: "player" (#userid), "steamid" oder "ip".
 */
final class ConsoleButtons
{
    /** Werte für "suggest". */
    public const SUGGEST = ['player', 'steamid', 'ip'];

    /** Höchstlänge eines gebündelten Befehls (Source-Konsole: 511 Zeichen je Zeile, mit Reserve). */
    private const COMMAND_LENGTH = 480;

    /** @var list<string> Fehler beim Lesen, "datei.json: Grund" */
    private array $errors = [];

    public function __construct(private readonly string $directory, private readonly string $language)
    {
    }

    /**
     * Gruppen für einen Server mit Spielordner $folder (zugewiesenes oder erkanntes Game): Gruppen mit "games" nur, wenn
     * der Ordner passt. Ist er unbekannt (Server noch nie erreicht), werden sie gezeigt.
     */
    public function groupsFor(string $folder): array
    {
        $folder = strtolower($folder);

        return array_values(array_filter(
            $this->groups(),
            static fn (array $group): bool => $group['games'] === [] || $folder === '' || in_array($folder, $group['games'], true)
        ));
    }

    /**
     * RCON-Befehle, die alle Plugins aus "requires" abfragen, mehrere je Befehl ("sm plugins info a.smx;sm plugins info
     * b.smx"). Die Source-Konsole schneidet Zeilen nach 511 Zeichen ab, daher höchstens COMMAND_LENGTH je Befehl.
     *
     * @param list<array{requires: list<string>}> $groups
     * @return list<string>
     */
    public static function requiresCommands(array $groups): array
    {
        $files = array_values(array_unique(array_merge([], ...array_map(static fn (array $group): array => $group['requires'], $groups))));
        $commands = [];
        $current = '';
        foreach ($files as $file)
        {
            $part = 'sm plugins info ' . $file;
            if ($current !== '' && strlen($current) + 1 + strlen($part) > self::COMMAND_LENGTH)
            {
                $commands[] = $current;
                $current = '';
            }
            $current .= ($current === '' ? '' : ';') . $part;
        }

        return $current === '' ? $commands : [...$commands, $current];
    }

    /**
     * Laufende Plugins (Dateinamen) aus den Antworten von requiresCommands(): "Filename: x.smx" ... "Status: running".
     * Nicht geladene melden "[SM] Plugin x.smx is not loaded.".
     *
     * @return list<string>
     */
    public static function runningPlugins(string $response): array
    {
        return array_keys(array_filter(self::pluginInfos($response), static fn (array $info): bool => $info['running']));
    }

    /**
     * Dateiname => Titel und Status aus den Antworten von requiresCommands() ("Title: Name (Beschreibung)"), z. B. um
     * Einträge aus "sm plugins list" (nur Namen) einer Datei zuzuordnen.
     *
     * @return array<string, array{title: string, running: bool}>
     */
    public static function pluginInfos(string $response): array
    {
        $infos = [];
        $file = null;
        foreach (SourceModPlugins::lines($response) as $line)
        {
            if (preg_match('/^\s*Filename:\s*(\S+)/', $line, $m) === 1)
            {
                $file = strtolower($m[1]);
                $infos[$file] = ['title' => '', 'running' => false];
            }
            elseif ($file !== null && preg_match('/^\s*Title:\s*(.*?)\s*$/', $line, $m) === 1)
            {
                $infos[$file]['title'] = $m[1];
            }
            elseif ($file !== null && preg_match('/^\s*Status:\s*(\S+)/', $line, $m) === 1)
            {
                $infos[$file]['running'] = strtolower($m[1]) === 'running';
                $file = null;
            }
        }

        return $infos;
    }

    /**
     * Spieler aus der Antwort von "status". Formate (Source, CS:GO mit zusätzlicher Spalte):
     *
     *   #      2 "CS:S SrcTV"        BOT                                       active
     *   #      3 "Name"  STEAM_0:1:1234  05:12  60  0 active 1.2.3.4:27005
     *   #  4 2 "Name" STEAM_1:0:1234 05:12 60 0 active 196608 1.2.3.4:27005
     *
     * @return list<array{userid: int, name: string, steamid: string, ip: string, bot: bool}>
     */
    public static function statusPlayers(string $response): array
    {
        $players = [];
        foreach (SourceModPlugins::lines($response) as $line)
        {
            if (preg_match('/^#\s*(\d+)\s+(?:\d+\s+)?"(.*)"\s+(\S+)(.*)$/', $line, $m) !== 1)
            {
                continue;
            }
            $ip = preg_match('/(\d{1,3}(?:\.\d{1,3}){3}):\d+\s*$/', $m[4], $address) === 1 ? $address[1] : '';
            $players[] = [
                'userid' => (int) $m[1],
                'name' => mb_scrub($m[2], 'UTF-8'),
                'steamid' => $m[3] === 'BOT' ? '' : $m[3],
                'ip' => $ip,
                'bot' => $m[3] === 'BOT',
            ];
        }

        return $players;
    }

    /**
     * Gruppen mit Buttons in Anzeigereihenfolge.
     *
     * @return list<array{file: string, name: string, description: string, buttons: list<array{label: string,
     *     command: string, description: string, confirm: string, underscore: bool, args: list<array{name: string,
     *     label: string, placeholder: string, default: string, type: string, options: list<string>, quote: bool,
     *     required: bool}>}>}>
     */
    public function groups(): array
    {
        $this->errors = [];
        $groups = [];
        // .json und .jsonc (JSON mit Kommentaren; Kommentare sind in beiden erlaubt).
        $paths = array_merge(
            glob(rtrim($this->directory, '/') . '/*.json') ?: [],
            glob(rtrim($this->directory, '/') . '/*.jsonc') ?: []
        );
        foreach ($paths as $path)
        {
            $file = basename($path);
            $json = json_decode(self::stripComments((string) file_get_contents($path)), true);
            if (!is_array($json))
            {
                $this->errors[] = $file . ': ' . json_last_error_msg();
                continue;
            }
            $buttons = [];
            foreach (is_array($json['buttons'] ?? null) ? $json['buttons'] : [] as $index => $button)
            {
                $parsed = is_array($button) ? $this->button($button) : null;
                if ($parsed === null)
                {
                    $this->errors[] = $file . ': Button ' . ((int) $index + 1) . ' braucht "label" und "command"';
                    continue;
                }
                $buttons[] = $parsed;
            }
            if ($buttons === [])
            {
                $this->errors[] = $file . ': keine Buttons';
                continue;
            }
            // "requires": Plugin-Dateien (".smx" darf fehlen); nur gültige Namen, sie landen in einem RCON-Befehl.
            $requires = [];
            foreach ($this->strings($json['requires'] ?? null) as $plugin)
            {
                $plugin = str_ends_with(strtolower($plugin), '.smx') ? $plugin : $plugin . '.smx';
                if (SourceModPlugins::isPluginFile($plugin))
                {
                    $requires[] = strtolower($plugin);
                }
                else
                {
                    $this->errors[] = $file . ': ungültiges Plugin in "requires": ' . $plugin;
                }
            }
            $groups[] = [
                'file' => $file,
                'requires' => array_values(array_unique($requires)),
                'games' => array_map('strtolower', $this->strings($json['games'] ?? null)),
                'order' => is_int($json['order'] ?? null) ? $json['order'] : 100,
                'open' => ($json['open'] ?? false) === true,
                'name' => $this->text($json['name'] ?? null) ?: preg_replace('/\.jsonc?$/', '', $file),
                'description' => $this->text($json['description'] ?? null),
                'buttons' => $buttons,
            ];
        }
        usort($groups, static fn (array $a, array $b): int => [$a['order'], $a['file']] <=> [$b['order'], $b['file']]);

        return array_map(static function (array $group): array {
            unset($group['order']);
            return $group;
        }, $groups);
    }

    /** @return list<string> */
    public function errors(): array
    {
        return $this->errors;
    }

    private function button(array $button): ?array
    {
        $label = $this->text($button['label'] ?? null);
        $command = trim(is_string($button['command'] ?? null) ? $button['command'] : '');
        if ($label === '' || $command === '')
        {
            return null;
        }
        $definitions = is_array($button['args'] ?? null) ? $button['args'] : [];
        $args = [];
        preg_match_all('/\{([A-Za-z0-9_]+)\}/', $command, $matches);
        foreach (array_unique($matches[1]) as $name)
        {
            $definition = is_array($definitions[$name] ?? null) ? $definitions[$name] : [];
            $options = is_array($definition['options'] ?? null) ? $definition['options'] : [];
            $args[] = [
                'name' => $name,
                'label' => $this->text($definition['label'] ?? null) ?: $name,
                'placeholder' => $this->text($definition['placeholder'] ?? null),
                'default' => is_scalar($definition['default'] ?? null) ? (string) $definition['default'] : '',
                'type' => ($definition['type'] ?? '') === 'number' ? 'number' : 'text',
                'options' => array_values(array_map('strval', array_filter($options, 'is_scalar'))),
                'quote' => ($definition['quote'] ?? false) === true,
                'required' => ($definition['required'] ?? true) !== false,
                'suggest' => in_array($definition['suggest'] ?? null, self::SUGGEST, true) ? $definition['suggest'] : '',
            ];
        }

        return [
            'label' => $label,
            'command' => $command,
            'description' => $this->text($button['description'] ?? null),
            'confirm' => $this->text($button['confirm'] ?? null),
            'underscore' => ($button['argmode'] ?? '') === 'underscore',
            'args' => $args,
        ];
    }

    /**
     * Entfernt Kommentare (// bis Zeilenende, /* ... *\/), damit die Dateien kommentiert werden können (wie JSONC).
     * Text in Anführungszeichen bleibt unverändert, z. B. "http://...".
     */
    public static function stripComments(string $json): string
    {
        $out = '';
        $length = strlen($json);
        for ($i = 0; $i < $length; $i++)
        {
            $char = $json[$i];
            if ($char === '"')
            {
                // Zeichenkette samt Escapes übernehmen.
                $end = $i + 1;
                while ($end < $length && $json[$end] !== '"')
                {
                    $end += $json[$end] === '\\' ? 2 : 1;
                }
                $out .= substr($json, $i, $end - $i + 1);
                $i = $end;
            }
            elseif ($char === '/' && ($json[$i + 1] ?? '') === '/')
            {
                $i = ($newline = strpos($json, "\n", $i)) === false ? $length : $newline - 1;
            }
            elseif ($char === '/' && ($json[$i + 1] ?? '') === '*')
            {
                $i = ($close = strpos($json, '*/', $i + 2)) === false ? $length : $close + 1;
            }
            else
            {
                $out .= $char;
            }
        }

        return $out;
    }

    /**
     * Text oder Liste von Texten als Liste (leere fallen weg).
     *
     * @return list<string>
     */
    private function strings(mixed $value): array
    {
        $values = is_string($value) ? [$value] : (is_array($value) ? $value : []);

        return array_values(array_filter(array_map(
            static fn (mixed $item): string => is_string($item) ? trim($item) : '',
            $values
        ), static fn (string $item): bool => $item !== ''));
    }

    /** Text oder {"en": ..., "de": ...}: die aktive Sprache, sonst Englisch, sonst der erste. */
    private function text(mixed $value): string
    {
        if (is_string($value))
        {
            return trim($value);
        }
        if (!is_array($value) || $value === [])
        {
            return '';
        }
        $text = $value[$this->language] ?? $value['en'] ?? reset($value);

        return is_string($text) ? trim($text) : '';
    }
}
