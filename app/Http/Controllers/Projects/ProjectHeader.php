<?php

namespace App\Http\Controllers\Projects;

use App\Models\Projects\Project;

/**
 * Minimal project data for the persistent ProjectLayout header.
 */
final class ProjectHeader
{
    /**
     * @return array<string, mixed>
     */
    public static function for(Project $project): array
    {
        return [
            'id' => $project->id,
            'project_number' => $project->project_number,
            'code' => $project->code,
            'name' => $project->name,
            'city' => $project->city,
            'status' => $project->status->value,
            'status_label' => $project->status->label(),
        ];
    }
}
