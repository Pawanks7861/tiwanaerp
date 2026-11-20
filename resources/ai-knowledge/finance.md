# Finance

## Soft cutover (GRN-driven procurement value)

Client RA bills, Vendor Bills, Expenses, Petty Cash, and Payments are **soft-retired**: UI create/write routes and Site API writes are blocked. Historical rows, tables, and Tally mappers remain.

- **Procurement value** for Cost Control and related reports comes from **approved GRNs**: `accepted_qty × PO rate` (GRN rate as snapshot fallback).
- **Subcontract Bills** remain the only project AP bill UI (plus labour payments, unchanged).
- Retention screens remain for historical/subcontract use.

## Subcontract bills

1. Project → Subcontract → Work orders / Subcontractor bills.
2. Raise bills against certified quantities; certify and process as before.

## Reports

Cost Control “actual” for materials uses approved GRN accepted value. Subcontract certified bills still feed actuals. Do not create vendor bills or expenses for new material spend.

The Smart Assistant is read-only: it cannot approve payments or post invoices.
