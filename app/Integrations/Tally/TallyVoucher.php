<?php

namespace App\Integrations\Tally;

use App\Support\Math\Decimal;

/**
 * Accounting voucher built from stored ERP amounts. Debits and credits are positive money strings.
 */
final class TallyVoucher
{
    /**
     * @param  list<array{ledger: string, debit: string, credit: string}>  $lines
     */
    public function __construct(
        public readonly string $voucherType,
        public readonly string $date,
        public readonly string $number,
        public readonly string $reference,
        public readonly string $narration,
        public readonly string $partyLedger,
        public readonly array $lines,
        public readonly ?string $costCentre = null,
    ) {}

    public function assertBalanced(): void
    {
        $debit = Decimal::zero();
        $credit = Decimal::zero();
        foreach ($this->lines as $line) {
            $debit = $debit->plus($line['debit']);
            $credit = $credit->plus($line['credit']);
        }

        if (! $debit->equals($credit)) {
            throw new TallyException('Stored amounts do not balance for this document. Tally was not updated.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $lines = $this->lines;
        usort($lines, fn (array $a, array $b) => [$a['ledger'], $a['debit'], $a['credit']] <=> [$b['ledger'], $b['debit'], $b['credit']]);

        return [
            'voucher_type' => $this->voucherType,
            'date' => $this->date,
            'number' => $this->number,
            'reference' => $this->reference,
            'narration' => $this->narration,
            'party_ledger' => $this->partyLedger,
            'cost_centre' => $this->costCentre,
            'lines' => $lines,
        ];
    }

    public function hash(): string
    {
        return hash('sha256', json_encode($this->toArray(), JSON_THROW_ON_ERROR));
    }
}
