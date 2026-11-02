<?php

namespace App\Services\Imports;

use App\Models\ReferenceValue;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/** M04: the blank workbook for Upload Task. It has no example rows, so nothing in it can become a task by accident. */
class TaskUploadTemplate
{
    public const FILE_NAME = 'nexora-task-upload-template.xlsx';

    /**
     * Columns in workbook order. Identifiers are text so Excel keeps their leading zeros.
     *
     * @var array<string, array{required: bool, text: bool, rule: string}>
     */
    public const COLUMNS = [
        'task_id' => ['required' => true, 'text' => true, 'rule' => 'Must end in a number that is unique within the assignment. FG001 is read as 1.'],
        'account_number' => ['required' => true, 'text' => true, 'rule' => 'Up to 100 characters. One open task per account in each assignment. An existing account must belong to this customer.'],
        'customer_reference' => ['required' => true, 'text' => true, 'rule' => 'Up to 80 characters.'],
        'customer_name' => ['required' => false, 'text' => false, 'rule' => 'Up to 200 characters. Needed on the first row of a new customer reference.'],
        'phone' => ['required' => false, 'text' => true, 'rule' => 'For example 0123456789. Saved only when the customer reference is new.'],
        'email' => ['required' => false, 'text' => false, 'rule' => 'Saved only when the customer reference is new.'],
        'outstanding_balance' => ['required' => true, 'text' => false, 'rule' => 'The amount the customer owes in RM, for example 1234.50. The Rider sees this.'],
        'monthly_charge' => ['required' => false, 'text' => false, 'rule' => 'The monthly charge in RM, for example 45.00.'],
        'balance_date' => ['required' => false, 'text' => true, 'rule' => 'The date of the balance as YYYY-MM-DD, for example 2026-11-01. Today if blank.'],
        'address' => ['required' => false, 'text' => false, 'rule' => 'Up to 2,000 characters. Saved only when the account number is new.'],
        'building' => ['required' => false, 'text' => false, 'rule' => 'Up to 200 characters. Saved only when the account number is new.'],
        'postcode' => ['required' => false, 'text' => true, 'rule' => 'Up to 20 characters. Saved only when the account number is new.'],
        'state_code' => ['required' => false, 'text' => false, 'rule' => 'Choose a state from the list. Saved only when the account number is new.'],
        'priority_code' => ['required' => false, 'text' => false, 'rule' => 'Choose low, normal, high or urgent. Blank means normal.'],
        'instructions' => ['required' => false, 'text' => false, 'rule' => 'Up to 5,000 characters.'],
    ];

    private const REQUIRED_COLOUR = 'FFFB8500';

    private const OPTIONAL_COLOUR = 'FFDCE7ED';

    private const LAST_VALIDATED_ROW = 2001;

    /**
     * Writes the workbook to a temporary file and returns its path. The caller removes the file.
     */
    public function write(): string
    {
        $book = new Spreadsheet;
        try {
            $this->fillTasksSheet($book->getActiveSheet());
            $this->fillGuideSheet($book->createSheet());
            $book->setActiveSheetIndex(0);
            $path = tempnam(sys_get_temp_dir(), 'task-template-');
            (new Xlsx($book))->save($path);

            return $path;
        } finally {
            $book->disconnectWorksheets();
        }
    }

    private function fillTasksSheet(Worksheet $sheet): void
    {
        $sheet->setTitle('Tasks');
        foreach (array_keys(self::COLUMNS) as $index => $name) {
            $column = Coordinate::stringFromColumnIndex($index + 1);
            $sheet->setCellValue($column.'1', $name);
            $sheet->getStyle($column.'1')->getFont()->setBold(true);
            $sheet->getStyle($column.'1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::COLUMNS[$name]['required'] ? self::REQUIRED_COLOUR : self::OPTIONAL_COLOUR);
            $sheet->getColumnDimension($column)->setWidth(max(16, mb_strlen($name) + 4));
            if (self::COLUMNS[$name]['text']) {
                $sheet->getStyle($column.':'.$column)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
            }
        }
        $sheet->freezePane('A2');
        $this->addList($sheet, 'state_code', ReferenceValue::query()->where('category', 'state')->where('is_active', true)->orderBy('label')->pluck('label')->all());
        $this->addList($sheet, 'priority_code', ReferenceValue::query()->where('category', 'priority')->where('is_active', true)->orderBy('sort_order')->pluck('code')->all());
    }

    /**
     * @param  array<int, string>  $options
     */
    private function addList(Worksheet $sheet, string $name, array $options): void
    {
        $column = Coordinate::stringFromColumnIndex(array_search($name, array_keys(self::COLUMNS), true) + 1);
        $validation = new DataValidation;
        $validation->setType(DataValidation::TYPE_LIST)->setAllowBlank(true)->setShowDropDown(true)->setShowErrorMessage(true)
            ->setErrorTitle('Not on the list')->setError('Choose a value from the list.')
            ->setFormula1('"'.implode(',', $options).'"')
            ->setSqref("{$column}2:{$column}".self::LAST_VALIDATED_ROW);
        $sheet->setDataValidation("{$column}2:{$column}".self::LAST_VALIDATED_ROW, $validation);
    }

    private function fillGuideSheet(Worksheet $sheet): void
    {
        $sheet->setTitle('Guide');
        $sheet->fromArray(['Column', 'Required', 'Rule'], null, 'A1');
        $sheet->getStyle('A1:C1')->getFont()->setBold(true);
        $row = 2;
        foreach (self::COLUMNS as $name => $column) {
            $sheet->fromArray([$name, $column['required'] ? 'Required' : 'Optional', $column['rule']], null, "A{$row}");
            $row++;
        }
        $sheet->setCellValue('A'.($row + 1), 'Type one task per row on the Tasks sheet, starting at row 2. Keep the headers in row 1. Orange headers are required.');
        $sheet->getColumnDimension('A')->setWidth(22);
        $sheet->getColumnDimension('B')->setWidth(12);
        $sheet->getColumnDimension('C')->setWidth(100);
    }
}
