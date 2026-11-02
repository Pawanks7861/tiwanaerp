<?php

namespace App\Services\Ai\Local;

class IntentRegistry
{
    /**
     * @return list<IntentDefinition>
     */
    public function all(): array
    {
        return [
            new IntentDefinition(
                id: 'help.boq.create',
                type: 'help',
                label: 'How to create a BOQ',
                keywords: ['boq', 'create', 'how', 'banao', 'banaye'],
                phrases: ['how create boq', 'create boq', 'boq kaise', 'boq how create', 'how do i create a boq'],
                helpTopic: 'create BOQ',
                suggestions: ['How to create BOQ?', 'Why can\'t I create a BOQ?'],
                routeName: null,
                permission: 'boq.view',
            ),
            new IntentDefinition(
                id: 'help.boq.blocked',
                type: 'help',
                label: 'Why BOQ cannot be created',
                keywords: ['boq', 'cannot', 'can\'t', 'why', 'blocked', 'unable'],
                phrases: ['why can\'t i create boq', 'cannot create boq', 'boq nahi ban', 'why boq'],
                helpTopic: 'why can\'t create BOQ drawing approval',
                suggestions: ['Pending drawing approvals', 'How to create BOQ?'],
            ),
            new IntentDefinition(
                id: 'help.drawing.upload',
                type: 'help',
                label: 'How to upload a drawing',
                keywords: ['drawing', 'upload', 'how'],
                phrases: ['upload drawing', 'how upload drawing', 'drawing kaise upload', 'upload a drawing'],
                helpTopic: 'upload drawing',
                permission: 'drawings.view',
            ),
            new IntentDefinition(
                id: 'help.drawing.approve',
                type: 'help',
                label: 'How to approve a drawing',
                keywords: ['drawing', 'approval', 'approve', 'how'],
                phrases: ['approve drawing', 'how approve drawing', 'drawing approval process'],
                helpTopic: 'approve drawing',
                permission: 'drawings.view',
            ),
            new IntentDefinition(
                id: 'help.grn.create',
                type: 'help',
                label: 'How to create a GRN',
                keywords: ['grn', 'create', 'how', 'receive'],
                phrases: ['create grn', 'how create grn', 'grn kaise', 'goods receipt'],
                helpTopic: 'create GRN',
                permission: 'grn.view',
            ),
            new IntentDefinition(
                id: 'help.pr.create',
                type: 'help',
                label: 'How to create a Purchase Request',
                keywords: ['purchase', 'request', 'material', 'pr', 'create', 'how'],
                phrases: ['create purchase request', 'create material request', 'pr kaise', 'how create pr'],
                helpTopic: 'create purchase request material request',
                permission: 'material_requests.view',
            ),
            new IntentDefinition(
                id: 'help.po.create',
                type: 'help',
                label: 'How to create / approve a Purchase Order',
                keywords: ['purchase_order', 'create', 'approval', 'how', 'process'],
                phrases: ['create purchase order', 'po process', 'approve purchase order', 'how create po', 'po kaise'],
                helpTopic: 'create purchase order approve PO',
                permission: 'purchase.view',
            ),
            new IntentDefinition(
                id: 'help.inventory.issue',
                type: 'help',
                label: 'How to issue materials',
                keywords: ['inventory', 'issue', 'transfer', 'material', 'how'],
                phrases: ['issue materials', 'material issue', 'issue to project', 'transfer stock'],
                helpTopic: 'issue materials inventory transfer',
                permission: 'inventory.view',
            ),
            new IntentDefinition(
                id: 'help.import.materials',
                type: 'help',
                label: 'How to import materials from Excel',
                keywords: ['import', 'excel', 'material', 'csv', 'how'],
                phrases: ['import materials', 'excel import', 'download template materials'],
                helpTopic: 'import materials excel',
            ),
            new IntentDefinition(
                id: 'help.finance.payments',
                type: 'help',
                label: 'Where to view vendor payments',
                keywords: ['vendor', 'payment', 'invoice', 'where', 'view'],
                phrases: ['vendor payments', 'where view payments', 'download invoice'],
                helpTopic: 'vendor payments invoices finance',
                permission: 'vendor_bills.view',
            ),
            new IntentDefinition(
                id: 'help.reports',
                type: 'help',
                label: 'How to generate reports',
                keywords: ['report', 'generate', 'download', 'how'],
                phrases: ['generate project report', 'how generate report', 'download report'],
                helpTopic: 'project reports',
                permission: 'reports.view',
            ),
            new IntentDefinition(
                id: 'help.general',
                type: 'help',
                label: 'General ERP help',
                keywords: ['help', 'how', 'module', 'workflow', 'erp'],
                phrases: ['how does erp work', 'help me', 'what can you do'],
                helpTopic: 'general erp overview',
            ),

            new IntentDefinition(
                id: 'purchase_order.monthly_total',
                type: 'analytics',
                label: 'Purchase Order total',
                keywords: ['purchase_order', 'total', 'amount', 'value', 'sum', 'how_much'],
                phrases: [
                    'total purchase_order', 'purchase_order amount', 'purchase_order value',
                    'total po amount', 'po total this_month', 'is month purchase_order',
                    'november ka total purchase', 'total sum of po',
                ],
                patterns: [
                    '/purchase_order.*(total|amount|value|sum)/u',
                    '/(total|amount|value|sum).*purchase_order/u',
                    '/\bpo\b.*(total|amount|value)/u',
                ],
                tool: 'getPurchaseOrderSummary',
                defaults: ['period' => 'this_month', 'group_by' => 'none'],
                suggestions: ['Total PO amount this month', 'PO value last month', 'PO by vendor'],
                permission: 'purchase.view',
            ),
            new IntentDefinition(
                id: 'purchase_order.pending',
                type: 'analytics',
                label: 'Pending Purchase Order approvals',
                keywords: ['purchase_order', 'pending', 'approval', 'how_many'],
                phrases: ['pending purchase_order', 'pending po approval', 'po pending'],
                tool: 'getPurchaseOrderSummary',
                defaults: ['period' => 'this_month', 'pending_only' => true],
                suggestions: ['Pending PO approvals', 'Total PO amount this month'],
                permission: 'purchase.view',
            ),
            new IntentDefinition(
                id: 'purchase_order.count_today',
                type: 'analytics',
                label: 'PO count today',
                keywords: ['purchase_order', 'how_many', 'count', 'today', 'bane'],
                phrases: ['how_many purchase_order today', 'po today', 'aaj kitne po'],
                tool: 'getPurchaseOrderSummary',
                defaults: ['period' => 'today', 'group_by' => 'none'],
                permission: 'purchase.view',
            ),
            new IntentDefinition(
                id: 'purchase_order.by_vendor',
                type: 'analytics',
                label: 'PO by vendor',
                keywords: ['purchase_order', 'vendor', 'group_vendor', 'highest'],
                phrases: ['purchase_order by vendor', 'vendor wise po', 'which vendor highest'],
                tool: 'getPurchaseOrderSummary',
                defaults: ['period' => 'this_month', 'group_by' => 'vendor'],
                permission: 'purchase.view',
            ),
            new IntentDefinition(
                id: 'purchase_order.by_project',
                type: 'analytics',
                label: 'PO by project',
                keywords: ['purchase_order', 'project', 'group_project', 'highest'],
                phrases: ['purchase_order by project', 'project wise po', 'highest procurement'],
                tool: 'getPurchaseOrderSummary',
                defaults: ['period' => 'this_month', 'group_by' => 'project'],
                permission: 'purchase.view',
            ),
            new IntentDefinition(
                id: 'drawing.pending',
                type: 'analytics',
                label: 'Pending drawing approvals',
                keywords: ['drawing', 'pending', 'approval', 'how_many'],
                phrases: ['pending drawing', 'drawing pending approval', 'drawings waiting'],
                tool: 'getDrawingApprovalSummary',
                defaults: [],
                suggestions: ['Pending drawing approvals', 'How to approve a drawing?'],
                permission: 'drawings.view',
            ),
            new IntentDefinition(
                id: 'boq.summary',
                type: 'analytics',
                label: 'BOQ totals',
                keywords: ['boq', 'total', 'amount', 'value', 'summary'],
                phrases: ['total boq', 'boq value', 'approved boq', 'boq total'],
                tool: 'getBoqSummary',
                defaults: [],
                permission: 'boq.view',
            ),
            new IntentDefinition(
                id: 'inventory.summary',
                type: 'analytics',
                label: 'Inventory stock / valuation',
                keywords: ['inventory', 'valuation', 'quantity', 'available', 'current'],
                phrases: ['current inventory', 'inventory stock', 'stock valuation', 'available stock'],
                tool: 'getInventorySummary',
                defaults: [],
                permission: 'inventory.view',
            ),
            new IntentDefinition(
                id: 'finance.expenses',
                type: 'analytics',
                label: 'Expense / expenditure summary',
                keywords: ['expense', 'expenditure', 'total', 'project', 'kharcha'],
                phrases: ['project expenditure', 'total expenses', 'expense this_month'],
                tool: 'getExpenseSummary',
                defaults: ['period' => 'this_month'],
                permission: 'expenses.view',
            ),
            new IntentDefinition(
                id: 'finance.vendor_outstanding',
                type: 'analytics',
                label: 'Vendor outstanding payments',
                keywords: ['vendor', 'outstanding', 'payment', 'unpaid', 'due'],
                phrases: ['vendor outstanding', 'outstanding payments', 'unpaid invoices', 'baki payment'],
                tool: 'getVendorOutstanding',
                defaults: [],
                permission: 'vendor_bills.view',
            ),
            new IntentDefinition(
                id: 'projects.active',
                type: 'analytics',
                label: 'Active projects and milestones',
                keywords: ['project', 'active', 'milestone', 'progress', 'how_many'],
                phrases: ['active projects', 'upcoming milestones', 'project progress'],
                tool: 'getProjectSummary',
                defaults: [],
                permission: 'projects.view',
            ),
        ];
    }

    public function find(string $id): ?IntentDefinition
    {
        foreach ($this->all() as $intent) {
            if ($intent->id === $id) {
                return $intent;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function suggestionLabels(): array
    {
        return [
            'How to create BOQ?',
            'Total PO amount this month',
            'Pending drawing approvals',
            'Current inventory stock',
            'Vendor outstanding payments',
            'How to create GRN?',
        ];
    }
}
