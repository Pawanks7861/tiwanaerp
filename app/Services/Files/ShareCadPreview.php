<?php

namespace App\Services\Files;

use App\Logging\RedactExternalPreviewTokens;
use App\Models\Core\Company;
use App\Models\Files\ExternalPreviewToken;
use App\Models\Files\FileExternalAccess;
use App\Services\Audit\AuditLogger;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Optional ShareCAD fallback. The local converter stays preferred.
 * A DWG is fetched by ShareCAD only through a short-lived token for one approved file.
 */
class ShareCadPreview
{
    public const PROVIDER_KEY = 'dwg_preview_provider';

    public const ACK_KEY = 'dwg_preview_sharecad_acknowledged';

    public const TOO_LARGE = 'This drawing is too large for the external CAD viewer.';

    public const UNAVAILABLE = 'CAD preview is temporarily unavailable.';

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly FileSourceResolver $files,
    ) {}

    public function provider(): string
    {
        $company = $this->company();
        $stored = $company?->setting(self::PROVIDER_KEY);
        $provider = is_string($stored) ? $stored : (string) config('previews.dwg.provider', 'local');

        return in_array($provider, ['local', 'sharecad', 'auto'], true) ? $provider : 'local';
    }

    public function deploymentEnabled(): bool
    {
        return (bool) config('previews.dwg.sharecad_enabled');
    }

    public function acknowledged(): bool
    {
        return $this->company()?->setting(self::ACK_KEY) === true;
    }

    public function allows(PreviewableFile $file): bool
    {
        if (! in_array($file->extension, ['dwg', 'dxf'], true)) {
            return false;
        }

        return FileExternalAccess::query()
            ->where('source_type', $file->source)
            ->where('source_id', $file->id)
            ->value('allow_external') === true;
    }

    public function canManage(): bool
    {
        $company = $this->company();
        $user = auth()->user();

        return $company !== null && $user !== null && $user->can('manageSettings', $company);
    }

    /**
     * @return array{mode: string, use: bool, too_large: bool}
     */
    public function decision(PreviewableFile $file): array
    {
        $mode = $this->provider();
        $result = ['mode' => $mode, 'use' => false, 'too_large' => false];

        if (! in_array($file->extension, ['dwg', 'dxf'], true) || $mode === 'local') {
            return $result;
        }

        if (! $this->deploymentEnabled() || ! $this->acknowledged() || ! $this->allows($file)) {
            return $result;
        }

        if ($file->size > (int) config('previews.dwg.max_bytes')) {
            $result['too_large'] = true;

            return $result;
        }

        $result['use'] = true;

        return $result;
    }

    /**
     * @return array{provider: string, url: string, label: string, tooltip: string, failure_message: string}
     */
    public function frame(PreviewableFile $file): array
    {
        $plain = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        ExternalPreviewToken::query()->where('expires_at', '<', now()->subDay())->delete();

        $token = new ExternalPreviewToken;
        $token->forceFill([
            'token_hash' => hash('sha256', $plain),
            'source_type' => $file->source,
            'source_id' => $file->id,
            'provider' => 'sharecad',
            'expires_at' => now()->addMinutes($this->ttlMinutes()),
        ])->save();

        $this->audit->record($token, 'External DWG preview generated', null, [
            'provider' => 'sharecad',
            'source_type' => $file->source,
            'source_id' => $file->id,
        ]);

        $fileUrl = route('files.external-preview', ['token' => $plain]);

        return [
            'provider' => 'sharecad',
            'url' => (string) config('previews.dwg.frame').'?url='.rawurlencode($fileUrl),
            'label' => 'External CAD Viewer',
            'tooltip' => 'This drawing is being rendered using ShareCAD.',
            'failure_message' => self::UNAVAILABLE,
        ];
    }

    public function stream(string $token): Response
    {
        if (! preg_match('/^[A-Za-z0-9_-]{40,80}$/', $token)) {
            return $this->gone();
        }

        $row = ExternalPreviewToken::withoutGlobalScopes()
            ->where('token_hash', hash('sha256', $token))
            ->first();

        if ($row === null || $row->provider !== 'sharecad' || $row->expires_at === null || $row->expires_at->isPast()) {
            return $this->gone();
        }

        $company = Company::query()->find($row->company_id);
        if ($company === null) {
            return $this->gone();
        }

        return app(CurrentCompany::class)->runAs($company, function () use ($row) {
            if (! $this->deploymentEnabled() || ! $this->acknowledged() || $this->provider() === 'local') {
                return $this->gone();
            }

            try {
                $file = $this->files->locate($row->source_type, (int) $row->source_id);
            } catch (\Throwable) {
                return $this->gone();
            }

            if ($file->companyId !== (int) $row->company_id || $file->disk !== 'private') {
                return $this->gone();
            }

            if (! in_array($file->extension, ['dwg', 'dxf'], true) || $file->size > (int) config('previews.dwg.max_bytes') || ! $this->allows($file)) {
                return $this->gone();
            }

            $absolute = Storage::disk('private')->path($file->path);
            if (! is_file($absolute)) {
                return $this->gone();
            }

            if ($row->used_at === null) {
                $row->forceFill(['used_at' => now()])->save();
            }

            $name = 'drawing.'.$file->extension;
            $response = response()->file($absolute);
            $response->headers->set('Content-Type', $file->extension === 'dxf' ? 'image/vnd.dxf' : 'image/vnd.dwg');
            $response->headers->set('X-Content-Type-Options', 'nosniff');
            $response->headers->set('Cache-Control', 'private, no-store');
            $response->headers->set('Content-Disposition', 'inline; filename="'.$name.'"');

            return $response;
        });
    }

    private function company(): ?Company
    {
        return app(CurrentCompany::class)->get();
    }

    private function gone(): Response
    {
        return response('', 410);
    }

    public function mask(string $value): string
    {
        return RedactExternalPreviewTokens::redact($value);
    }

    private function ttlMinutes(): int
    {
        return min(10, max(5, (int) config('previews.dwg.ttl_minutes', 10)));
    }
}
