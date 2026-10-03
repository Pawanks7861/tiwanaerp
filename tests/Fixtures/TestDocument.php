<?php

namespace Tests\Fixtures;

use App\Contracts\Approvable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasApprovals;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Minimal approvable document used to exercise the generic approval engine in tests.
 */
class TestDocument extends Model implements Approvable
{
    use BelongsToCompany, HasApprovals;

    public const MORPH = 'test_document';

    protected $table = 'test_documents';

    protected $fillable = ['project_id', 'title', 'amount', 'status'];

    public static function createTable(): void
    {
        Relation::morphMap([self::MORPH => self::class]);

        Schema::create('test_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id');
            $table->foreignId('project_id')->nullable();
            $table->string('title');
            $table->decimal('amount', 18, 2)->nullable();
            $table->string('status')->default('draft');
            $table->timestamps();
        });
    }

    public function approvalDocumentType(): string
    {
        return 'material_request';
    }

    public function approvalAmount(): ?string
    {
        return $this->amount;
    }

    public function approvalProjectId(): ?int
    {
        return $this->project_id;
    }

    public function approvalTitle(): string
    {
        return $this->title;
    }

    public function onApprovalSubmitted(): void
    {
        $this->forceFill(['status' => 'pending'])->save();
    }

    public function onApprovalCompleted(): void
    {
        $this->forceFill(['status' => 'approved'])->save();
    }

    public function onApprovalRejected(): void
    {
        $this->forceFill(['status' => 'rejected'])->save();
    }

    public function onApprovalSentBack(): void
    {
        $this->forceFill(['status' => 'draft'])->save();
    }

    public function onApprovalCancelled(): void
    {
        $this->forceFill(['status' => 'draft'])->save();
    }
}
