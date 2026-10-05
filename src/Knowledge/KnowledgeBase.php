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

    /** @return list<array{title: string, body: string}> */
    public function search(string $query, int $limit = 3): array
    {
        $terms = self::terms($query);
        if ($terms === []) {
            return [];
        }

        $scored = [];
        foreach ($this->sections as $section) {
            $titleTerms = self::terms($section['title']);
            $bodyTerms = self::terms($section['body']);
            $score = 0;
            foreach ($terms as $term) {
                $score += 3 * count(array_keys($titleTerms, $term, true));
                $score += count(array_keys($bodyTerms, $term, true));
            }
            if ($score > 0) {
                $scored[] = [$score, ['title' => $section['title'], 'body' => $section['body']]];
            }
        }
        usort($scored, static fn ($a, $b) => $b[0] <=> $a[0]);

        return array_map(static fn ($s) => $s[1], array_slice($scored, 0, $limit));
    }

    /** @return list<string> */
    private static function terms(string $text): array
    {
        static $stop = ['the', 'a', 'an', 'and', 'or', 'of', 'to', 'is', 'are', 'my', 'i', 'you', 'your',
            'how', 'what', 'when', 'do', 'does', 'can', 'for', 'in', 'on', 'it', 'with', 'be', 'me', 'we'];
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
