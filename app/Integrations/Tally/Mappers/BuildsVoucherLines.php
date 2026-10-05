<?php

namespace App\Integrations\Tally\Mappers;

use App\Support\Math\Decimal;

trait BuildsVoucherLines
{
    protected function money(mixed $value): string
    {
        return Decimal::of($value === null ? '0' : (string) $value)->toMoney();
    }

    /**
     * @return array{ledger: string, debit: string, credit: string}|null
     */
    protected function debit(string $ledger, mixed $amount): ?array
    {
        $money = $this->money($amount);

        return Decimal::of($money)->isZero() ? null : ['ledger' => $ledger, 'debit' => $money, 'credit' => '0.00'];
    }

    /**
     * @return array{ledger: string, debit: string, credit: string}|null
     */
    protected function credit(string $ledger, mixed $amount): ?array
    {
        $money = $this->money($amount);

        return Decimal::of($money)->isZero() ? null : ['ledger' => $ledger, 'debit' => '0.00', 'credit' => $money];
    }

    /**
     * @param  list<array{ledger: string, debit: string, credit: string}|null>  $lines
     * @return list<array{ledger: string, debit: string, credit: string}>
     */
    protected function lines(array $lines): array
    {
        return array_values(array_filter($lines));
    }

    protected function narration(?string $projectCode, string $reference): string
    {
        $project = $projectCode !== null && $projectCode !== '' ? $projectCode : '—';

        return mb_substr("Imported from Tiwana ERP | Project: {$project} | ERP Ref: {$reference}", 0, 240);
    }

    protected function reference(string $type, int $id): string
    {
        return "ERP:{$type}:{$id}";
    }
}
