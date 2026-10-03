<?php

/*
 * Default document number formats (architecture J.2). Companies can override any of these in
 * document_number_formats. Tokens: {PROJECT_CODE} {YYYY} {YY} {MM} {FY} {DATE:<php format>} {SEQ:<padding>}.
 * A pattern containing {PROJECT_CODE} gets a separate sequence per project.
 */
return [

    'formats' => [
        'project' => ['pattern' => 'PRJ-{YYYY}-{SEQ:4}', 'reset' => 'never', 'assign_on' => 'create'],
        'project_code' => ['pattern' => 'PRJ{SEQ:3}', 'reset' => 'never', 'assign_on' => 'create'],

        'material' => ['pattern' => 'ITM-{SEQ:5}', 'reset' => 'never', 'assign_on' => 'create'],
        'vendor' => ['pattern' => 'VEN-{SEQ:4}', 'reset' => 'never', 'assign_on' => 'create'],
        'subcontractor' => ['pattern' => 'SUB-{SEQ:4}', 'reset' => 'never', 'assign_on' => 'create'],
        'client' => ['pattern' => 'CLI-{SEQ:4}', 'reset' => 'never', 'assign_on' => 'create'],
        'warehouse' => ['pattern' => 'WH-{SEQ:3}', 'reset' => 'never', 'assign_on' => 'create'],

        'boq' => ['pattern' => 'BOQ-{PROJECT_CODE}-{SEQ:3}', 'reset' => 'never', 'assign_on' => 'create'],
        'rate_analysis' => ['pattern' => 'RA-{SEQ:4}', 'reset' => 'never', 'assign_on' => 'create'],

        'material_request' => ['pattern' => 'MR-{PROJECT_CODE}-{SEQ:4}', 'reset' => 'never', 'assign_on' => 'create'],
        'rfq' => ['pattern' => 'RFQ-{PROJECT_CODE}-{SEQ:4}', 'reset' => 'never', 'assign_on' => 'create'],
        'purchase_order' => ['pattern' => 'PO-{PROJECT_CODE}-{SEQ:4}', 'reset' => 'never', 'assign_on' => 'create'],
        'grn' => ['pattern' => 'GRN-{PROJECT_CODE}-{SEQ:4}', 'reset' => 'never', 'assign_on' => 'create'],
        'material_issue' => ['pattern' => 'ISS-{PROJECT_CODE}-{SEQ:4}', 'reset' => 'never', 'assign_on' => 'create'],
        'stock_transfer' => ['pattern' => 'STR-{PROJECT_CODE}-{SEQ:4}', 'reset' => 'never', 'assign_on' => 'create'],
        'material_return' => ['pattern' => 'RET-{PROJECT_CODE}-{SEQ:4}', 'reset' => 'never', 'assign_on' => 'create'],
        'stock_adjustment' => ['pattern' => 'ADJ-{PROJECT_CODE}-{SEQ:4}', 'reset' => 'never', 'assign_on' => 'create'],
        'work_order' => ['pattern' => 'WO-{PROJECT_CODE}-{SEQ:4}', 'reset' => 'never', 'assign_on' => 'create'],
        'subcontractor_bill' => ['pattern' => 'SCB-{PROJECT_CODE}-{SEQ:4}', 'reset' => 'never', 'assign_on' => 'create'],
        'labour_payment' => ['pattern' => 'LP-{PROJECT_CODE}-{SEQ:4}', 'reset' => 'never', 'assign_on' => 'create'],
        'labour' => ['pattern' => 'LAB-{SEQ:4}', 'reset' => 'never', 'assign_on' => 'create'],
        'equipment' => ['pattern' => 'EQP-{SEQ:4}', 'reset' => 'never', 'assign_on' => 'create'],
        'client_invoice' => ['pattern' => 'INV-{PROJECT_CODE}-{SEQ:4}', 'reset' => 'never', 'assign_on' => 'approve'],
        'dpr' => ['pattern' => 'DPR-{PROJECT_CODE}-{DATE:Ymd}', 'reset' => 'never', 'assign_on' => 'create'],

        'expense' => ['pattern' => 'EXP-{PROJECT_CODE}-{SEQ:4}', 'reset' => 'never', 'assign_on' => 'create'],
        'vendor_bill' => ['pattern' => 'VB-{PROJECT_CODE}-{SEQ:4}', 'reset' => 'never', 'assign_on' => 'create'],
        'payment_receipt' => ['pattern' => 'RCPT-{PROJECT_CODE}-{SEQ:4}', 'reset' => 'never', 'assign_on' => 'create'],
        'payment' => ['pattern' => 'PAY-{PROJECT_CODE}-{SEQ:4}', 'reset' => 'never', 'assign_on' => 'create'],
        'retention_release' => ['pattern' => 'RTR-{PROJECT_CODE}-{SEQ:4}', 'reset' => 'never', 'assign_on' => 'create'],
        'lead' => ['pattern' => 'LEAD-{YYYY}-{SEQ:4}', 'reset' => 'yearly', 'assign_on' => 'create'],
        'quotation' => ['pattern' => 'QTN-{YYYY}-{SEQ:4}', 'reset' => 'yearly', 'assign_on' => 'create'],
    ],

];
