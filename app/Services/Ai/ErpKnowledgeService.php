<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class ErpKnowledgeService
{
    /**
     * @return list<array{id: string, title: string, body: string}>
     */
    public function all(): array
    {
        $path = (string) config('ai.knowledge_path');
        if (! File::isDirectory($path)) {
            return [];
        }

        $entries = [];
        foreach (File::files($path) as $file) {
            if ($file->getExtension() !== 'md') {
                continue;
            }
            $body = File::get($file->getPathname());
            $entries[] = [
                'id' => $file->getFilenameWithoutExtension(),
                'title' => Str::headline($file->getFilenameWithoutExtension()),
                'body' => $body,
            ];
        }

        return $entries;
    }

    public function retrieve(string $query, int $limit = 3): string
    {
        $query = Str::lower($query);
        $scored = [];

        foreach ($this->all() as $entry) {
            $hay = Str::lower($entry['title'].' '.$entry['body']);
            $score = 0;
            foreach (preg_split('/\W+/', $query) ?: [] as $word) {
                if (strlen($word) < 3) {
                    continue;
                }
                if (str_contains($hay, $word)) {
                    $score += substr_count($hay, $word);
                }
            }
            // Boost common modules
            foreach (['boq', 'drawing', 'purchase', 'po', 'grn', 'inventory', 'expense', 'import'] as $boost) {
                if (str_contains($query, $boost) && str_contains($hay, $boost)) {
                    $score += 5;
                }
            }
            if ($score > 0) {
                $scored[] = ['score' => $score, 'entry' => $entry];
            }
        }

        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);
        $picked = array_slice($scored, 0, $limit);

        if ($picked === []) {
            $general = collect($this->all())->firstWhere('id', 'general');

            return $general['body'] ?? '';
        }

        return implode("\n\n---\n\n", array_map(
            fn ($row) => "# {$row['entry']['title']}\n{$row['entry']['body']}",
            $picked,
        ));
    }
}
