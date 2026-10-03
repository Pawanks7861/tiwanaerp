<?php

namespace App\Contracts;

/**
 * A document that goes through the generic approval engine (architecture J.1).
 * The hooks are called by ApprovalService inside its transaction; the document's own service
 * logic (status change, locking, ledger posting) belongs in these hooks or in event listeners.
 */
interface Approvable
{
    /** Key used to select the approval workflow, e.g. "purchase_order". */
    public function approvalDocumentType(): string;

    /** Decimal string used to match workflow amount ranges, or null when not amount-based. */
    public function approvalAmount(): ?string;

    /**
     * Project used to resolve project-role approvers (e.g. that project's manager). An ID rather
     * than a relation, so inbox listings of many documents never lazy-load.
     */
    public function approvalProjectId(): ?int;

    /** Human label shown in inboxes and notifications, e.g. the document number. */
    public function approvalTitle(): string;

    public function onApprovalSubmitted(): void;

    public function onApprovalCompleted(): void;

    public function onApprovalRejected(): void;

    public function onApprovalSentBack(): void;

    public function onApprovalCancelled(): void;
}
