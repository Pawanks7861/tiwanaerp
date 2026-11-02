<?php

namespace App\Services\Ai\Local;

use App\Models\User;
use Illuminate\Support\Str;

class ChatResponseFormatter
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $filters
     */
    public function formatAnalytics(string $intentId, array $payload, array $filters = [], ?User $user = null): string
    {
        if (isset($payload['error'])) {
            return (string) ($payload['message'] ?? 'This information is restricted for your role.');
        }

        return match (true) {
            str_starts_with($intentId, 'purchase_order') => $this->formatPo($payload, $filters),
            $intentId === 'drawing.pending' => $this->formatDrawings($payload),
            $intentId === 'boq.summary' => $this->formatBoq($payload),
            $intentId === 'inventory.summary' => $this->formatInventory($payload),
            $intentId === 'finance.expenses' => $this->formatExpenses($payload),
            $intentId === 'finance.vendor_outstanding' => $this->formatOutstanding($payload),
            $intentId === 'projects.active' => $this->formatProjects($payload),
            default => $this->prettyJson($payload),
        };
    }

    public function formatHelp(string $markdown, ?string $routeHint = null): string
    {
        $text = trim($markdown);
        $text = preg_replace('/^#+\s*/m', '', $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        if ($routeHint) {
            $text .= "\n\nOpen: {$routeHint}";
        }

        return trim($text) !== ''
            ? trim($text)
            : 'I found a related help topic, but no detailed article is available yet. Ask about BOQ, drawings, purchase orders, GRN, inventory, or finance.';
    }

    /**
     * @param  list<array{id: string, score: int, label: string}>  $candidates
     * @param  list<string>  $suggestions
     */
    public function formatFallback(array $candidates, array $suggestions): string
    {
        $lines = [
            "I couldn't understand that request yet. You can ask about purchase orders, BOQs, drawings, inventory, finance, and other supported ERP functions.",
            '',
            'Try one of these:',
        ];

        $labels = $candidates !== []
            ? array_map(fn ($c) => $c['label'], array_slice($candidates, 0, 4))
            : array_slice($suggestions, 0, 6);

        foreach ($labels as $label) {
            $lines[] = '• '.$label;
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $filters
     */
    private function formatPo(array $payload, array $filters): string
    {
        $period = $payload['period_label'] ?? 'selected period';
        $count = (int) ($payload['po_count'] ?? 0);
        $lines = ["Purchase Order summary for {$period}:"];

        if (! empty($payload['pending_only'])) {
            $lines = ["Pending Purchase Order approvals:"];
            $lines[] = "Pending POs: {$count}";
        } else {
            $lines[] = "Total Purchase Orders: {$count}";
            if (($payload['grand_total_visible'] ?? false) && $payload['grand_total'] !== null) {
                $lines[] = 'Total Amount: '.$this->money((string) $payload['grand_total'], (string) ($payload['currency'] ?? 'INR'));
            } elseif ($count > 0) {
                $lines[] = 'Monetary totals are hidden — financial view permission is required.';
            }
        }

        $lines[] = 'Period: '.$this->formatDate($payload['from'] ?? null).' – '.$this->formatDate($payload['to'] ?? null);
        $lines[] = 'Status: '.implode(', ', $payload['statuses_included'] ?? ['approved / issued']);
        if (! empty($payload['excluded'])) {
            $lines[] = 'Excluded: '.implode(', ', $payload['excluded']);
        }
        if (! empty($filters['project_id'])) {
            $lines[] = 'Filter: project #'.(int) $filters['project_id'];
        }
        if (! empty($filters['vendor_id'])) {
            $lines[] = 'Filter: vendor #'.(int) $filters['vendor_id'];
        }

        if (! empty($payload['breakdown']) && is_array($payload['breakdown'])) {
            $lines[] = '';
            $lines[] = 'Breakdown:';
            foreach (array_slice($payload['breakdown'], 0, 8) as $row) {
                $name = $row['vendor'] ?? $row['project'] ?? 'Item';
                $amt = isset($row['total']) ? $this->money((string) $row['total'], (string) ($payload['currency'] ?? 'INR')) : '';
                $lines[] = '• '.$name.' — '.(int) ($row['po_count'] ?? 0).' PO'.($amt !== '' ? ", {$amt}" : '');
            }
        }

        if ($count === 0) {
            $lines[] = '';
            $lines[] = 'No matching purchase orders were found for this filter.';
        } else {
            $lines[] = '';
            $lines[] = 'Open Purchase Orders in Procurement to review the filtered list.';
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function formatDrawings(array $payload): string
    {
        $pending = (int) ($payload['pending_approval'] ?? 0);
        $counts = $payload['counts'] ?? [];

        return implode("\n", [
            'Drawing approval summary:',
            "Pending approval: {$pending}",
            'Approved: '.(int) ($counts['approved'] ?? 0),
            'Rejected: '.(int) ($counts['rejected'] ?? 0),
            'Draft: '.(int) ($counts['draft'] ?? 0),
            '',
            'Open Drawing Management in the project to review revisions.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function formatBoq(array $payload): string
    {
        $lines = [
            'BOQ summary:',
            'BOQs: '.(int) ($payload['boq_count'] ?? 0),
            'Approved (current): '.(int) ($payload['approved_current_count'] ?? 0),
        ];
        if (($payload['totals_visible'] ?? false) && isset($payload['total_cost_amount'])) {
            $lines[] = 'Total cost amount: '.$this->money((string) $payload['total_cost_amount'], (string) ($payload['currency'] ?? 'INR'));
            if (isset($payload['total_client_amount'])) {
                $lines[] = 'Total client amount: '.$this->money((string) $payload['total_client_amount'], (string) ($payload['currency'] ?? 'INR'));
            }
        }
        if (! empty($payload['item_count'])) {
            $lines[] = 'Line items: '.(int) $payload['item_count'];
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function formatInventory(array $payload): string
    {
        if (($payload['supported'] ?? true) === false) {
            return (string) ($payload['message'] ?? 'Inventory summary is not available.');
        }

        $lines = [
            'Inventory summary:',
            'Balance lines: '.(int) ($payload['balance_lines'] ?? 0),
            'Total quantity: '.(string) ($payload['total_quantity'] ?? '0'),
        ];
        if (isset($payload['valuation']) && $payload['valuation'] !== null) {
            $lines[] = 'Valuation: '.$this->money((string) $payload['valuation'], 'INR');
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function formatExpenses(array $payload): string
    {
        $lines = [
            'Expense summary ('.($payload['period_label'] ?? 'period').'):',
            'Records: '.(int) ($payload['count'] ?? 0),
        ];
        if ($payload['total_amount'] !== null) {
            $lines[] = 'Total: '.$this->money((string) $payload['total_amount'], (string) ($payload['currency'] ?? 'INR'));
        } else {
            $lines[] = 'Amounts hidden — financial permission required.';
        }
        $lines[] = 'Period: '.$this->formatDate($payload['from'] ?? null).' – '.$this->formatDate($payload['to'] ?? null);

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function formatOutstanding(array $payload): string
    {
        $lines = [
            'Vendor outstanding (approved / partially paid bills):',
            'Vendors with balance: '.(int) ($payload['vendor_count'] ?? 0),
        ];
        if (($payload['totals_visible'] ?? false) && isset($payload['total_outstanding'])) {
            $lines[] = 'Total outstanding: '.$this->money((string) $payload['total_outstanding'], (string) ($payload['currency'] ?? 'INR'));
        }
        if (! empty($payload['vendors']) && is_array($payload['vendors'])) {
            $lines[] = '';
            $lines[] = 'Top vendors:';
            foreach (array_slice($payload['vendors'], 0, 8) as $row) {
                $lines[] = '• '.($row['vendor'] ?? 'Vendor').' — '.$this->money((string) ($row['outstanding'] ?? '0'), (string) ($payload['currency'] ?? 'INR'));
            }
        }
        if ((int) ($payload['vendor_count'] ?? 0) === 0) {
            $lines[] = '';
            $lines[] = 'No outstanding vendor balances found for your accessible projects.';
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function formatProjects(array $payload): string
    {
        $lines = [
            'Projects overview:',
            'Active projects: '.(int) ($payload['active_count'] ?? 0),
            'Total visible projects: '.(int) ($payload['total_count'] ?? 0),
            'Upcoming milestones (30 days): '.(int) ($payload['upcoming_milestones'] ?? 0),
            'Overdue milestones: '.(int) ($payload['overdue_milestones'] ?? 0),
        ];
        if (! empty($payload['milestones']) && is_array($payload['milestones'])) {
            $lines[] = '';
            $lines[] = 'Next milestones:';
            foreach (array_slice($payload['milestones'], 0, 5) as $row) {
                $lines[] = '• '.($row['project'] ?? 'Project').' — '.($row['name'] ?? 'Milestone').' ('.($row['due_date'] ?? 'n/a').')';
            }
        }

        return implode("\n", $lines);
    }

    public function money(string $amount, string $currency = 'INR'): string
    {
        $negative = str_starts_with($amount, '-');
        $amount = ltrim($amount, '-');
        $parts = explode('.', $amount, 2);
        $int = $parts[0] !== '' ? $parts[0] : '0';
        $dec = isset($parts[1]) ? substr(str_pad($parts[1], 2, '0'), 0, 2) : '00';

        $len = strlen($int);
        if ($len <= 3) {
            $formatted = $int;
        } else {
            $last3 = substr($int, -3);
            $rest = substr($int, 0, -3);
            $rest = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest) ?? $rest;
            $formatted = $rest.','.$last3;
        }

        $symbol = $currency === 'INR' ? '₹' : $currency.' ';

        return ($negative ? '-' : '').$symbol.$formatted.'.'.$dec;
    }

    private function formatDate(mixed $date): string
    {
        if (! is_string($date) || $date === '') {
            return 'n/a';
        }
        try {
            return \Carbon\CarbonImmutable::parse($date)->format('j F Y');
        } catch (\Throwable) {
            return $date;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function prettyJson(array $payload): string
    {
        return Str::limit(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '', 2000);
    }
}
