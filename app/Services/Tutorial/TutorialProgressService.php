<?php

namespace App\Services\Tutorial;

use App\Models\Tutorial\UserTutorialProgress;
use App\Models\User;
use App\Support\Permissions\DefaultRoles;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TutorialProgressService
{
    public function __construct(private readonly TutorialStepRegistry $registry) {}

    public function tutorialKey(): string
    {
        return (string) config('tutorial.key', 'tiwana-erp-onboarding');
    }

    public function tutorialVersion(): string
    {
        return (string) config('tutorial.version', 'v1');
    }

    public function isExempt(User $user): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        $exempt = config('tutorial.exempt_roles', [
            DefaultRoles::COMPANY_ADMIN,
            DefaultRoles::DIRECTOR,
        ]);

        foreach ($exempt as $role) {
            if ($user->hasRole($role)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Payload shared with the frontend on every authenticated page.
     *
     * @return array<string, mixed>
     */
    public function inertiaPayload(User $user): array
    {
        if (! config('tutorial.enabled', true)) {
            return ['show' => false, 'exempt' => false, 'completed' => true];
        }

        if ($this->isExempt($user)) {
            $this->ensureExemptRecord($user);

            return [
                'show' => false,
                'exempt' => true,
                'completed' => true,
                'tutorial_key' => $this->tutorialKey(),
                'tutorial_version' => $this->tutorialVersion(),
            ];
        }

        $progress = $this->progressFor($user);
        $projectId = $this->registry->sampleProjectId($user);
        $steps = $this->registry->stepsFor($user, $projectId);
        $required = $this->registry->requiredStepKeys($user, $projectId);
        $completedKeys = $progress->completedStepKeys();

        if ($progress->isCompleted()) {
            return [
                'show' => false,
                'exempt' => false,
                'completed' => true,
                'tutorial_key' => $progress->tutorial_key,
                'tutorial_version' => $progress->tutorial_version,
                'current_step' => null,
                'completed_steps' => $completedKeys,
                'required_steps' => $required,
                'steps' => $steps,
                'progress' => [
                    'done' => count($required),
                    'total' => count($required),
                ],
            ];
        }

        $current = $progress->current_step;
        if ($current === null || ! collect($steps)->contains(fn ($s) => $s['key'] === $current)) {
            $current = $steps[0]['key'] ?? 'welcome';
            if ($progress->status === 'not_started' || $progress->current_step !== $current) {
                $progress->forceFill([
                    'current_step' => $current,
                    'status' => $progress->status === 'not_started' ? 'in_progress' : $progress->status,
                    'started_at' => $progress->started_at ?? now(),
                    'last_seen_at' => now(),
                ])->save();
            }
        } else {
            $progress->forceFill(['last_seen_at' => now()])->save();
        }

        return [
            'show' => true,
            'exempt' => false,
            'completed' => false,
            'tutorial_key' => $progress->tutorial_key,
            'tutorial_version' => $progress->tutorial_version,
            'current_step' => $progress->current_step,
            'completed_steps' => $completedKeys,
            'required_steps' => $required,
            'steps' => $steps,
            'sample_project_id' => $projectId,
            'progress' => [
                'done' => count(array_intersect($completedKeys, $required)),
                'total' => count($required),
            ],
        ];
    }

    public function progressFor(User $user): UserTutorialProgress
    {
        return UserTutorialProgress::query()->firstOrCreate(
            [
                'user_id' => $user->id,
                'tutorial_key' => $this->tutorialKey(),
                'tutorial_version' => $this->tutorialVersion(),
            ],
            [
                'status' => 'not_started',
                'completed_steps' => [],
                'current_step' => 'welcome',
            ],
        );
    }

    /**
     * Acknowledge a step and advance. Closing the UI must not call this for completion.
     *
     * @return array<string, mixed>
     */
    public function acknowledge(User $user, string $stepKey): array
    {
        if ($this->isExempt($user)) {
            throw ValidationException::withMessages(['tutorial' => 'This tutorial does not apply to your role.']);
        }

        $progress = $this->progressFor($user);
        if ($progress->isCompleted()) {
            return $this->inertiaPayload($user);
        }

        $projectId = $this->registry->sampleProjectId($user);
        $steps = $this->registry->stepsFor($user, $projectId);
        $keys = array_column($steps, 'key');

        if (! in_array($stepKey, $keys, true)) {
            throw ValidationException::withMessages(['step' => 'That tutorial step is not available for your account.']);
        }

        // Must acknowledge the current step (or a previously completed one when going back).
        $completed = $progress->completedStepKeys();
        if ($stepKey !== $progress->current_step && ! in_array($stepKey, $completed, true)) {
            throw ValidationException::withMessages(['step' => 'Complete the current tutorial step before continuing.']);
        }

        return DB::transaction(function () use ($user, $progress, $stepKey, $steps, $keys) {
            $locked = UserTutorialProgress::query()->whereKey($progress->id)->lockForUpdate()->firstOrFail();
            if ($locked->isCompleted()) {
                return $this->inertiaPayload($user);
            }

            $completed = $locked->completedStepKeys();
            if ($stepKey !== 'complete' && ! in_array($stepKey, $completed, true)) {
                $completed[] = $stepKey;
            }

            $index = array_search($stepKey, $keys, true);
            $next = ($index !== false && isset($keys[$index + 1])) ? $keys[$index + 1] : $stepKey;

            $locked->forceFill([
                'completed_steps' => array_values($completed),
                'current_step' => $next,
                'status' => 'in_progress',
                'started_at' => $locked->started_at ?? now(),
                'last_seen_at' => now(),
            ])->save();

            return $this->inertiaPayload($user);
        });
    }

    /**
     * Move current_step backward without removing completed steps.
     *
     * @return array<string, mixed>
     */
    public function goTo(User $user, string $stepKey): array
    {
        if ($this->isExempt($user)) {
            throw ValidationException::withMessages(['tutorial' => 'This tutorial does not apply to your role.']);
        }

        $progress = $this->progressFor($user);
        if ($progress->isCompleted()) {
            return $this->inertiaPayload($user);
        }

        $projectId = $this->registry->sampleProjectId($user);
        $steps = $this->registry->stepsFor($user, $projectId);
        $keys = array_column($steps, 'key');
        $completed = $progress->completedStepKeys();

        if (! in_array($stepKey, $keys, true)) {
            throw ValidationException::withMessages(['step' => 'Invalid tutorial step.']);
        }

        // May revisit any completed step or the current step.
        $index = array_search($stepKey, $keys, true);
        $currentIndex = array_search($progress->current_step, $keys, true);
        $allowed = in_array($stepKey, $completed, true)
            || $stepKey === $progress->current_step
            || ($currentIndex !== false && $index !== false && $index <= $currentIndex);

        if (! $allowed) {
            throw ValidationException::withMessages(['step' => 'You cannot jump ahead in the tutorial.']);
        }

        $progress->forceFill([
            'current_step' => $stepKey,
            'last_seen_at' => now(),
            'status' => 'in_progress',
            'started_at' => $progress->started_at ?? now(),
        ])->save();

        return $this->inertiaPayload($user);
    }

    /**
     * @return array<string, mixed>
     */
    public function complete(User $user): array
    {
        if ($this->isExempt($user)) {
            throw ValidationException::withMessages(['tutorial' => 'This tutorial does not apply to your role.']);
        }

        $progress = $this->progressFor($user);
        if ($progress->isCompleted()) {
            return $this->inertiaPayload($user);
        }

        $projectId = $this->registry->sampleProjectId($user);
        $required = $this->registry->requiredStepKeys($user, $projectId);
        $completed = $progress->completedStepKeys();
        $missing = array_values(array_diff($required, $completed));

        if ($missing !== []) {
            throw ValidationException::withMessages([
                'tutorial' => 'Finish every required tutorial step before completing. Missing: '.implode(', ', $missing),
            ]);
        }

        // Must be on the completion step (or already past all required).
        $steps = $this->registry->stepsFor($user, $projectId);
        $lastRequired = $required !== [] ? $required[array_key_last($required)] : null;
        if ($progress->current_step !== 'complete' && $lastRequired !== null && ! in_array($lastRequired, $completed, true)) {
            throw ValidationException::withMessages(['tutorial' => 'Reach the final tutorial step before finishing.']);
        }

        $progress->forceFill([
            'status' => 'completed',
            'completed_at' => now(),
            'current_step' => 'complete',
            'completed_steps' => array_values(array_unique([...$completed, 'complete'])),
            'last_seen_at' => now(),
        ])->save();

        return $this->inertiaPayload($user);
    }

    /**
     * Touch last_seen without completing — used when the user temporarily closes the UI.
     */
    public function touch(User $user): void
    {
        if ($this->isExempt($user) || ! config('tutorial.enabled', true)) {
            return;
        }

        $progress = $this->progressFor($user);
        if ($progress->isCompleted()) {
            return;
        }

        $progress->forceFill([
            'last_seen_at' => now(),
            'status' => $progress->status === 'not_started' ? 'in_progress' : $progress->status,
            'started_at' => $progress->started_at ?? now(),
        ])->save();
    }

    private function ensureExemptRecord(User $user): void
    {
        UserTutorialProgress::query()->firstOrCreate(
            [
                'user_id' => $user->id,
                'tutorial_key' => $this->tutorialKey(),
                'tutorial_version' => $this->tutorialVersion(),
            ],
            [
                'status' => 'exempt',
                'completed_steps' => [],
                'current_step' => null,
                'completed_at' => null,
            ],
        );
    }
}
