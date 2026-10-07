<?php
declare(strict_types=1);

/**
 * Seitenaufteilung für Listen: berechnet Offset und die Seitenlinks.
 */
final class Pager
{
    public readonly int $page;
    public readonly int $pages;
    public readonly int $offset;

    public function __construct(public readonly int $total, public readonly int $perPage, int $requestedPage)
    {
        $this->pages = max(1, (int) ceil($total / max(1, $perPage)));
        $this->page = max(1, min($this->pages, $requestedPage));
        $this->offset = ($this->page - 1) * $perPage;
    }

    /**
     * Daten für das Template mit der Basis-URL (ohne "page"). Wie die Ban-Liste der Keks-Brigarde-Homepage: Zurück,
     * erste und letzte Seite, zwei Seiten um die aktuelle, Lücken als "…", Weiter.
     *
     * @return array{has_pages: bool, links: list<array{is_gap: bool, is_page: bool, number: int, url: string, current: bool, is_other: bool}>,
     *     has_previous: bool, previous_url: string, has_next: bool, next_url: string, from: int, to: int, total: int}
     */
    public function view(string $baseUrl): array
    {
        $url = static fn (int $number): string => $baseUrl . '&page=' . $number;
        $links = [];
        $previous = 0;
        for ($number = 1; $number <= $this->pages; $number++)
        {
            if ($number !== 1 && $number !== $this->pages && abs($number - $this->page) > 2)
            {
                continue;
            }
            if ($number - $previous > 1)
            {
                $links[] = ['is_gap' => true, 'is_page' => false, 'number' => 0, 'url' => '', 'current' => false, 'is_other' => false];
            }
            $current = $number === $this->page;
            $links[] = ['is_gap' => false, 'is_page' => true, 'number' => $number, 'url' => $url($number), 'current' => $current, 'is_other' => !$current];
            $previous = $number;
        }

        return [
            'has_pages' => $this->pages > 1,
            'links' => $links,
            'has_previous' => $this->page > 1,
            'previous_url' => $url(max(1, $this->page - 1)),
            'has_next' => $this->page < $this->pages,
            'next_url' => $url(min($this->pages, $this->page + 1)),
            'from' => $this->total === 0 ? 0 : $this->offset + 1,
            'to' => min($this->total, $this->offset + $this->perPage),
            'total' => $this->total,
        ];
    }
}
