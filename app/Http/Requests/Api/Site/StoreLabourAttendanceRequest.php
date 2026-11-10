<?php

namespace App\Http\Requests\Api\Site;

use App\Enums\Labour\AttendanceStatus;
use App\Models\Labour\LabourAttendance;
use App\Models\Projects\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StoreLabourAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('mark', [LabourAttendance::class, $this->project()]);
    }

    protected function prepareForValidation(): void
    {
        $records = $this->input('records');
        if (is_array($records)) {
            $this->merge([
                'records' => array_map(function ($row) {
                    if (! is_array($row)) {
                        return $row;
                    }
                    if (isset($row['status'])) {
                        $row['status'] = strtoupper(trim((string) $row['status']));
                    }

                    return $row;
                }, $records),
            ]);
        }

        $rows = $this->input('rows');
        if (is_array($rows)) {
            $this->merge([
                'rows' => array_map(function ($row) {
                    if (! is_array($row) || ! isset($row['status'])) {
                        return $row;
                    }
                    $status = (string) $row['status'];
                    if (strlen($status) === 1) {
                        $row['status'] = strtoupper($status);
                    }

                    return $row;
                }, $rows),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $statusRule = Rule::in(array_merge(AttendanceStatus::shorts(), array_column(AttendanceStatus::cases(), 'value')));

        return [
            'date' => ['required_without:attendance_date', 'date', 'before_or_equal:today'],
            'attendance_date' => ['required_without:date', 'date', 'before_or_equal:today'],
            'site_id' => ['nullable', 'integer'],
            'uuid' => ['nullable', 'uuid'],
            'sync_uuid' => ['nullable', 'uuid'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'records' => ['required_without:rows', 'array', 'min:1', 'max:500'],
            'records.*.labourer_id' => ['required_without:rows', 'integer'],
            'records.*.labour_id' => ['sometimes', 'integer'],
            'records.*.status' => ['required', 'string', $statusRule],
            'records.*.punch_in' => ['nullable', 'string', 'max:8'],
            'records.*.punch_out' => ['nullable', 'string', 'max:8'],
            'records.*.working_hours' => ['nullable', 'numeric', 'min:0', 'max:24'],
            'records.*.ot_hours' => ['nullable', 'numeric', 'min:0', 'max:24'],
            'records.*.task_id' => ['nullable', 'integer'],
            'records.*.remarks' => ['nullable', 'string', 'max:500'],
            'rows' => ['required_without:records', 'array', 'min:1', 'max:500'],
            'rows.*.labour_id' => ['required', 'integer'],
            'rows.*.status' => ['required', 'string', $statusRule],
            'rows.*.punch_in' => ['nullable', 'string', 'max:8'],
            'rows.*.punch_out' => ['nullable', 'string', 'max:8'],
            'rows.*.working_hours' => ['nullable', 'numeric', 'min:0', 'max:24'],
            'rows.*.ot_hours' => ['nullable', 'numeric', 'min:0', 'max:24'],
            'rows.*.task_id' => ['nullable', 'integer'],
            'rows.*.remarks' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $date = $this->validated('attendance_date') ?? $this->validated('date');
        $rows = $this->validated('rows') ?? collect($this->validated('records'))
            ->map(fn (array $row) => [
                'labour_id' => (int) ($row['labour_id'] ?? $row['labourer_id']),
                'status' => $this->normalizeStatus($row['status']),
                'punch_in' => $row['punch_in'] ?? null,
                'punch_out' => $row['punch_out'] ?? null,
                'working_hours' => $row['working_hours'] ?? null,
                'ot_hours' => $row['ot_hours'] ?? null,
                'task_id' => $row['task_id'] ?? null,
                'remarks' => $row['remarks'] ?? null,
            ])->values()->all();

        return [
            'attendance_date' => $date,
            'site_id' => $this->validated('site_id'),
            'uuid' => $this->validated('uuid') ?? $this->validated('sync_uuid'),
            'latitude' => $this->validated('latitude'),
            'longitude' => $this->validated('longitude'),
            'rows' => $rows,
        ];
    }

    private function normalizeStatus(string $status): string
    {
        $trim = trim($status);
        if (strlen($trim) === 1) {
            return AttendanceStatus::fromShort($trim)->value;
        }

        return AttendanceStatus::from($trim)->value;
    }

    private function project(): Project
    {
        return $this->route('project');
    }
}
