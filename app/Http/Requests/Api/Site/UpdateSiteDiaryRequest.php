<?php

namespace App\Http\Requests\Api\Site;

use App\Enums\SiteExecution\SiteDiaryStatus;
use App\Models\SiteExecution\SiteDiary;
use Illuminate\Support\Facades\Gate;

class UpdateSiteDiaryRequest extends StoreSiteDiaryRequest
{
    public function authorize(): bool
    {
        $diary = $this->route('siteDiary');

        return $diary instanceof SiteDiary
            && $diary->status !== SiteDiaryStatus::Approved
            && Gate::allows('update', $diary);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['uuid' => ['sometimes', 'nullable', 'string', 'uuid']] + parent::rules();
    }
}
