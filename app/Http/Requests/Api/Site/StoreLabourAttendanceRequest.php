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
        if (! is_array($records)) {
            return;
        }

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

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'date' => ['required', 'date', 'before_or_equal:today'],
            'records' => ['required', 'array', 'min:1', 'max:500'],
            'records.*.labourer_id' => ['required', 'integer'],
            'records.*.status' => ['required', 'string', Rule::in(AttendanceStatus::shorts())],
        ];
    }

    /**
     * @return array{attendance_date: string, rows: list<array{labour_id: int, status: string}>}
     */
    public function payload(): array
    {
        return [
            'attendance_date' => $this->validated('date'),
            'rows' => collect($this->validated('records'))
                ->map(fn (array $row) => [
                    'labour_id' => (int) $row['labourer_id'],
                    'status' => AttendanceStatus::fromShort($row['status'])->value,
                ])
                ->values()
                ->all(),
        ];
    }

    private function project(): Project
    {
        return $this->route('project');
    }
}
