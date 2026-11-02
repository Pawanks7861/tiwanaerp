<?php

namespace App\Services\Ai\Local;

use Illuminate\Support\Str;

class IntentResolver
{
    public function __construct(
        private readonly IntentRegistry $registry,
        private readonly QuestionNormalizer $normalizer,
    ) {}

    /**
     * @param  array<string, mixed>|null  $conversationContext
     * @return array{intent: ?IntentDefinition, score: int, candidates: list<array{id: string, score: int, label: string}>}
     */
    public function resolve(string $rawMessage, string $normalized, ?array $conversationContext = null): array
    {
        $tokens = $this->normalizer->tokens($normalized);
        $scored = [];

        foreach ($this->registry->all() as $intent) {
            $score = $this->score($intent, $rawMessage, $normalized, $tokens);
            if ($score > 0) {
                $scored[] = ['intent' => $intent, 'score' => $score];
            }
        }

        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);

        $min = (int) config('ai.intent_min_score', 8);
        $top = $scored[0] ?? null;

        // Follow-up: short filter-only message reuses last analytics intent.
        if (($conversationContext['follow_up_only'] ?? false) === true
            && filled($conversationContext['intent'] ?? null)
        ) {
            $prev = $this->registry->find((string) $conversationContext['intent']);
            if ($prev !== null && $prev->type === 'analytics') {
                return [
                    'intent' => $prev,
                    'score' => max($min, $top['score'] ?? $min),
                    'candidates' => $this->candidateList($scored),
                ];
            }
        }

        if ($top === null || $top['score'] < $min) {
            return [
                'intent' => null,
                'score' => $top['score'] ?? 0,
                'candidates' => $this->candidateList($scored),
            ];
        }

        // Ambiguous: two close scores
        if (isset($scored[1]) && ($top['score'] - $scored[1]['score']) < 3 && $scored[1]['score'] >= $min) {
            return [
                'intent' => null,
                'score' => $top['score'],
                'candidates' => $this->candidateList($scored),
            ];
        }

        return [
            'intent' => $top['intent'],
            'score' => $top['score'],
            'candidates' => $this->candidateList($scored),
        ];
    }

    /**
     * @param  list<string>  $tokens
     */
    private function score(IntentDefinition $intent, string $raw, string $normalized, array $tokens): int
    {
        $score = 0;
        $rawLower = Str::lower($raw);

        foreach ($intent->phrases as $phrase) {
            $p = Str::lower($phrase);
            if (str_contains($normalized, $p) || str_contains($rawLower, $p)) {
                $score += 12;
            }
        }

        foreach ($intent->patterns as $pattern) {
            if (@preg_match($pattern, $normalized) === 1 || @preg_match($pattern, $rawLower) === 1) {
                $score += 10;
            }
        }

        $keywordHits = 0;
        foreach ($intent->keywords as $keyword) {
            $k = Str::lower($keyword);
            if (in_array($k, $tokens, true) || str_contains($normalized, $k) || str_contains($rawLower, $k)) {
                $keywordHits++;
                $score += 3;
            }
        }

        if ($keywordHits >= 2) {
            $score += 4;
        }

        // Soft similarity: shared tokens vs label
        $labelTokens = $this->normalizer->tokens($this->normalizer->normalize($intent->label));
        $overlap = count(array_intersect($tokens, $labelTokens));
        $score += $overlap * 2;

        return $score;
    }

    /**
     * @param  list<array{intent: IntentDefinition, score: int}>  $scored
     * @return list<array{id: string, score: int, label: string}>
     */
    private function candidateList(array $scored): array
    {
        return array_map(
            fn ($row) => [
                'id' => $row['intent']->id,
                'score' => $row['score'],
                'label' => $row['intent']->label,
            ],
            array_slice($scored, 0, 5),
        );
    }
}
