<?php

namespace App\Listeners;

use App\Events\Finance\ClientInvoiceCertified;
use App\Events\Finance\PaymentReceived;
use App\Events\Planning\TaskAssigned;
use App\Events\Quality\NcrRaised;
use App\Models\Projects\Project;
use App\Models\User;
use App\Notifications\GeneralNotification;
use App\Support\Notifications\PermissionRecipients;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Architecture J.4 recipients, resolved by permission or by the record itself, never by role name.
 * Task assigned → assignee. Invoice certified → accountant (payments.view) and the project manager.
 * Payment received → accountant and director (payments.view). NCR raised → responsible person and
 * the project manager.
 */
class SendOperationalNotifications
{
    public function __construct(private readonly PermissionRecipients $recipients) {}

    public function handleTaskAssigned(TaskAssigned $event): void
    {
        $task = $event->task;
        $this->send(
            $this->recipients->users((int) $task->company_id, [$task->assigned_to]),
            (int) $task->company_id,
            'planning.task_assigned',
            'Task assigned',
            "{$task->wbs_code} {$task->name} was assigned to you.",
            route('projects.planning.tasks.index', ['project' => $task->project_id], absolute: false),
            (int) $task->project_id,
        );
    }

    public function handleClientInvoiceCertified(ClientInvoiceCertified $event): void
    {
        $invoice = $event->invoice;
        $managerId = Project::query()->whereKey($invoice->project_id)->value('project_manager_id');
        $users = $this->recipients->in((int) $invoice->company_id, ['payments.view'])
            ->merge($this->recipients->users((int) $invoice->company_id, [$managerId]))
            ->unique('id');

        $this->send(
            $users,
            (int) $invoice->company_id,
            'finance.invoice_certified',
            'Client invoice certified',
            "{$invoice->invoice_number} was certified.",
            route('projects.ra-bills.show', [$invoice->project_id, $invoice->id], absolute: false),
            (int) $invoice->project_id,
        );
    }

    public function handlePaymentReceived(PaymentReceived $event): void
    {
        $payment = $event->payment;
        $this->send(
            $this->recipients->in((int) $payment->company_id, ['payments.view']),
            (int) $payment->company_id,
            'finance.payment_received',
            'Payment received',
            "Receipt {$payment->payment_number} for {$payment->amount} was approved.",
            route('projects.payments.show', [$payment->project_id, $payment->id], absolute: false),
            (int) $payment->project_id,
        );
    }

    public function handleNcrRaised(NcrRaised $event): void
    {
        $ncr = $event->ncr;
        $managerId = Project::query()->whereKey($ncr->project_id)->value('project_manager_id');
        $this->send(
            $this->recipients->users((int) $ncr->company_id, [$ncr->responsible_user_id, $managerId]),
            (int) $ncr->company_id,
            'quality.ncr_raised',
            'NCR raised',
            "{$ncr->ncr_number}: ".Str::limit($ncr->issue, 140),
            route('projects.ncrs.show', [$ncr->project_id, $ncr->id], absolute: false),
            (int) $ncr->project_id,
        );
    }

    /**
     * @param  Collection<int, User>  $users
     */
    private function send(Collection $users, int $companyId, string $kind, string $title, string $body, string $url, int $projectId): void
    {
        if ($users->isEmpty()) {
            return;
        }

        Notification::send($users, new GeneralNotification(
            $companyId, $kind, $title, $body, $url, ['project_id' => $projectId],
        ));
    }
}
