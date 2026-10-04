<?php

namespace App\Http\Controllers\Quality;

use App\Enums\Discipline;
use App\Http\Controllers\Controller;
use App\Models\Quality\QualityChecklist;
use App\Models\Quality\QualityChecklistItem;
use App\Models\User;
use App\Services\Quality\QualityChecklistService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class QualityChecklistController extends Controller
{
    public function __construct(private readonly QualityChecklistService $checklists) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', QualityChecklist::class);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'discipline' => ['nullable', Rule::enum(Discipline::class)],
            'active' => ['nullable', 'in:1,0'],
        ]);

        $page = QualityChecklist::query()
            ->withCount('items')
            ->search($filters['search'] ?? null)
            ->when($filters['discipline'] ?? null, fn ($q, $d) => $q->where('discipline', $d))
            ->when(isset($filters['active']), fn ($q) => $q->where('is_active', $filters['active'] === '1'))
            ->orderBy('name')
            ->paginate(25)->withQueryString();

        return Inertia::render('Quality/Checklists/Index', [
            'checklists' => $page->through(fn (QualityChecklist $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'discipline' => $c->discipline->value,
                'discipline_label' => $c->discipline->label(),
                'activity' => $c->activity,
                'is_active' => $c->is_active,
                'items_count' => $c->items_count,
            ]),
            'filters' => $filters,
            'disciplines' => Discipline::options(),
            'can' => ['create' => $request->user()->can('create', QualityChecklist::class)],
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', QualityChecklist::class);

        return $this->form(null, ['update' => true, 'delete' => false]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', QualityChecklist::class);

        $checklist = $this->checklists->create($this->validated($request));

        return redirect()->route('quality.checklists.show', $checklist)->with('success', "Checklist \"{$checklist->name}\" created.");
    }

    public function show(Request $request, QualityChecklist $checklist): Response
    {
        Gate::authorize('view', $checklist);

        return $this->form($checklist, $this->abilities($request->user(), $checklist));
    }

    public function update(Request $request, QualityChecklist $checklist): RedirectResponse
    {
        Gate::authorize('update', $checklist);

        $this->checklists->update($checklist, $this->validated($request));

        return back()->with('success', 'Checklist saved. Existing inspections keep the checkpoints they were created with.');
    }

    public function destroy(QualityChecklist $checklist): RedirectResponse
    {
        Gate::authorize('delete', $checklist);

        $this->checklists->delete($checklist);

        return redirect()->route('quality.checklists.index')->with('success', "Checklist \"{$checklist->name}\" deleted.");
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'discipline' => ['required', Rule::enum(Discipline::class)],
            'activity' => ['nullable', 'string', 'max:150'],
            'is_active' => ['boolean'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.id' => ['nullable', 'integer'],
            'items.*.checkpoint' => ['required', 'string', 'max:255'],
            'items.*.acceptance_criteria' => ['nullable', 'string', 'max:500'],
        ], [], ['items.*.checkpoint' => 'checkpoint', 'items.*.acceptance_criteria' => 'acceptance criteria']);
    }

    /**
     * @param  array<string, bool>  $can
     */
    private function form(?QualityChecklist $checklist, array $can): Response
    {
        return Inertia::render('Quality/Checklists/Form', [
            'checklist' => $checklist ? [
                'id' => $checklist->id,
                'name' => $checklist->name,
                'discipline' => $checklist->discipline->value,
                'activity' => $checklist->activity,
                'is_active' => $checklist->is_active,
                'in_use' => $checklist->isInUse(),
                'items' => $checklist->items()->get()->map(fn (QualityChecklistItem $i) => $i->only(['id', 'checkpoint', 'acceptance_criteria']))->all(),
            ] : null,
            'disciplines' => Discipline::options(),
            'can' => $can,
        ]);
    }

    /**
     * @return array<string, bool>
     */
    private function abilities(User $user, QualityChecklist $checklist): array
    {
        return [
            'update' => $user->can('update', $checklist),
            'delete' => ! $checklist->isInUse() && $user->can('delete', $checklist),
        ];
    }
}
