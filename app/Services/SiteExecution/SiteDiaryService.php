<?php

namespace App\Services\SiteExecution;

use App\Enums\SiteExecution\SiteDiaryStatus;
use App\Models\Projects\Project;
use App\Models\Projects\Site;
use App\Models\SiteExecution\SiteDiary;
use App\Models\SiteExecution\SiteDiaryEquipment;
use App\Models\SiteExecution\SiteDiaryLabour;
use App\Models\SiteExecution\SiteDiaryMaterial;
use App\Models\SiteExecution\SiteDiaryWorkItem;
use App\Models\User;
use App\Services\SiteExecution\Concerns\ResolvesWorkLines;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Site diaries: draft → submitted → reviewed → approved, or rejected back to an editable state.
 * The diary is a record only: it never writes progress, stock or cost. Status changes happen
 * here and nowhere else; the person who submitted a diary cannot review, approve or reject it.
 */
class SiteDiaryService
{
    use ResolvesWorkLines;

    public const MAX_LINES = 100;

    /**
     * Idempotent on the client uuid: a retried create returns the diary saved the first time.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(Project $project, array $data): SiteDiary
    {
        $uuid = $data['uuid'] ?? null;
        if ($uuid !== null && ($existing = $this->byUuid($project, $uuid))) {
            return $existing;
        }

        return DB::transaction(function () use ($project, $data, $uuid) {
            $diary = new SiteDiary($this->header($project, $data));
            $diary->forceFill([
                'uuid' => $uuid ?? (string) Str::uuid(),
                'project_id' => $project->id,
                'status' => SiteDiaryStatus::Draft,
            ])->save();

            $this->replaceLines($diary, $project, $data);

            return $diary;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(SiteDiary $diary, array $data): SiteDiary
    {
        return DB::transaction(function () use ($diary, $data) {
            $locked = SiteDiary::query()->whereKey($diary->id)->lockForUpdate()->firstOrFail();
            $locked->assertEditable();
            $project = Project::query()->findOrFail($locked->project_id);

            $locked->fill($this->header($project, $data))->save();
            $this->replaceLines($locked, $project, $data);
            $diary->setRawAttributes($locked->getAttributes(), true);

            return $locked;
        });
    }

    public function delete(SiteDiary $diary): void
    {
        $diary->assertEditable();
        $diary->delete();
    }

    public function submit(SiteDiary $diary, User $user): void
    {
        $this->transition($diary, function (SiteDiary $locked) use ($user) {
            $locked->assertEditable();

            if (blank($locked->work_performed) && ! $locked->workItems()->exists()) {
                throw ValidationException::withMessages(['diary' => 'Describe the work performed or add at least one work item before submitting.']);
            }

            return [
                'status' => SiteDiaryStatus::Submitted,
                'submitted_by' => $user->id,
                'submitted_at' => now(),
                'reviewed_by' => null,
                'reviewed_at' => null,
                'rejected_by' => null,
                'rejected_at' => null,
                'rejection_reason' => null,
            ];
        });
    }

    public function review(SiteDiary $diary, User $user): void
    {
        $this->transition($diary, function (SiteDiary $locked) use ($user) {
            $this->assertStatus($locked, [SiteDiaryStatus::Submitted], 'Only a submitted diary can be reviewed.');
            $this->assertNotSubmitter($locked, $user, 'review');

            return ['status' => SiteDiaryStatus::Reviewed, 'reviewed_by' => $user->id, 'reviewed_at' => now()];
        });
    }

    public function approve(SiteDiary $diary, User $user): void
    {
        $this->transition($diary, function (SiteDiary $locked) use ($user) {
            $this->assertStatus($locked, [SiteDiaryStatus::Reviewed], 'Only a reviewed diary can be approved.');
            $this->assertNotSubmitter($locked, $user, 'approve');

            return ['status' => SiteDiaryStatus::Approved, 'approved_by' => $user->id, 'approved_at' => now()];
        });
    }

    public function reject(SiteDiary $diary, User $user, string $reason): void
    {
        $this->transition($diary, function (SiteDiary $locked) use ($user, $reason) {
            $this->assertStatus($locked, [SiteDiaryStatus::Submitted, SiteDiaryStatus::Reviewed], 'Only a submitted or reviewed diary can be rejected.');
            $this->assertNotSubmitter($locked, $user, 'reject');

            return [
                'status' => SiteDiaryStatus::Rejected,
                'rejected_by' => $user->id,
                'rejected_at' => now(),
                'rejection_reason' => $reason,
            ];
        });
    }

    /**
     * @param  callable(SiteDiary): array<string, mixed>  $change
     */
    private function transition(SiteDiary $diary, callable $change): void
    {
        DB::transaction(function () use ($diary, $change) {
            $locked = SiteDiary::query()->whereKey($diary->id)->lockForUpdate()->firstOrFail();
            $locked->forceFill($change($locked))->save();
            $diary->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * @param  list<SiteDiaryStatus>  $allowed
     */
    private function assertStatus(SiteDiary $diary, array $allowed, string $message): void
    {
        if (! in_array($diary->status, $allowed, true)) {
            throw ValidationException::withMessages(['diary' => $message]);
        }
    }

    private function assertNotSubmitter(SiteDiary $diary, User $user, string $action): void
    {
        if ((int) ($diary->submitted_by ?? $diary->created_by) === (int) $user->id) {
            throw ValidationException::withMessages(['diary' => "You cannot {$action} a diary you submitted yourself."]);
        }
    }

    private function byUuid(Project $project, string $uuid): ?SiteDiary
    {
        $existing = SiteDiary::query()->withoutGlobalScopes()->where('uuid', $uuid)->first();
        if ($existing === null) {
            return null;
        }

        $mine = SiteDiary::query()->whereKey($existing->id)->where('project_id', $project->id)->first();

        return $mine ?? throw ValidationException::withMessages(['uuid' => 'This diary reference is already in use.']);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function header(Project $project, array $data): array
    {
        $siteId = $data['site_id'] ?? null;
        if (! blank($siteId)) {
            $site = Site::query()->where('project_id', $project->id)->where('is_active', true)->whereKey((int) $siteId)->first();
            if ($site === null) {
                throw ValidationException::withMessages(['site_id' => 'Choose an active site of this project.']);
            }
        }

        return [
            'site_id' => blank($siteId) ? null : (int) $siteId,
            'diary_date' => $data['diary_date'],
            'weather' => $data['weather'] ?? null,
            'temperature' => $data['temperature'] ?? null,
            'work_location' => $data['work_location'] ?? null,
            'work_performed' => $data['work_performed'] ?? null,
            'issues' => $data['issues'] ?? null,
            'safety_incidents' => $data['safety_incidents'] ?? null,
            'remarks' => $data['remarks'] ?? null,
            'latitude' => $data['latitude'] ?? null,
            'longitude' => $data['longitude'] ?? null,
            'captured_at' => $data['captured_at'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $data  work_items, labours, equipment, materials
     */
    private function replaceLines(SiteDiary $diary, Project $project, array $data): void
    {
        $workItems = array_values($data['work_items'] ?? []);
        $labours = array_values($data['labours'] ?? []);
        $equipment = array_values($data['equipment'] ?? []);
        $materials = array_values($data['materials'] ?? []);

        foreach (['work_items' => $workItems, 'labours' => $labours, 'equipment' => $equipment, 'materials' => $materials] as $key => $rows) {
            if (count($rows) > self::MAX_LINES) {
                throw ValidationException::withMessages([$key => 'At most '.self::MAX_LINES.' lines.']);
            }
        }

        SiteDiaryWorkItem::query()->where('site_diary_id', $diary->id)->get()->each->delete();
        SiteDiaryLabour::query()->where('site_diary_id', $diary->id)->get()->each->delete();
        SiteDiaryEquipment::query()->where('site_diary_id', $diary->id)->get()->each->delete();
        SiteDiaryMaterial::query()->where('site_diary_id', $diary->id)->get()->each->delete();

        foreach ($workItems as $i => $row) {
            $target = $this->workTarget($project, $row, "work_items.{$i}");
            (new SiteDiaryWorkItem)->forceFill([
                'site_diary_id' => $diary->id,
                'task_id' => $target['task']?->id,
                'boq_item_id' => $target['boq']?->id,
                'subcontractor_id' => $this->subcontractor($row['subcontractor_id'] ?? null, "work_items.{$i}.subcontractor_id")?->id,
                'description' => $row['description'] ?? null,
                'quantity' => $this->quantity($row['quantity'] ?? null, "work_items.{$i}.quantity")->toQuantity(),
                'unit_id' => $target['unit']->id,
            ])->save();
        }

        foreach ($labours as $i => $row) {
            (new SiteDiaryLabour)->forceFill([
                'site_diary_id' => $diary->id,
                'labour_trade_id' => $this->labourTrade($row['labour_trade_id'] ?? null, "labours.{$i}.labour_trade_id")->id,
                'subcontractor_id' => $this->subcontractor($row['subcontractor_id'] ?? null, "labours.{$i}.subcontractor_id")?->id,
                'headcount' => $this->headcount($row['headcount'] ?? null, "labours.{$i}.headcount"),
                'hours' => $this->hours($row['hours'] ?? null, "labours.{$i}.hours"),
                'remarks' => $row['remarks'] ?? null,
            ])->save();
        }

        foreach ($equipment as $i => $row) {
            $type = $this->equipmentType($row['equipment_type_id'] ?? null, "equipment.{$i}.equipment_type_id");
            if ($type === null && blank($row['description'] ?? null)) {
                throw ValidationException::withMessages(["equipment.{$i}.equipment_type_id" => 'Choose the equipment type or describe the equipment.']);
            }
            (new SiteDiaryEquipment)->forceFill([
                'site_diary_id' => $diary->id,
                'equipment_type_id' => $type?->id,
                'description' => $row['description'] ?? null,
                'working_hours' => $this->hours($row['working_hours'] ?? null, "equipment.{$i}.working_hours"),
                'idle_hours' => $this->hours($row['idle_hours'] ?? null, "equipment.{$i}.idle_hours"),
            ])->save();
        }

        foreach ($materials as $i => $row) {
            $resolved = $this->materialWithUnit($row['material_id'] ?? null, $row['unit_id'] ?? null, "materials.{$i}");
            (new SiteDiaryMaterial)->forceFill([
                'site_diary_id' => $diary->id,
                'material_id' => $resolved['material']->id,
                'quantity' => $this->quantity($row['quantity'] ?? null, "materials.{$i}.quantity")->toQuantity(),
                'unit_id' => $resolved['unit_id'],
                'remarks' => $row['remarks'] ?? null,
            ])->save();
        }
    }
}
