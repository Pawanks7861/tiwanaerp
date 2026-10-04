<?php

namespace App\Policies\Documents;

use App\Enums\Documents\DocumentStatus;
use App\Models\Documents\Document;
use App\Models\Projects\Project;
use App\Models\User;
use App\Policies\ProjectPolicy;

/**
 * documents.* permission AND project access. documents.delete covers archive / restore and
 * deleting a draft (soft delete); versions are never deleted.
 */
class DocumentPolicy
{
    public function __construct(private readonly ProjectPolicy $projects) {}

    public function viewAny(User $user, Project $project): bool
    {
        return $user->can('documents.view') && $this->projects->view($user, $project);
    }

    public function view(User $user, Document $document): bool
    {
        return $user->can('documents.view') && $this->projects->view($user, $document->project);
    }

    public function create(User $user, Project $project): bool
    {
        return $user->can('documents.upload') && $this->projects->view($user, $project);
    }

    public function update(User $user, Document $document): bool
    {
        return $user->can('documents.upload') && $document->isEditable() && $this->projects->view($user, $document->project);
    }

    public function addVersion(User $user, Document $document): bool
    {
        return $user->can('documents.upload') && $document->status->acceptsVersions()
            && $this->projects->view($user, $document->project);
    }

    public function publish(User $user, Document $document): bool
    {
        return $this->in($user, $document, 'documents.upload', DocumentStatus::Draft);
    }

    public function archive(User $user, Document $document): bool
    {
        return $this->in($user, $document, 'documents.delete', DocumentStatus::Active);
    }

    public function restore(User $user, Document $document): bool
    {
        return $this->in($user, $document, 'documents.delete', DocumentStatus::Archived);
    }

    public function delete(User $user, Document $document): bool
    {
        return $this->in($user, $document, 'documents.delete', DocumentStatus::Draft);
    }

    public function manageFolders(User $user, Project $project): bool
    {
        return $user->can('documents.manage_folders') && $this->projects->view($user, $project);
    }

    private function in(User $user, Document $document, string $permission, DocumentStatus $status): bool
    {
        return $user->can($permission) && $document->status === $status && $this->projects->view($user, $document->project);
    }
}
