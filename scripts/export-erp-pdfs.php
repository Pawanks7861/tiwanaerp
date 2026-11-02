<?php

/**
 * Write PDF copies of the ERP flowchart/guide and the role-permission matrix.
 */

declare(strict_types=1);

use App\Enums\ProjectRole;
use App\Support\Permissions\DefaultRoles;
use App\Support\Permissions\PermissionCatalog;
use Dompdf\Dompdf;
use Dompdf\Options;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

ini_set('memory_limit', '512M');

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function renderPdf(string $html, string $paper, string $orientation, string $path): void
{
    $options = new Options;
    $options->set('isRemoteEnabled', false);
    $options->set('isHtml5ParserEnabled', true);
    $options->set('defaultFont', 'DejaVu Sans');

    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper($paper, $orientation);
    $dompdf->render();
    file_put_contents($path, $dompdf->output());
}

$css = <<<'CSS'
  * { box-sizing: border-box; }
  body { font-family: DejaVu Sans, sans-serif; font-size: 9pt; color: #0f172a; }
  h1 { font-size: 18pt; margin: 0 0 6px; }
  h2 { font-size: 13pt; margin: 16px 0 8px; border-bottom: 1px solid #cbd5e1; padding-bottom: 4px; }
  h3 { font-size: 11pt; margin: 12px 0 6px; }
  p, li { line-height: 1.4; }
  .muted { color: #475569; font-size: 8.5pt; }
  .cover { margin-bottom: 18px; }
  .phase { width: 100%; border-collapse: collapse; margin: 6px 0 12px; }
  .phase td { border: 1px solid #cbd5e1; padding: 6px 8px; vertical-align: top; }
  .phase .title { width: 22%; background: #1e3a5f; color: #fff; font-weight: bold; }
  .box { display: inline-block; border: 1px solid #1e3a5f; padding: 3px 7px; margin: 2px 2px 4px 0; background: #f8fafc; font-size: 8pt; }
  .arrow { color: #64748b; }
  table.grid { width: 100%; border-collapse: collapse; margin: 8px 0 14px; }
  table.grid th { background: #1e3a5f; color: #fff; font-size: 7.5pt; padding: 5px 4px; text-align: left; }
  table.grid td { border: 1px solid #cbd5e1; padding: 4px 5px; font-size: 7.5pt; vertical-align: top; }
  table.grid tr:nth-child(even) td { background: #f8fafc; }
  .yes { background: #d1fae5; color: #065f46; font-weight: bold; text-align: center; }
  .no { text-align: center; color: #94a3b8; }
  .legend span { display: inline-block; margin-right: 10px; }
  .note { background: #eef2ff; border: 1px solid #c7d2fe; padding: 8px 10px; margin: 8px 0 14px; font-size: 8.5pt; }
  .page-break { page-break-before: always; }
  ol { margin: 4px 0 8px 18px; padding: 0; }
CSS;

$today = now()->toDateString();

$guideHtml = '<html><head><meta charset="UTF-8"><style>'.$css.'</style></head><body>';
$guideHtml .= '<div class="cover"><h1>Tiwana ERP — complete guide</h1>';
$guideHtml .= '<p class="muted">Flowchart, user path, approvals, ledgers, roles. Generated '.$today.' from the live catalog.</p></div>';

$guideHtml .= '<div class="note">A user logs into one company. A <b>company role</b> unlocks menus. A <b>project role</b> unlocks that job and is who a workflow means by “Project Manager of this project”. Super Admin is a user flag, not a role. Typical document path: draft → submit → approve.</div>';

$guideHtml .= '<h2>1. Whole-ERP flowchart</h2>';

$phases = [
    ['1. Company setup', 'Login → Company, users, roles → Approval workflows → Masters (items, vendors, tax, warehouses, clients)'],
    ['2. Win the job', 'Lead → Quotation → Project + sites + team'],
    ['3. Price and plan', 'Drawings (R0, R1…) → BOQ + rate analysis → Budget → Tasks / Gantt'],
    ['4. Buy and store', 'Material request → RFQ → Vendor quotes → Bid comparison → Purchase order → GRN (stock in) → Issue to site (cost in)'],
    ['5. Execute on site', 'Site diary → one DPR per project per day → Progress on BOQ. Parallel: labour, work orders, equipment, quality / NCR'],
    ['6. Bill and report', 'Client RA bill, vendor bill (PO+GRN match), subcontractor bill, payments, retention → Reports read the ledgers'],
];

$guideHtml .= '<table class="phase">';
foreach ($phases as $phase) {
    $guideHtml .= '<tr><td class="title">'.e($phase[0]).'</td><td>'.e($phase[1]).'</td></tr>';
}
$guideHtml .= '</table>';

$guideHtml .= '<p>';
foreach (['Login', 'Company', 'Masters', 'CRM', 'Project', 'Drawings', 'BOQ', 'Planning', 'Procurement', 'Inventory', 'Site', 'Resources', 'Quality', 'Finance', 'Reports'] as $i => $box) {
    if ($i > 0) {
        $guideHtml .= ' <span class="arrow">→</span> ';
    }
    $guideHtml .= '<span class="box">'.e($box).'</span>';
}
$guideHtml .= '</p>';

$guideHtml .= '<h2>2. Spine every job follows</h2>';
$guideHtml .= '<table class="grid"><thead><tr><th>Order</th><th>User action</th><th>Must already exist</th><th>What becomes true</th></tr></thead><tbody>';
$spine = [
    ['1', 'Admin sets users, roles, workflows, masters', 'Company + Super Admin or Company Admin', 'People can log in; documents have items, vendors, tax'],
    ['2', 'CRM wins a client and opens a project', 'Client master', 'A job with a code, team, and sites'],
    ['3', 'Drawings registered and approved', 'Project + drawings permission', 'A quantity source for BOQ'],
    ['4', 'BOQ priced, approved, budget taken', 'Items, rates, drawings', 'Locked baseline; later bills use line_uid'],
    ['5', 'Planning breaks BOQ into tasks', 'Approved BOQ (typical)', 'Gantt and progress targets'],
    ['6', 'Site requests material; purchase buys; store receives', 'BOQ/task + vendors + warehouses', 'Approved GRN increases stock'],
    ['7', 'Store issues to site', 'Stock from GRN', 'Stock down; project material cost up'],
    ['8', 'Diaries → one DPR per day → progress', 'Tasks', 'Executed quantity vs BOQ'],
    ['9', 'Labour, work orders, equipment in parallel', 'Trades, subcontractors, equipment', 'Actual resource cost'],
    ['10', 'Quality inspects; NCRs close defects', 'Checklists', 'Pass/fail record, no money movement'],
    ['11', 'Client RA bill, vendor bill, payments', 'Progress + GRN + work order', 'Receivable, payable, cash, retention'],
    ['12', 'Reports and dashboard', 'Posted documents', 'No new postings'],
];
foreach ($spine as $row) {
    $guideHtml .= '<tr><td>'.e($row[0]).'</td><td>'.e($row[1]).'</td><td>'.e($row[2]).'</td><td>'.e($row[3]).'</td></tr>';
}
$guideHtml .= '</tbody></table>';

$guideHtml .= '<h2>3. Detailed user path by stage</h2>';

$stages = [
    ['Login', 'Any active company member', 'Sign in. Session is bound to one company. Super Admin bypasses permission checks; others use company roles.', ['Open login and sign in (2FA if enabled).', 'Server requires an active company.', 'Sidebar shows only modules the user can view.', 'Project pages also need project team membership or projects.view_all.'], 'Session and permissions.', 'None.'],
    ['Company and users', 'Company Admin, Super Admin', 'Users, roles, approval workflows, numbering, GST, Tally mapping.', ['Invite staff under Administration → Users.', 'Assign company roles (Director, PM, Purchase, Store, Accountant, …).', 'Set Approval Workflows: named user, company role, or project role per process.', 'Company settings, financial year, document numbers, optional Tally ledgers.'], 'Users, roles, workflows. No cost or stock.', 'Editing a workflow does not rewrite documents already submitted.'],
    ['Masters', 'Company Admin, Purchase Manager, Accountant', 'Catalogues later documents point at.', ['Units, tax rates, categories, items.', 'Vendors, subcontractors, clients, trades, equipment types, warehouses, expense categories.', 'Deactivate instead of deleting once documents exist.'], 'Master records only.', 'None by default.'],
    ['CRM', 'Director, Company Admin, CRM users', 'Lead → quotation → project.', ['Create lead (new → contacted → qualified).', 'Quotation draft → sent; Director can approve.', 'Accepted quote can convert to a project with client and contract value.'], 'Lead, quotation, optional project.', 'Quotations can use a workflow if configured.'],
    ['Project', 'Company Admin, Director, Project Manager', 'Open the job, sites, and team.', ['Create/open project (planning → active → hold / completed / closed).', 'Add sites and assign project roles: manager, engineer, purchase, store, billing, quality, accounts, viewer.', 'Company permission unlocks menus; project role unlocks the job and resolves “project manager of this project”.'], 'Project, sites, team.', 'None to open a project.'],
    ['Drawings', 'PM, Site Engineer, Quality Engineer', 'Register drawings. Every revision is kept.', ['Register number, title, discipline.', 'Upload R0 then R1, R2. Files are never overwritten.', 'Review/approve. Approving supersedes the previous revision.', 'BOQ can be built from approved drawing quantities.'], 'Drawing + immutable revisions.', 'Drawing review flow, not the generic engine.'],
    ['BOQ and budget', 'Billing Engineer, PM, Director (approve)', 'Price the job, then lock a budget.', ['Create BOQ, add sections/items, import, or pick approved drawings.', 'Attach rate analyses (material, labour, equipment, subcontract, overhead, profit).', 'Submit and approve. Approved BOQ is locked; revise clones version N+1.', 'Create a budget from the approved BOQ. Downstream bills keep line_uid.'], 'BOQ totals and budget version.', 'Default: Project Manager then Director. Configurable, including a specific user.'],
    ['Planning', 'Project Manager, Site Engineer', 'WBS, tasks, milestones, Gantt.', ['Create milestones and tasks. Link a task to a BOQ item.', 'Set dates, assignees, dependencies (FS/SS/FF/SF).', 'Site progress later updates the same task. There is no second activity table.'], 'Plan only. Progress posts when DPR is approved.', 'None for creating tasks.'],
    ['Procurement', 'Site (request), Purchase (buy), PM/Director (approve)', 'MR → RFQ → quotes → bid → PO → GRN.', ['Site raises material request against BOQ/task.', 'Purchase sends RFQ, collects quotes, records bid comparison.', 'PO from selected quote. GST intra vs inter is computed, not chosen.', 'Store receives GRN (received − rejected = accepted).'], 'Approved GRN posts stock in. PO does not move stock.', 'Default: MR PM→Purchase; PO PM→Director; GRN PM.'],
    ['Inventory', 'Store Manager, PM (adjustments)', 'Issue, transfer, return, adjust.', ['Issue to project/task is when material becomes project cost.', 'Transfer: draft → dispatched → received.', 'Return to store or vendor.', 'Adjustments need approval. Site diary material use is informational only.'], 'Stock ledger. Issue also posts project material cost.', 'Adjustments require approval.'],
    ['Site execution', 'Site Engineer, Project Manager', 'Diaries roll into one DPR per project per day.', ['Several engineers can file diaries (work, labour, equipment, photos, GPS).', 'One DPR per project per day, filled from approved diaries.', 'Approve DPR → progress_entries on tasks / BOQ line_uid.'], 'Progress entries. Diary material lines do not move stock.', 'Diary: submit → review → approve. DPR: submit → approve.'],
    ['Labour, subcontract, equipment', 'PM, Site Engineer, Billing Engineer, Accountant', 'People, work orders, machines.', ['Labour register → attendance → period payment → approve → pay.', 'Work order against BOQ lines; subcontractor bill certifies qty, holds retention.', 'Assign equipment, log usage and fuel; repairs can link to an expense.'], 'Approved payments, certified bills, usage feed cost ledger.', 'WO, subcontractor bills, labour payments use workflows when configured.'],
    ['Quality', 'Quality Engineer, Site Engineer, PM', 'Checklists, inspections, NCR.', ['Company checklists.', 'Inspection on location/task/BOQ item: pass/fail/NA.', 'Failed work can raise NCR; close and verify.'], 'Inspection and NCR only. No stock or cost.', 'NCR close/verify is a quality state machine.'],
    ['Documents', 'Project members with documents permission', 'Folders and immutable versions.', ['Create folders, upload. Later uploads are version N+1.', 'Nothing is overwritten.'], 'Document + versions on private storage.', 'Not the generic approval engine.'],
    ['Finance', 'Accountant, Billing Engineer, Director', 'Expenses, RA bills, vendor bills, payments, retention.', ['Expense / petty cash → approve → cost.', 'Client RA bill from BOQ progress (previous + current + cumulative).', 'Vendor bill 3-way match PO ↔ GRN ↔ invoice.', 'Payment or receipt allocated to a bill. Retention release is its own document.', 'Optional TallyPrime sync of approved vouchers.'], 'Cost ledger, receivables, payables, cash.', 'Expense, bills, payment, retention each have a workflow.'],
    ['Reports', 'Director, PM, Accountant (financials need view_financial)', 'Read ledgers already posted.', ['Company dashboard: projects, approvals, budget vs actual, cash.', 'Project reports: progress, stock, cost, billing.', 'Export PDF/Excel where permitted. Costs stay hidden without view_costs / view_financial.'], 'Nothing. Reports only read.', 'None.'],
];

foreach ($stages as $stage) {
    $guideHtml .= '<h3>'.e($stage[0]).'</h3>';
    $guideHtml .= '<p class="muted">Who: '.e($stage[1]).'</p>';
    $guideHtml .= '<p>'.e($stage[2]).'</p><ol>';
    foreach ($stage[3] as $step) {
        $guideHtml .= '<li>'.e($step).'</li>';
    }
    $guideHtml .= '</ol><p class="muted">Posts: '.e($stage[4]).' Approval: '.e($stage[5]).'</p>';
}

$guideHtml .= '<h2>4. Two ledgers reports read</h2>';
$guideHtml .= '<table class="grid"><thead><tr><th>Ledger</th><th>Written when</th><th>Read by</th></tr></thead><tbody>';
$guideHtml .= '<tr><td>Stock ledger (warehouse + item)</td><td>Approved GRN, issue, transfer, return, adjustment</td><td>Inventory, stock reports, vendor bill match</td></tr>';
$guideHtml .= '<tr><td>Project cost ledger (cost head)</td><td>Material issue, approved expense, labour, equipment, certified subcontractor bill</td><td>Budget vs actual, dashboards, financial reports</td></tr>';
$guideHtml .= '<tr><td>Progress entries</td><td>Approved DPR</td><td>Progress %, client RA quantities, delay days</td></tr>';
$guideHtml .= '</tbody></table>';

$guideHtml .= '<h2>5. Default approval chain</h2>';
$guideHtml .= '<p class="muted">Change these under Administration → Approval Workflows. In-flight documents keep the snapshot taken at submit.</p>';
$guideHtml .= '<table class="grid"><thead><tr><th>Process</th><th>Default steps</th><th>Typical user</th></tr></thead><tbody>';
$approvals = [
    ['Material request', 'Project Manager → Purchase Manager', 'Site then purchase'],
    ['Purchase order', 'Project Manager → Director', 'Purchase then director'],
    ['GRN / issue / return / DPR', 'Project Manager', 'Store / site'],
    ['BOQ, work order, bills, retention', 'Project Manager → Director', 'Billing / accounts'],
    ['Expense', 'Project Manager → Accountant', 'Site then accounts'],
];
foreach ($approvals as $row) {
    $guideHtml .= '<tr><td>'.e($row[0]).'</td><td>'.e($row[1]).'</td><td>'.e($row[2]).'</td></tr>';
}
$guideHtml .= '</tbody></table>';

$guideHtml .= '<h2>6. Company roles</h2>';
$guideHtml .= '<table class="grid"><thead><tr><th>Role</th><th>Type</th><th>Description</th></tr></thead><tbody>';
$guideHtml .= '<tr><td>Super Admin</td><td>User flag, not a company role</td><td>Bypasses every Gate check. Still needs an active company.</td></tr>';
foreach (DefaultRoles::definitions() as $name => $def) {
    $guideHtml .= '<tr><td>'.e($name).'</td><td>Company Spatie role</td><td>'.e($def['description']).'</td></tr>';
}
$guideHtml .= '</tbody></table>';

$guideHtml .= '<h2>7. Project roles</h2>';
$guideHtml .= '<p class="muted">Stored on the project team. They do not replace company permissions.</p>';
$guideHtml .= '<table class="grid"><thead><tr><th>Key</th><th>Label</th></tr></thead><tbody>';
foreach (ProjectRole::cases() as $case) {
    $guideHtml .= '<tr><td>'.e($case->value).'</td><td>'.e($case->label()).'</td></tr>';
}
$guideHtml .= '</tbody></table>';

$guideHtml .= '<p class="muted">Hiding a button in the UI is not security. The server re-checks permission, company, and project on every save. Drawings and quotations have their own approve/revise flows.</p>';
$guideHtml .= '</body></html>';

$modules = PermissionCatalog::modules();
$allPermissions = PermissionCatalog::all();
$roleDefinitions = DefaultRoles::definitions();
$roleNames = array_keys($roleDefinitions);
$grantedByRole = [];
foreach ($roleDefinitions as $role => $definition) {
    $grantedByRole[$role] = array_fill_keys(PermissionCatalog::expand($definition['permissions']), true);
}

$short = [
    'Super Admin' => 'SA',
    'Company Admin' => 'CA',
    'Director' => 'Dir',
    'Project Manager' => 'PM',
    'Site Engineer' => 'SE',
    'Purchase Manager' => 'Pur',
    'Store Manager' => 'Sto',
    'Accountant' => 'Acc',
    'Billing Engineer' => 'BE',
    'Quality Engineer' => 'QE',
];

$matrixHtml = '<html><head><meta charset="UTF-8"><style>'.$css.' table.grid td, table.grid th { font-size: 6.5pt; padding: 3px 3px; } h1 { font-size: 14pt; }</style></head><body>';
$matrixHtml .= '<h1>Tiwana ERP — role and permission matrix</h1>';
$matrixHtml .= '<p class="muted">Yes = granted by the default seeded definition. Blank = not granted unless a company admin adds it. Super Admin (SA) is a user flag and always Yes. Generated '.$today.'. '.count($allPermissions).' permissions, '.count($roleNames).' company roles.</p>';
$matrixHtml .= '<p class="legend muted">';
$matrixHtml .= '<span>SA Super Admin</span><span>CA Company Admin</span><span>Dir Director</span><span>PM Project Manager</span><span>SE Site Engineer</span>';
$matrixHtml .= '<span>Pur Purchase Manager</span><span>Sto Store Manager</span><span>Acc Accountant</span><span>BE Billing Engineer</span><span>QE Quality Engineer</span>';
$matrixHtml .= '</p>';

$matrixHtml .= '<table class="grid"><thead><tr><th>Group</th><th>Module</th><th>Permission</th><th>Action</th><th>SA</th>';
foreach ($roleNames as $role) {
    $matrixHtml .= '<th>'.e($short[$role] ?? $role).'</th>';
}
$matrixHtml .= '</tr></thead><tbody>';

$actionLabel = static fn (string $action): string => ucfirst(str_replace('_', ' ', $action));

foreach ($modules as $module => $definition) {
    foreach ($definition['actions'] as $action) {
        $permission = "{$module}.{$action}";
        $matrixHtml .= '<tr><td>'.e($definition['group']).'</td><td>'.e($definition['label']).'</td><td>'.e($permission).'</td><td>'.e($actionLabel($action)).'</td>';
        $matrixHtml .= '<td class="yes">Yes</td>';
        foreach ($roleNames as $role) {
            if (isset($grantedByRole[$role][$permission])) {
                $matrixHtml .= '<td class="yes">Yes</td>';
            } else {
                $matrixHtml .= '<td class="no"></td>';
            }
        }
        $matrixHtml .= '</tr>';
    }
}
$matrixHtml .= '</tbody></table>';
$matrixHtml .= '<p class="muted">Permissions are named {module}.{action} and are global. Roles that hold them are per company. A user still needs project membership (or projects.view_all) to open a project.</p>';
$matrixHtml .= '</body></html>';

$docs = dirname(__DIR__).DIRECTORY_SEPARATOR.'docs';
$guidePath = $docs.DIRECTORY_SEPARATOR.'Tiwana-ERP-Complete-Guide.pdf';
$matrixPath = $docs.DIRECTORY_SEPARATOR.'Tiwana-ERP-Role-Permission-Matrix.pdf';

echo "Writing guide...\n";
renderPdf($guideHtml, 'a4', 'portrait', $guidePath);
echo "Writing matrix...\n";
renderPdf($matrixHtml, 'a3', 'landscape', $matrixPath);

$downloads = getenv('USERPROFILE').DIRECTORY_SEPARATOR.'Downloads';
if (is_dir($downloads)) {
    copy($guidePath, $downloads.DIRECTORY_SEPARATOR.'Tiwana-ERP-Complete-Guide.pdf');
    copy($matrixPath, $downloads.DIRECTORY_SEPARATOR.'Tiwana-ERP-Role-Permission-Matrix.pdf');
    echo "Copied to Downloads\n";
}

echo $guidePath.PHP_EOL;
echo $matrixPath.PHP_EOL;
echo 'guide_bytes='.filesize($guidePath).' matrix_bytes='.filesize($matrixPath).PHP_EOL;
