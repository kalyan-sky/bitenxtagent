<?php

declare(strict_types=1);

namespace Bitenxt\SupportAgent\Knowledge;

/**
 * Help articles the bot may quote: Markdown files in knowledge/, split into
 * sections on "## " headings and ranked by simple keyword overlap. Only put
 * customer-facing content in that folder - anything there can be shown.
 */
final class KnowledgeBase
{
    /** @var list<array{title: string, body: string, source: string}> */
    private array $sections = [];

    public function __construct(string $directory)
    {
        foreach (glob(rtrim($directory, '/') . '/*.md') ?: [] as $file) {
            $source = basename($file, '.md');
            $parts = preg_split('/^##\s+/m', (string) file_get_contents($file)) ?: [];
            foreach ($parts as $i => $part) {
                $part = trim($part);
                if ($part === '' || ($i === 0 && str_starts_with($part, '# '))) {
                    continue; // skip the page title block
                }
                [$title, $body] = array_pad(explode("\n", $part, 2), 2, '');
                $this->sections[] = ['title' => trim($title), 'body' => trim($body), 'source' => $source];
            }
        }
    }

    /** Words customers use for the same thing; a query word also matches the others in its group. */
    private const SYNONYMS = [
        ['coupon', 'discount', 'promo', 'voucher', 'offer', 'promotion'],
        ['scan', 'stl', 'ply', 'intraoral', 'impression', 'file'],
        ['upload', 'attach', 'send', 'submit'],
        ['doctor', 'dentist', 'dr', 'clinician'],
        ['patient', 'case'],
        ['reorder', 'repeat', 'again', 'duplicate'],
        ['account', 'profile', 'login', 'signin', 'password', 'register', 'signup'],
        ['cart', 'basket', 'checkout'],
        ['track', 'tracking', 'status', 'where', 'shipped', 'delivery'],
        ['note', 'comment', 'instruction', 'message', 'follow'],
        ['appointment', 'meeting', 'call', 'consultation', 'demo'],
        ['product', 'service', 'catalog', 'item'],
        ['cancel', 'change', 'modify', 'edit'],
    ];

    /** @return list<array{title: string, body: string}> */
    public function search(string $query, int $limit = 3): array
    {
        $terms = self::terms($query);
        if ($terms === []) {
            return [];
        }
        // Synonyms help, but count for less than the customer's own words.
        $weights = array_fill_keys(self::expand($terms), 0.5);
        foreach ($terms as $term) {
            $weights[$term] = 1.0;
        }

        $scored = [];
        foreach ($this->sections as $section) {
            $titleTerms = array_count_values(self::terms($section['title']));
            $bodyTerms = array_count_values(self::terms($section['body']));
            $score = 0.0;
            $matched = 0;
            foreach ($weights as $term => $weight) {
                $inTitle = isset($titleTerms[$term]);
                $inBody = min($bodyTerms[$term] ?? 0, 3);
                if ($inTitle || $inBody > 0) {
                    // A match in the heading counts most; repeating a word in a long body counts little.
                    $score += $weight * (($inTitle ? 4 : 0) + $inBody);
                    $matched += $weight === 1.0 ? 1 : 0;
                }
            }
            if ($score > 0) {
                // Sections that cover more of the question's own words come first.
                $scored[] = [$score + 3 * $matched, ['title' => $section['title'], 'body' => $section['body']]];
            }
        }
        usort($scored, static fn ($a, $b) => $b[0] <=> $a[0]);

        return array_map(static fn ($s) => $s[1], array_slice($scored, 0, $limit));
    }

    /**
     * The best-matching section with how sure the match is: the share of the
     * question's own words (not synonyms) found in it, and whether any of
     * them is in its heading. Used to answer clear how-to questions directly.
     *
     * @return array{title: string, body: string, coverage: float, title_hit: bool}|null
     */
    public function bestMatch(string $query): ?array
    {
        $best = $this->search($query, 1)[0] ?? null;
        $terms = array_values(array_unique(self::terms($query)));
        if ($best === null || $terms === []) {
            return null;
        }
        $titleTerms = self::terms($best['title']);
        $allTerms = array_merge($titleTerms, self::terms($best['body']));
        $matched = count(array_intersect($terms, $allTerms));

        return $best + [
            'coverage' => $matched / count($terms),
            'title_hit' => array_intersect($terms, $titleTerms) !== [],
        ];
    }

    /**
     * @param list<string> $terms
     * @return list<string>
     */
    private static function expand(array $terms): array
    {
        static $groups = null;
        $groups ??= array_map(static fn (array $g) => array_map([self::class, 'stem'], $g), self::SYNONYMS);
        $expanded = $terms;
        foreach ($groups as $group) {
            if (array_intersect($terms, $group) !== []) {
                $expanded = array_merge($expanded, $group);
            }
        }

        return array_values(array_unique($expanded));
    }

    /** @return list<string> */
    private static function terms(string $text): array
    {
        static $stop = ['the', 'a', 'an', 'and', 'or', 'of', 'to', 'is', 'are', 'my', 'i', 'you', 'your',
            'how', 'what', 'when', 'do', 'does', 'can', 'for', 'in', 'on', 'it', 'with', 'be', 'me', 'we',
            'have', 'has', 'any', 'there', 'this', 'that', 'which', 'about', 'get', 'need', 'want', 'please', 'tell',
            'should', 'could', 'would', 'will', 'am', 'was', 'from', 'at', 'by', 'if', 'so', 'our', 'us', 'all'];
        preg_match_all('/[\p{L}\p{N}]+/u', mb_strtolower($text), $m);

        return array_values(array_filter(
            array_map([self::class, 'stem'], array_filter($m[0], static fn ($w) => !in_array($w, $stop, true))),
            static fn ($w) => mb_strlen($w) > 1,
        ));
    }

    /** Crude English stemmer so "placing", "placed", "places" and "place" all match. */
    private static function stem(string $word): string
    {
        foreach (['ing' => 6, 'ed' => 5, 'ies' => 5, 'es' => 5, 's' => 4] as $suffix => $minLength) {
            if (mb_strlen($word) >= $minLength && str_ends_with($word, $suffix)) {
                $word = mb_substr($word, 0, -mb_strlen($suffix)) . ($suffix === 'ies' ? 'y' : '');
                break;
            }
        }
        if (mb_strlen($word) > 3 && str_ends_with($word, 'e')) {
            $word = mb_substr($word, 0, -1);
        }
        // "shipp" (from "shipping") -> "ship"
        if (preg_match('/([b-df-hj-np-tv-z])\1$/u', $word)) {
            $word = mb_substr($word, 0, -1);
        }

        return $word;
    }
}
