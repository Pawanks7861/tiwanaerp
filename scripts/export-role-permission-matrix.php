<?php

/**
 * Build docs/Tiwana-ERP-Role-Permission-Matrix.xlsx from PermissionCatalog and DefaultRoles.
 */

declare(strict_types=1);

use App\Enums\ProjectRole;
use App\Support\Permissions\DefaultRoles;
use App\Support\Permissions\PermissionCatalog;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$modules = PermissionCatalog::modules();
$allPermissions = PermissionCatalog::all();
$roleDefinitions = DefaultRoles::definitions();
$roleNames = array_keys($roleDefinitions);

$grantedByRole = [];
foreach ($roleDefinitions as $role => $definition) {
    $grantedByRole[$role] = array_fill_keys(PermissionCatalog::expand($definition['permissions']), true);
}

$superAdmin = 'Super Admin';
$roleColumns = [$superAdmin, ...$roleNames];

$spreadsheet = new Spreadsheet();
$spreadsheet->getProperties()
    ->setTitle('Tiwana ERP Role and Permission Matrix')
    ->setCreator('Tiwana ERP')
    ->setDescription('Company roles vs catalog permissions. Super Admin is a user flag, not a Spatie role.');

$navy = '1E3A5F';
$headerFill = [
    'fillType' => Fill::FILL_SOLID,
    'startColor' => ['rgb' => $navy],
];
$headerFont = ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 10];
$yesFill = [
    'fillType' => Fill::FILL_SOLID,
    'startColor' => ['rgb' => 'D1FAE5'],
];
$groupFill = [
    'fillType' => Fill::FILL_SOLID,
    'startColor' => ['rgb' => 'EEF2FF'],
];
$thin = [
    'borders' => [
        'allBorders' => [
            'borderStyle' => Border::BORDER_THIN,
            'color' => ['rgb' => 'CBD5E1'],
        ],
    ],
];

$actionLabel = static function (string $action): string {
    return ucfirst(str_replace('_', ' ', $action));
};

$matrix = $spreadsheet->getActiveSheet();
$matrix->setTitle('Permission matrix');

$headers = ['Group', 'Module', 'Permission', 'Action', ...$roleColumns];
$matrix->fromArray($headers, null, 'A1');

$row = 2;
foreach ($modules as $module => $definition) {
    foreach ($definition['actions'] as $action) {
        $permission = "{$module}.{$action}";
        $values = [
            $definition['group'],
            $definition['label'],
            $permission,
            $actionLabel($action),
            'Yes',
        ];
        foreach ($roleNames as $role) {
            $values[] = isset($grantedByRole[$role][$permission]) ? 'Yes' : '';
        }
        $matrix->fromArray($values, null, "A{$row}");
        $row++;
    }
}

$lastRow = $row - 1;
$lastCol = Coordinate::stringFromColumnIndex(count($headers));
$lastRoleCol = Coordinate::stringFromColumnIndex(count($headers));

$matrix->freezePane('E2');
$matrix->setAutoFilter("A1:{$lastCol}{$lastRow}");
$matrix->getStyle("A1:{$lastCol}1")->applyFromArray([
    'fill' => $headerFill,
    'font' => $headerFont,
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'wrapText' => true, 'vertical' => Alignment::VERTICAL_CENTER],
]);
$matrix->getRowDimension(1)->setRowHeight(32);
$matrix->getStyle("A2:{$lastCol}{$lastRow}")->applyFromArray($thin);
$matrix->getStyle("A2:D{$lastRow}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
$matrix->getStyle("E2:{$lastCol}{$lastRow}")
    ->getAlignment()
    ->setHorizontal(Alignment::HORIZONTAL_CENTER)
    ->setVertical(Alignment::VERTICAL_CENTER);

foreach (range(5, count($headers)) as $colIndex) {
    $col = Coordinate::stringFromColumnIndex($colIndex);
    for ($r = 2; $r <= $lastRow; $r++) {
        if ($matrix->getCell("{$col}{$r}")->getValue() === 'Yes') {
            $matrix->getStyle("{$col}{$r}")->applyFromArray([
                'fill' => $yesFill,
                'font' => ['color' => ['rgb' => '065F46'], 'bold' => true],
            ]);
        }
    }
}

$matrix->getColumnDimension('A')->setWidth(22);
$matrix->getColumnDimension('B')->setWidth(24);
$matrix->getColumnDimension('C')->setWidth(36);
$matrix->getColumnDimension('D')->setWidth(22);
foreach (range(5, count($headers)) as $colIndex) {
    $matrix->getColumnDimension(Coordinate::stringFromColumnIndex($colIndex))->setWidth(16);
}
$matrix->setAutoFilter("A1:{$lastCol}{$lastRow}");
$matrix->getSheetView()->setZoomScale(90);
$matrix->getPageSetup()->setFitToWidth(1)->setFitToHeight(0)->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE);

$rolesSheet = $spreadsheet->createSheet();
$rolesSheet->setTitle('Roles');
$rolesSheet->fromArray(['Role', 'Type', 'Description', 'Permissions granted', 'Total catalog permissions'], null, 'A1');

$catalogCount = count($allPermissions);
$roleRows = [
    [$superAdmin, 'Platform user flag (not a company role)', 'Bypasses every Gate check. Not seeded as a Spatie role.', $catalogCount, $catalogCount],
];
foreach ($roleDefinitions as $role => $definition) {
    $roleRows[] = [
        $role,
        'Company Spatie role (per company)',
        $definition['description'],
        count($grantedByRole[$role]),
        $catalogCount,
    ];
}
$rolesSheet->fromArray($roleRows, null, 'A2');
$rolesLast = 1 + count($roleRows);
$rolesSheet->getStyle('A1:E1')->applyFromArray(['fill' => $headerFill, 'font' => $headerFont]);
$rolesSheet->getStyle("A1:E{$rolesLast}")->applyFromArray($thin);
$rolesSheet->freezePane('A2');
foreach (['A' => 22, 'B' => 42, 'C' => 62, 'D' => 22, 'E' => 26] as $col => $width) {
    $rolesSheet->getColumnDimension($col)->setWidth($width);
}
$rolesSheet->getStyle("C2:C{$rolesLast}")->getAlignment()->setWrapText(true);
$rolesSheet->getStyle("A2:E{$rolesLast}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);

$projectSheet = $spreadsheet->createSheet();
$projectSheet->setTitle('Project roles');
$projectSheet->fromArray(['Project role (key)', 'Label', 'Notes'], null, 'A1');
$projectRows = [];
foreach (ProjectRole::cases() as $case) {
    $projectRows[] = [
        $case->value,
        $case->label(),
        'Stored on project_users. Scopes which projects a user can open and which project-role approver a workflow step resolves to. It does not replace company permissions.',
    ];
}
$projectSheet->fromArray($projectRows, null, 'A2');
$projectLast = 1 + count($projectRows);
$projectSheet->getStyle('A1:C1')->applyFromArray(['fill' => $headerFill, 'font' => $headerFont]);
$projectSheet->getStyle("A1:C{$projectLast}")->applyFromArray($thin);
$projectSheet->getColumnDimension('A')->setWidth(22);
$projectSheet->getColumnDimension('B')->setWidth(22);
$projectSheet->getColumnDimension('C')->setWidth(90);
$projectSheet->getStyle("C2:C{$projectLast}")->getAlignment()->setWrapText(true);
$projectSheet->getRowDimension(1)->setRowHeight(22);

$notes = $spreadsheet->createSheet();
$notes->setTitle('Notes');
$notes->fromArray([
    ['Tiwana ERP — role and permission matrix'],
    [''],
    ['Generated from', 'app/Support/Permissions/PermissionCatalog.php and DefaultRoles.php'],
    ['Date', now()->toDateString()],
    ['Catalog permissions', (string) $catalogCount],
    ['Company roles', (string) count($roleNames)],
    [''],
    ['How to read the matrix'],
    ['Yes', 'The role is granted that permission by the default seeded definition.'],
    ['(blank)', 'The role does not have that permission unless a company admin adds it in Administration → Roles & Permissions.'],
    [''],
    ['Rules'],
    ['1', 'Permissions are named {module}.{action} and are global. Roles that hold them are per company (Spatie teams).'],
    ['2', 'Super Admin is a user flag (is_super_admin). Gate::before grants every ability. It is not a company role.'],
    ['3', 'Company Admin is seeded with all catalog permissions (*).'],
    ['4', 'Editing a role in one company does not change other companies or this spreadsheet. Re-run scripts/export-role-permission-matrix.php after catalog changes.'],
    ['5', 'A user still needs project membership (or projects.view_all) to open a project, even if they hold the module permission.'],
    ['6', 'Hiding a button in the UI is not security. The server re-checks every permission.'],
    ['7', 'In-flight approval steps keep the snapshot taken at submit; changing a role later does not rewrite those steps.'],
], null, 'A1');
$notes->getStyle('A1')->getFont()->setBold(true)->setSize(14);
$notes->getColumnDimension('A')->setWidth(28);
$notes->getColumnDimension('B')->setWidth(120);
$notes->getStyle('B3:B20')->getAlignment()->setWrapText(true);

$spreadsheet->setActiveSheetIndex(0);

$out = dirname(__DIR__).DIRECTORY_SEPARATOR.'docs'.DIRECTORY_SEPARATOR.'Tiwana-ERP-Role-Permission-Matrix.xlsx';
(new Xlsx($spreadsheet))->save($out);

echo $out.PHP_EOL;
echo 'permissions='.count($allPermissions).' roles='.count($roleNames).PHP_EOL;
