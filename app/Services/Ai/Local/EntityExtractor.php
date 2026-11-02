<?php

namespace App\Services\Ai\Local;

use App\Models\Masters\Vendor;
use App\Models\Projects\Project;
use App\Models\User;
use Illuminate\Support\Str;

class EntityExtractor
{
    /**
     * @param  array<string, mixed>  $pageContext
     * @return array{project_id?: int, vendor_id?: int, group_by?: string, follow_up_only?: bool}
     */
    public function extract(User $user, string $raw, string $normalized, array $pageContext = []): array
    {
        $out = [];

        if (preg_match('/\bgroup_vendor\b|\bby vendor\b|\bvendor.?wise\b/u', $normalized)) {
            $out['group_by'] = 'vendor';
        } elseif (preg_match('/\bgroup_project\b|\bby project\b|\bproject.?wise\b/u', $normalized)) {
            $out['group_by'] = 'project';
        }

        $project = $this->matchProject($user, $raw, $normalized);
        if ($project !== null) {
            $out['project_id'] = $project->id;
        } elseif (! empty($pageContext['project_id']) && $this->refersToCurrentProject($normalized)) {
            $out['project_id'] = (int) $pageContext['project_id'];
        }

        $vendor = $this->matchVendor($raw, $normalized);
        if ($vendor !== null) {
            $out['vendor_id'] = $vendor->id;
        }

        $out['follow_up_only'] = $this->isFollowUpOnly($normalized, $out);

        return $out;
    }

    private function refersToCurrentProject(string $normalized): bool
    {
        return (bool) preg_match('/\b(this project|current project|is project|iss project|is project ka)\b/u', $normalized);
    }

    /**
     * @param  array<string, mixed>  $entities
     */
    private function isFollowUpOnly(string $normalized, array $entities): bool
    {
        $tokens = preg_split('/\s+/', $normalized) ?: [];
        $substantive = array_diff($tokens, [
            'only', 'for', 'filter_project', 'project', 'vendor', 'last_month', 'this_month', 'today',
            'followup', 'what', 'about', 'and', 'the', 'of', 'show', 'group_vendor', 'group_project',
            'sirf', 'ka', 'ke', 'ki',
        ]);

        if (isset($entities['project_id']) || isset($entities['vendor_id']) || isset($entities['group_by'])) {
            return count($substantive) <= 3;
        }

        return (bool) preg_match('/^(last_month|this_month|today|followup( last_month| this_month)?)$/u', trim($normalized));
    }

    private function matchProject(User $user, string $raw, string $normalized): ?Project
    {
        $projects = Project::query()->visibleTo($user)->get(['id', 'code', 'name', 'project_number']);
        $best = null;
        $bestLen = 0;

        foreach ($projects as $project) {
            foreach ([$project->code, $project->name, $project->project_number] as $label) {
                if (! filled($label)) {
                    continue;
                }
                $needle = Str::lower((string) $label);
                if (Str::length($needle) < 2) {
                    continue;
                }
                if (str_contains(Str::lower($raw), $needle) || str_contains($normalized, $needle)) {
                    if (Str::length($needle) > $bestLen) {
                        $best = $project;
                        $bestLen = Str::length($needle);
                    }
                }
            }
        }

        return $best;
    }

    private function matchVendor(string $raw, string $normalized): ?Vendor
    {
        $vendors = Vendor::query()->limit(300)->get(['id', 'code', 'name']);
        $best = null;
        $bestLen = 0;

        foreach ($vendors as $vendor) {
            foreach ([$vendor->code, $vendor->name] as $label) {
                if (! filled($label)) {
                    continue;
                }
                $needle = Str::lower((string) $label);
                if (Str::length($needle) < 3) {
                    continue;
                }
                if (str_contains(Str::lower($raw), $needle) || str_contains($normalized, $needle)) {
                    if (Str::length($needle) > $bestLen) {
                        $best = $vendor;
                        $bestLen = Str::length($needle);
                    }
                }
            }
        }

        return $best;
    }
}
