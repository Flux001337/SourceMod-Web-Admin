<?php
declare(strict_types=1);

final class Template
{
    private array $globalData = [];

    public function __construct(private readonly string $basePath)
    {
    }

    public function render(string $file, array $data = []): string
    {
        $data = array_merge($this->globalData, $data);
        $path = rtrim($this->basePath, '/\\') . DIRECTORY_SEPARATOR . ltrim($file, '/\\');
        if (!is_file($path))
        {
            throw new RuntimeException("Template nicht gefunden: {$file}");
        }

        $content = file_get_contents($path);
        if ($content === false)
        {
            throw new RuntimeException("Template konnte nicht gelesen werden: {$file}");
        }

        $content = $this->renderLists($content, $data);
        $content = $this->renderConditionals($content, $data);

        // Triple braces = bewusst unescaped HTML, z.B. für gerenderte Partials.
        $content = preg_replace_callback('/\{\{\{\s*([a-zA-Z0-9_.-]+)\s*\}\}\}/', function (array $match) use ($data): string {
            return (string) $this->value($data, $match[1], '');
        }, $content) ?? $content;

        // Double braces = escaped values.
        $content = preg_replace_callback('/\{\{\s*([a-zA-Z0-9_.-]+)\s*\}\}/', function (array $match) use ($data): string {
            return $this->escape((string) $this->value($data, $match[1], ''));
        }, $content) ?? $content;

        return $this->injectCsrfToken($content, $data);
    }

    public function setGlobalData(array $data): void
    {
        $this->globalData = $data;
    }

    private function injectCsrfToken(string $content, array $data): string
    {
        $token = (string) ($data['csrf_token'] ?? '');
        if ($token === '')
        {
            return $content;
        }

        $input = '<input type="hidden" name="csrf_token" value="'
            . htmlspecialchars($token, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">';

        // Verschachtelte render()-Aufrufe (z. B. Unterseite -> Homepage-Seite -> Admin-Shell -> Layout)
        // laufen alle über diese Methode; ohne die Prüfung auf ein bereits vorhandenes Token würde
        // jede weitere Ebene erneut einen Token einfügen (mehrere identische Hidden-Inputs pro Formular).
        return preg_replace_callback(
            '/<form\b([^>]*)>(\s*<input\s+type="hidden"\s+name="csrf_token"[^>]*>)?/i',
            static function (array $match) use ($input): string {
                $alreadyHasToken = ($match[2] ?? '') !== '';
                if ($alreadyHasToken || !preg_match('/\bmethod\s*=\s*["\']?post\b/i', $match[1]))
                {
                    return $match[0];
                }
                return $match[0] . $input;
            },
            $content
        ) ?? $content;
    }

    private function renderConditionals(string $content, array $data): string
    {
        $pattern = '/\{\{\?([a-zA-Z0-9_.-]+)\}\}(.*?)\{\{\/\1\}\}/s';

        return preg_replace_callback($pattern, function (array $match) use ($data): string {
            if (!$this->value($data, $match[1], false))
            {
                return '';
            }

            // Auch Bedingungen innerhalb eines bedingten Blocks auswerten.
            return $this->renderConditionals($match[2], $data);
        }, $content) ?? $content;
    }

    private function renderLists(string $content, array $data): string
    {
        $pattern = '/\{\{#([a-zA-Z0-9_.-]+)\}\}(.*?)\{\{\/\1\}\}/s';

        return preg_replace_callback($pattern, function (array $match) use ($data): string {
            $items = $this->value($data, $match[1], []);
            if (!is_array($items))
            {
                return '';
            }

            $output = '';
            foreach ($items as $item)
            {
                $context = is_array($item) ? array_merge($data, $item) : array_merge($data, ['value' => $item]);
                $output .= $this->renderString($match[2], $context);
            }
            return $output;
        }, $content) ?? $content;
    }

    private function renderString(string $content, array $data): string
    {
        $content = $this->renderLists($content, $data);
        $content = $this->renderConditionals($content, $data);

        $content = preg_replace_callback('/\{\{\{\s*([a-zA-Z0-9_.-]+)\s*\}\}\}/', function (array $match) use ($data): string {
            return (string) $this->value($data, $match[1], '');
        }, $content) ?? $content;

        return preg_replace_callback('/\{\{\s*([a-zA-Z0-9_.-]+)\s*\}\}/', function (array $match) use ($data): string {
            return $this->escape((string) $this->value($data, $match[1], ''));
        }, $content) ?? $content;
    }

    /**
     * HTML-Escaping für Werte. Geschweifte Klammern werden zusätzlich als Entität
     * ausgegeben: Listen werden vor den äußeren Ersetzungen gerendert, der Text
     * durchläuft die Platzhalter-Auswertung also erneut. Ohne diese Kodierung könnte
     * Nutzertext wie "{{{ ticket.subject }}}" als Platzhalter gelesen werden und
     * fremde Werte ungeescaped ausgeben (Stored XSS). Im Browser sieht "&#123;" wie "{" aus.
     */
    private function escape(string $value): string
    {
        return strtr(
            htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            ['{' => '&#123;', '}' => '&#125;']
        );
    }

    private function value(array $data, string $path, mixed $default = null): mixed
    {
        $current = $data;
        foreach (explode('.', $path) as $part)
        {
            if (is_array($current) && array_key_exists($part, $current))
            {
                $current = $current[$part];
            }
            else
            {
                return $default;
            }
        }
        return $current;
    }
}
