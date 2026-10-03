<?php

namespace App\Http\Requests\Projects;

use App\Models\Projects\Site;
use Illuminate\Foundation\Http\FormRequest;

class SiteRequest extends FormRequest
{
    public function authorize(): bool
    {
        $site = $this->route('site');

        return $site instanceof Site
            ? $this->user()->can('update', $site)
            : $this->user()->can('create', [Site::class, $this->route('project')]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'address' => ['nullable', 'string', 'max:1000'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'decimal:0,7', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'decimal:0,7', 'required_with:latitude'],
            'geofence_radius_m' => ['nullable', 'integer', 'between:10,10000'],
            'is_active' => ['boolean'],
        ];
    }

    public function attributes(): array
    {
        return ['geofence_radius_m' => 'geofence radius'];
    }
}
