<?php

namespace App\Services\Ai\Local;

use Illuminate\Support\Str;

class QuestionNormalizer
{
    /**
     * Normalize user text for intent matching (English / Hindi / Hinglish).
     */
    public function normalize(string $text): string
    {
        $text = Str::lower(trim($text));
        $text = str_replace(['?', '!', ',', '"', "'", '।', '？'], ' ', $text);

        $replacements = [
            'purchase orders' => 'purchase_order',
            'purchase order' => 'purchase_order',
            'p\.?o\.?s?' => 'purchase_order',
            'खरीद आदेश' => 'purchase_order',
            'खरीद' => 'purchase',
            'kitne' => 'how_many',
            'kitna' => 'how_much',
            'batao' => 'show',
            'bata' => 'show',
            'dikhao' => 'show',
            'dikha' => 'show',
            'amount' => 'amount',
            'value' => 'amount',
            'total' => 'total',
            'sum' => 'total',
            'is month' => 'this_month',
            'iss month' => 'this_month',
            'is mahine' => 'this_month',
            'iss mahine' => 'this_month',
            'is mahina' => 'this_month',
            'pichle mahine' => 'last_month',
            'pichhla mahina' => 'last_month',
            'last month' => 'last_month',
            'this month' => 'this_month',
            'aaj' => 'today',
            'today' => 'today',
            'pending' => 'pending',
            'approval' => 'approval',
            'approve' => 'approval',
            'drawing' => 'drawing',
            'drawings' => 'drawing',
            'ड्राइंग' => 'drawing',
            'boq' => 'boq',
            'b\.?o\.?q' => 'boq',
            'bill of quantities' => 'boq',
            'bill of quantity' => 'boq',
            'grn' => 'grn',
            'goods receipt' => 'grn',
            'inventory' => 'inventory',
            'stock' => 'inventory',
            'स्टॉक' => 'inventory',
            'vendor' => 'vendor',
            'supplier' => 'vendor',
            'outstanding' => 'outstanding',
            'baki' => 'outstanding',
            'baaki' => 'outstanding',
            'expense' => 'expense',
            'expenditure' => 'expense',
            'kharcha' => 'expense',
            'project' => 'project',
            'kaise' => 'how',
            'kese' => 'how',
            'kaise create' => 'how create',
            'create karu' => 'how create',
            'banao' => 'how create',
            'banaye' => 'how create',
            'upload' => 'upload',
            'import' => 'import',
            'only for' => 'filter_project',
            'sirf' => 'filter_project',
            'by vendor' => 'group_vendor',
            'vendor wise' => 'group_vendor',
            'by project' => 'group_project',
            'project wise' => 'group_project',
            'what about' => 'followup',
            'and for' => 'followup',
        ];

        foreach ($replacements as $from => $to) {
            $text = preg_replace('/\b'.str_replace('/', '\/', $from).'\b/u', $to, $text) ?? $text;
        }

        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    /**
     * @return list<string>
     */
    public function tokens(string $normalized): array
    {
        $parts = preg_split('/[\s_]+/u', $normalized) ?: [];

        return array_values(array_filter($parts, fn ($t) => $t !== '' && Str::length($t) > 1));
    }
}
