<?php
require __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

// 🔹 Header titles
$headers = [
    'Employee ID',
    'Name',
    'Position',
    'Department ID',
    'Email',
    'Password',
    'Role'
];

// 🔹 Insert headers
$sheet->fromArray($headers, null, 'A1');

// 🔹 Column widths (VERY IMPORTANT)
$widths = [15, 22, 20, 18, 28, 16, 14];
$col = 'A';
foreach ($widths as $w) {
    $sheet->getColumnDimension($col)->setWidth($w);
    $col++;
}

// 🔹 Header style
$headerStyle = [
    'font' => [
        'bold' => true,
        'color' => ['rgb' => 'FFFFFF']
    ],
    'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_CENTER
    ],
    'fill' => [
        'fillType' => Fill::FILL_SOLID,
        'startColor' => ['rgb' => '2C7BE5']
    ]
];

$sheet->getStyle('A1:G1')->applyFromArray($headerStyle);

// 🔹 Freeze header row
$sheet->freezePane('A2');

// 🔹 Optional sample row (for guidance)
$sheet->fromArray([
    'EMP2022-001',
    'Juan Dela Cruz',
    'IT Staff',
    '2',
    'juan@email.com',
    'password123',
    'staff',
], null, 'A2');

// 🔹 Set active cell
$sheet->setSelectedCell('A2');

// 🔹 Output file
$writer = new Xlsx($spreadsheet);

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="employee_template.xlsx"');
header('Cache-Control: max-age=0');

$writer->save('php://output');
exit;
