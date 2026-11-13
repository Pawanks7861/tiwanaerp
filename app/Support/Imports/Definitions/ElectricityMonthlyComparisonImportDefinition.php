<?php

namespace App\Support\Imports\Definitions;

use App\Enums\Imports\ImportMode;
use App\Enums\Utilities\ElectricityBillStatus;
use App\Enums\Utilities\ElectricityReadingStatus;
use App\Models\Projects\Project;
use App\Models\User;
use App\Models\Utilities\ElectricityBill;
use App\Models\Utilities\ElectricityConnection;
use App\Models\Utilities\ElectricityMeter;
use App\Models\Utilities\ElectricityMeterReading;
use App\Models\Utilities\UtilitySite;
use App\Services\Utilities\ElectricityBillService;
use App\Services\Utilities\ElectricityMeterReadingService;
use App\Support\Imports\Concerns\NormalizesImportValues;
use App\Support\Imports\ImportContext;
use App\Support\Imports\ImportDefinition;
use App\Support\Imports\ImportField;
use App\Support\Math\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Historical bootstrap from Electricity Monthly Comparison workbook rows.
 * Excel values are reference only — readings recomputed server-side; bill totals
 * may be stored as actual/reference when tariff rules are incomplete.
 */
class ElectricityMonthlyComparisonImportDefinition extends ImportDefinition
{
    use NormalizesImportValues;

    public function __construct(
        private readonly ElectricityMeterReadingService $readings,
        private readonly ElectricityBillService $bills,
    ) {}

    public function key(): string
    {
        return 'electricity.monthly_comparison';
    }

    public function label(): string
    {
        return 'Electricity Monthly Comparison';
    }

    public function permission(): string
    {
        return 'utilities.electricity.import';
    }

    public function requiresProject(): bool
    {
        return false;
    }

    public function modes(): array
    {
        return [ImportMode::Create];
    }

    public function uniqueKey(): ?string
    {
        return 'connection_month';
    }

    public function authorize(User $user, ?Project $project = null): bool
    {
        return $user->can('utilities.electricity.import')
            && $user->can('utilities.electricity.enter_readings')
            && $user->can('utilities.electricity.create_bill');
    }

    public function instructions(): string
    {
        return 'Imports historical monthly electricity rows (site + connection + meter readings + optional actual bill total). '
            .'Server recalculates kWh/kVAh/demand/PF. Calculated bill amounts use ERP tariffs when available; '
            .'otherwise imported bill totals are stored as actual/reference only. EXAMPLE rows are ignored.';
    }

    public function templateFilename(): string
    {
        return 'Tiwana_ERP_Electricity_Monthly_Comparison_Import';
    }

    public function fields(): array
    {
        return [
            new ImportField('site_code', 'Site code', true, ['plant_code', 'utility_site']),
            new ImportField('site_name', 'Site name', false, ['plant', 'site']),
            new ImportField('state_code', 'State code', false, ['state'], help: '2-digit GST state code'),
            new ImportField('connection_number', 'Connection number', true, ['connection', 'consumer_number']),
            new ImportField('meter_number', 'Meter number', true, ['meter']),
            new ImportField('billing_month', 'Billing month', true, ['month'], 'date', help: 'Any day in the month; stored as month start'),
            new ImportField('from_date', 'From date', true, ['period_from'], 'date'),
            new ImportField('to_date', 'To date', true, ['period_to'], 'date'),
            new ImportField('opening_kwh', 'Opening kWh', true, [], 'number'),
            new ImportField('closing_kwh', 'Closing kWh', true, [], 'number'),
            new ImportField('opening_kvah', 'Opening kVAh', false, [], 'number'),
            new ImportField('closing_kvah', 'Closing kVAh', false, [], 'number'),
            new ImportField('mdi_reading', 'MDI reading', false, ['mdi'], 'number'),
            new ImportField('multiplication_factor', 'MF', false, ['mf', 'multiplier'], 'number'),
            new ImportField('actual_bill_total', 'Actual bill total', false, ['bill_amount', 'invoice_amount'], 'number'),
            new ImportField('bill_number', 'DISCOM bill number', false, ['invoice_no']),
            new ImportField('bill_date', 'Bill date', false, [], 'date'),
            new ImportField('due_date', 'Due date', false, [], 'date'),
            new ImportField('remarks', 'Remarks', false, ['notes']),
        ];
    }

    public function exampleRows(): array
    {
        return [[
            'site_code' => 'AHM',
            'site_name' => 'EXAMPLE — Ahmedabad',
            'state_code' => '24',
            'connection_number' => 'CONN-AHM-01',
            'meter_number' => 'MTR-AHM-01',
            'billing_month' => '2025-08-01',
            'from_date' => '2025-08-01',
            'to_date' => '2025-08-31',
            'opening_kwh' => '1000',
            'closing_kwh' => '1500',
            'opening_kvah' => '1050',
            'closing_kvah' => '1570',
            'mdi_reading' => '120',
            'multiplication_factor' => '10',
            'actual_bill_total' => '1250000',
            'bill_number' => 'DISCOM-EX-1',
            'bill_date' => '2025-09-05',
            'due_date' => '2025-09-20',
            'remarks' => 'EXAMPLE ROW — ignored',
        ]];
    }

    public function prepareRow(array $mapped, ImportMode $mode, ImportContext $context): array
    {
        if ($this->blankRow($mapped) || $this->isExampleRow($mapped)) {
            return ['action' => 'skip', 'data' => [], 'existing_id' => null, 'preview' => ['name' => '(skipped)']];
        }

        $siteCode = strtoupper((string) ($this->codeString($mapped['site_code'] ?? null) ?? ''));
        $connNo = (string) ($this->codeString($mapped['connection_number'] ?? null) ?? '');
        $meterNo = (string) ($this->codeString($mapped['meter_number'] ?? null) ?? '');
        $month = $this->date($mapped['billing_month'] ?? null);

        if ($siteCode === '' || $connNo === '' || $meterNo === '' || $month === null) {
            $this->fail(['row' => ['Site code, connection, meter and billing month are required.']]);
        }

        $monthStart = \Carbon\Carbon::parse($month)->startOfMonth()->toDateString();
        $dupKey = $connNo.'|'.$monthStart;
        /** @var \ArrayObject<string, bool> $seen */
        $seen = $context->remember('elec_seen_connection_months', fn () => new \ArrayObject);
        if ($seen->offsetExists($dupKey)) {
            $this->fail(['billing_month' => ['Duplicate connection/month in this file.']]);
        }
        $seen[$dupKey] = true;

        $connection = ElectricityConnection::query()->where('connection_number', $connNo)->first();
        $meter = ElectricityMeter::query()->where('meter_number', $meterNo)->first();
        if ($connection && $meter && (int) $meter->electricity_connection_id !== (int) $connection->id) {
            $this->fail(['meter_number' => ['Meter does not belong to connection.']]);
        }
        if ($meter) {
            $exists = ElectricityMeterReading::query()
                ->where('electricity_meter_id', $meter->id)
                ->whereDate('billing_month', $monthStart)
                ->where('status', '!=', ElectricityReadingStatus::Cancelled)
                ->exists();
            if ($exists) {
                $this->fail(['billing_month' => ['Reading already exists for this meter/month.']]);
            }
        }

        $mf = $this->number($mapped['multiplication_factor'] ?? null) ?? '1';
        if (Decimal::of($mf)->lessThanOrEqual('0')) {
            $this->fail(['multiplication_factor' => ['MF must be greater than zero.']]);
        }

        $data = [
            'site_code' => $siteCode,
            'site_name' => $this->str($mapped['site_name'] ?? null) ?? $siteCode,
            'state_code' => $this->str($mapped['state_code'] ?? null),
            'connection_number' => $connNo,
            'meter_number' => $meterNo,
            'billing_month' => $monthStart,
            'from_date' => $this->date($mapped['from_date'] ?? null) ?? $monthStart,
            'to_date' => $this->date($mapped['to_date'] ?? null) ?? $monthStart,
            'opening_kwh' => $this->number($mapped['opening_kwh'] ?? null) ?? '0',
            'closing_kwh' => $this->number($mapped['closing_kwh'] ?? null) ?? '0',
            'opening_kvah' => $this->number($mapped['opening_kvah'] ?? null),
            'closing_kvah' => $this->number($mapped['closing_kvah'] ?? null),
            'mdi_reading' => $this->number($mapped['mdi_reading'] ?? null),
            'multiplication_factor' => $mf,
            'actual_bill_total' => $this->number($mapped['actual_bill_total'] ?? null),
            'bill_number' => $this->str($mapped['bill_number'] ?? null),
            'bill_date' => $this->date($mapped['bill_date'] ?? null),
            'due_date' => $this->date($mapped['due_date'] ?? null),
            'remarks' => $this->str($mapped['remarks'] ?? null),
        ];

        return [
            'action' => 'create',
            'data' => $data,
            'existing_id' => null,
            'preview' => [
                'name' => $data['site_code'].' / '.$data['connection_number'],
                'code' => $data['billing_month'],
            ],
        ];
    }

    public function commitRow(array $prepared, ImportMode $mode, ImportContext $context): string
    {
        if ($prepared['action'] !== 'create') {
            return 'skipped';
        }

        $row = $prepared['data'];

        DB::transaction(function () use ($row, $context) {
            $site = UtilitySite::query()->firstOrCreate(
                ['code' => $row['site_code']],
                [
                    'name' => $row['site_name'],
                    'state_code' => $row['state_code'],
                    'is_active' => true,
                ]
            );

            $connection = ElectricityConnection::query()->firstOrCreate(
                ['connection_number' => $row['connection_number']],
                [
                    'utility_site_id' => $site->id,
                    'state_code' => $row['state_code'] ?? $site->state_code,
                    'default_multiplication_factor' => $row['multiplication_factor'],
                    'kwh_enabled' => true,
                    'kvah_enabled' => true,
                    'is_active' => true,
                ]
            );

            $meter = ElectricityMeter::query()->firstOrCreate(
                ['meter_number' => $row['meter_number']],
                [
                    'electricity_connection_id' => $connection->id,
                    'multiplication_factor' => $row['multiplication_factor'],
                    'is_active' => true,
                ]
            );

            $reading = $this->readings->create([
                'utility_site_id' => $site->id,
                'electricity_connection_id' => $connection->id,
                'electricity_meter_id' => $meter->id,
                'billing_month' => $row['billing_month'],
                'from_date' => $row['from_date'],
                'to_date' => $row['to_date'],
                'opening_kwh' => $row['opening_kwh'],
                'closing_kwh' => $row['closing_kwh'],
                'opening_kvah' => $row['opening_kvah'],
                'closing_kvah' => $row['closing_kvah'],
                'mdi_reading' => $row['mdi_reading'],
                'multiplication_factor' => $row['multiplication_factor'],
                'remarks' => trim(($row['remarks'] ?? '').' [imported]'),
            ]);

            $reading = $this->readings->confirm($reading, $context->actor);

            try {
                $bill = $this->bills->createFromReading($reading, [
                    'bill_number' => $row['bill_number'],
                    'bill_date' => $row['bill_date'],
                    'due_date' => $row['due_date'],
                    'remarks' => 'Imported historical row (source=excel_import)',
                ]);
            } catch (ValidationException) {
                $bill = new ElectricityBill;
                $bill->fill([
                    'utility_site_id' => $site->id,
                    'electricity_connection_id' => $connection->id,
                    'electricity_meter_reading_id' => $reading->id,
                    'bill_date' => $row['bill_date'],
                    'due_date' => $row['due_date'],
                    'remarks' => 'Imported without ERP tariff (actual/reference only)',
                ]);
                $bill->bill_number = $row['bill_number'] ?: ('IMP-'.$reading->id);
                $bill->billing_month = $reading->billing_month;
                $bill->status = ElectricityBillStatus::ActualEntered;
                $bill->calculated_total = '0';
                $bill->reading_snapshot = [
                    'id' => $reading->id,
                    'billing_month' => $reading->billing_month?->toDateString(),
                    'kwh_consumption' => (string) $reading->kwh_consumption,
                    'kvah_consumption' => $reading->kvah_consumption !== null ? (string) $reading->kvah_consumption : null,
                    'billing_demand' => $reading->billing_demand !== null ? (string) $reading->billing_demand : null,
                    'power_factor' => $reading->power_factor !== null ? (string) $reading->power_factor : null,
                    'source' => 'excel_import',
                ];
                $bill->save();
            }

            if ($row['actual_bill_total'] !== null) {
                $this->bills->enterActual($bill->fresh('components'), [
                    'actual_bill_total' => $row['actual_bill_total'],
                    'bill_date' => $row['bill_date'],
                    'due_date' => $row['due_date'],
                    'bill_number' => $row['bill_number'],
                ]);
            }
        });

        return 'created';
    }
}
