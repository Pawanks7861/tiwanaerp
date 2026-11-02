# Procurement

## Purchase Request (Material Request)
1. Project → Procurement → Material Requests → New.
2. Add lines (material or free text). Tag BOQ items when required.
3. Submit for approval. Rates are not entered on PRs.

## Purchase Order
1. Create from approved PR / comparison flow, or as allowed by permissions.
2. Draft → Submit → Approve.
3. Amendments require reasons and keep history.
4. Draft, rejected and cancelled POs are excluded from committed PO totals.
5. Committed totals use the PO header `grand_total` and document `po_date`.
6. Pending approvals are POs still in **submitted** status.

## How to approve a Purchase Order
Open Approvals or the PO document → Review lines and rates → Approve (requires `purchase.approve`).

## GRN
1. Open Procurement → GRN → Create against an approved / partially received PO.
2. Enter received quantities → Save → Submit / post per workflow.
3. Stock posts only through the GRN lifecycle — never by editing inventory rows directly.

## Excel imports
Materials, vendors, PR lines (draft), opening stock, expenses and other masters support Import + Download Template. Drawings do **not** use spreadsheet import.
