<?php

use App\Models\Core\DocumentNumberFormat;
use App\Models\Projects\Project;
use App\Services\Numbering\DocumentNumberService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->companyA = $this->createCompany();
    $this->companyB = $this->createCompany();
});

function nextNumber(string $type, ?Project $project = null, ?string $date = null): string
{
    return app(DocumentNumberService::class)->next($type, $project, $date ? CarbonImmutable::parse($date) : null);
}

test('each company has its own sequence', function () {
    $a = $this->inCompany($this->companyA, fn () => [nextNumber('vendor'), nextNumber('vendor')]);
    $b = $this->inCompany($this->companyB, fn () => nextNumber('vendor'));

    expect($a)->toBe(['VEN-0001', 'VEN-0002'])->and($b)->toBe('VEN-0001');
});

test('project-coded numbers have a separate sequence per project', function () {
    $this->inCompany($this->companyA, function () {
        $tower = Project::factory()->create(['code' => 'TWR']);
        $villa = Project::factory()->create(['code' => 'VIL']);

        expect(nextNumber('material_request', $tower))->toBe('MR-TWR-0001')
            ->and(nextNumber('material_request', $villa))->toBe('MR-VIL-0001')
            ->and(nextNumber('material_request', $tower))->toBe('MR-TWR-0002');
    });
});

test('a project-coded number cannot be generated without a project', function () {
    $this->inCompany($this->companyA, fn () => nextNumber('material_request'));
})->throws(InvalidArgumentException::class);

test('unknown document types are rejected', function () {
    $this->inCompany($this->companyA, fn () => nextNumber('no_such_document'));
})->throws(InvalidArgumentException::class);

test('a company override can reset the sequence every Indian financial year', function () {
    $this->inCompany($this->companyA, function () {
        DocumentNumberFormat::query()->create([
            'document_type' => 'vendor',
            'pattern' => 'V/{FY}/{SEQ:3}',
            'reset_frequency' => 'financial_year',
            'assign_on' => 'create',
        ]);

        expect(nextNumber('vendor', date: '2026-03-31'))->toBe('V/2025-26/001')
            ->and(nextNumber('vendor', date: '2026-04-01'))->toBe('V/2026-27/001')
            ->and(nextNumber('vendor', date: '2026-03-15'))->toBe('V/2025-26/002')
            ->and(nextNumber('vendor', date: '2027-03-31'))->toBe('V/2026-27/002');
    });

    expect($this->inCompany($this->companyB, fn () => nextNumber('vendor')))->toBe('VEN-0001');
});

test('a rolled-back save does not consume a number', function () {
    $this->inCompany($this->companyA, function () {
        try {
            DB::transaction(function () {
                nextNumber('vendor');
                throw new RuntimeException('save failed');
            });
        } catch (RuntimeException) {
        }

        expect(nextNumber('vendor'))->toBe('VEN-0001');
    });
});
