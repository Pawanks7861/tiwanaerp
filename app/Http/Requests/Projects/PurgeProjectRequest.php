<?php

namespace App\Http\Requests\Projects;

use App\Models\Projects\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class PurgeProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Project $project */
        $project = $this->route('project');

        return Gate::allows('purge', $project);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'confirmation_code' => ['required', 'string', 'max:12'],
            'acknowledge' => ['accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'confirmation_code.required' => 'Type the project code exactly to permanently delete this project.',
            'acknowledge.accepted' => 'Confirm that you understand this action permanently deletes all project data.',
        ];
    }
}
