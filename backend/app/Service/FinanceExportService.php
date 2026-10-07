<?php

namespace App\Service;

use App\Enums\TransactionSource;
use App\Enums\TransactionType;
use App\Models\Category;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\AutoFilter;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Excel workbook of the dashboard's data, same filters. Every figure on Resumo and Mensal is a
 * formula over the Transações sheet, so editing/filtering a row there updates the totals.
 * User text always goes in as StringCell: a description like "=HYPERLINK(...)" must stay text
 * (OpenSpout would otherwise turn any "=..." string into a formula).
 */
class FinanceExportService
{
    private const MONEY = '"R$" #,##0.00;[Red]-"R$" #,##0.00';

    private const TX = "'Transações'";

    // Transações columns referenced by the formulas.
    private const COL_DATE = 'A';

    private const COL_TYPE = 'B';

    private const COL_CATEGORY = 'E';

    private const COL_AMOUNT = 'F';

    private Style $title;

    private Style $muted;

    private Style $header;

    private Style $section;

    private Style $total;

    private Style $money;

    private Style $moneyTotal;

    private Style $percent;

    private Style $date;

    /** Row of Resumo's "Saldo anterior", which Mensal's running balance starts from. */
    private int $openingRow;

    public function __construct(
        protected FinanceDashboardService $dashboard,
    ) {
        $this->title = (new Style())->setFontBold()->setFontSize(16)->setFontColor('0F172A');
        $this->muted = (new Style())->setFontColor('64748B')->setFontSize(10);
        $this->header = (new Style())->setFontBold()->setFontColor('FFFFFF')->setBackgroundColor('2563EB');
        $this->section = (new Style())->setFontBold()->setFontColor('FFFFFF')->setBackgroundColor('334155');
        $this->total = (new Style())->setFontBold()->setBackgroundColor('F1F5F9');
        $this->money = (new Style())->setFormat(self::MONEY);
        $this->moneyTotal = (new Style())->setFontBold()->setBackgroundColor('F1F5F9')->setFormat(self::MONEY);
        $this->percent = (new Style())->setFormat('0.0%');
        $this->date = (new Style())->setFormat('dd/mm/yyyy');
    }

    /**
     * Writes the workbook to a temp file and returns its path (caller sends and deletes it).
     */
    public function export(string $from, string $to, array $filters = []): string
    {
        $summary = $this->dashboard->summary($from, $to, $filters);
        $transactions = Transaction::query()
            ->filtered([...$this->dashboard->scope($filters), 'from' => $from, 'to' => $to])
            ->with('category:id,name')
            ->orderBy('date')
            ->orderBy('id')
            ->get();

        $path = tempnam(sys_get_temp_dir(), 'finance-export-').'.xlsx';
        $writer = new Writer();
        $writer->openToFile($path);

        $this->writeSummary($writer, $summary, $this->describeFilters($filters));
        $this->writeMonthly($writer->addNewSheetAndMakeItCurrent(), $writer, $from, $to);
        $this->writeTransactions($writer->addNewSheetAndMakeItCurrent(), $writer, $transactions);
        $this->writeCategories($writer->addNewSheetAndMakeItCurrent(), $writer);

        $writer->close();

        return $path;
    }

    private function writeSummary(Writer $writer, array $summary, string $filters): void
    {
        $sheet = $writer->getCurrentSheet();
        $sheet->setName('Resumo');
        $sheet->setSheetView((new SheetView())->setShowGridLines(false));
        $sheet->setColumnWidth(38, 1);
        $sheet->setColumnWidth(18, 2, 3, 4);

        $br = fn (string $date) => CarbonImmutable::parse($date)->format('d/m/Y');
        $writer->addRow(Row::fromValues(['Balancete financeiro'], $this->title));
        $writer->addRow($this->texts(['Período: '.$br($summary['period']['from']).' a '.$br($summary['period']['to'])]));
        $writer->addRow($this->texts(["Filtros: {$filters}"], $this->muted));
        $writer->addRow($this->texts(['Gerado em '.now()->format('d/m/Y H:i').'. Valores calculados por fórmulas a partir da aba Transações.'], $this->muted));
        $writer->addRow(Row::fromValues([]));

        $row = 6;
        $totals = [];
        foreach ([TransactionType::Income, TransactionType::Expense] as $type) {
            $isIncome = $type === TransactionType::Income;
            $writer->addRow(Row::fromValues([$isIncome ? 'RECEITAS' : 'DESPESAS', 'Valor', '% do total', 'Lançamentos'], $this->section));
            $row++;

            $first = $row;
            $categories = $summary['categories'][$type->value];
            $totalRow = $first + count($categories);
            foreach ($categories as $category) {
                $criteria = self::TX.'!$'.self::COL_TYPE.':$'.self::COL_TYPE.',"'.$type->label().'",'.self::TX.'!$'.self::COL_CATEGORY.':$'.self::COL_CATEGORY.",A{$row}";
                $writer->addRow(new Row([
                    new StringCell($category['name'] ?? 'Sem categoria', null),
                    Cell::fromValue('=SUMIFS('.self::TX.'!$'.self::COL_AMOUNT.':$'.self::COL_AMOUNT.",{$criteria})", $this->money),
                    Cell::fromValue("=IF(\$B\${$totalRow}=0,0,B{$row}/\$B\${$totalRow})", $this->percent),
                    Cell::fromValue("=COUNTIFS({$criteria})"),
                ]));
                $row++;
            }

            $last = $row - 1;
            $writer->addRow(new Row([
                Cell::fromValue($isIncome ? 'Total arrecadado' : 'Total gasto', $this->total),
                Cell::fromValue($categories ? "=SUM(B{$first}:B{$last})" : 0, $this->moneyTotal),
                Cell::fromValue($categories ? 1 : 0, (clone $this->percent)->setFontBold()->setBackgroundColor('F1F5F9')),
                Cell::fromValue($categories ? "=SUM(D{$first}:D{$last})" : 0, $this->total),
            ]));
            $totals[$type->value] = $row;
            $row++;

            $writer->addRow(Row::fromValues([]));
            $row++;
        }

        $writer->addRow(Row::fromValues(['RESULTADO', 'Valor'], $this->section));
        $income = $totals['income'];
        $expense = $totals['expense'];
        $result = $row + 3;
        $opening = $row + 4;
        $this->openingRow = $opening;
        $lines = [
            ['Total arrecadado (receitas)', "=B{$income}", $this->money],
            ['Total gasto (despesas)', "=B{$expense}", $this->money],
            ['Resultado líquido (receitas − despesas)', "=B{$income}-B{$expense}", $this->moneyTotal],
            ['Saldo anterior ao período', $summary['opening_balance'], $this->money],
            ['Saldo final (saldo anterior + resultado)', "=B{$opening}+B{$result}", $this->moneyTotal],
        ];
        foreach ($lines as [$label, $value, $style]) {
            $writer->addRow(new Row([Cell::fromValue($label, $style === $this->moneyTotal ? $this->total : null), Cell::fromValue($value, $style)]));
        }

        $writer->addRow(Row::fromValues([]));
        $writer->addRow($this->texts(['Como ler: cada valor acima é uma fórmula (SOMASES) sobre a aba Transações — '
            .'editar, incluir ou excluir uma linha lá recalcula este resumo (o filtro de colunas não altera os totais). '
            .'"Saldo anterior" é o saldo acumulado antes do período, com os mesmos filtros.'], $this->muted));
    }

    private function writeMonthly($sheet, Writer $writer, string $from, string $to): void
    {
        $sheet->setName('Mensal');
        $sheet->setSheetView((new SheetView())->setFreezeRow(2));
        $sheet->setColumnWidth(14, 1);
        $sheet->setColumnWidth(18, 2, 3, 4, 5);

        $writer->addRow(Row::fromValues(['Mês', 'Receitas', 'Despesas', 'Resultado', 'Saldo acumulado'], $this->header));

        $sum = fn (string $type, int $row) => '=SUMIFS('.self::TX.'!$'.self::COL_AMOUNT.':$'.self::COL_AMOUNT.','
            .self::TX.'!$'.self::COL_TYPE.':$'.self::COL_TYPE.',"'.$type.'",'
            .self::TX.'!$'.self::COL_DATE.':$'.self::COL_DATE.',">="&A'.$row.','
            .self::TX.'!$'.self::COL_DATE.':$'.self::COL_DATE.',"<"&EDATE(A'.$row.',1))';
        $openingCell = $this->openingBalanceCell();

        $row = 2;
        $months = CarbonPeriod::create(CarbonImmutable::parse($from)->startOfMonth(), '1 month', CarbonImmutable::parse($to)->startOfMonth());
        foreach ($months as $month) {
            $writer->addRow(new Row([
                Cell::fromValue($month->toDateTimeImmutable(), (new Style())->setFormat('mmm/yyyy')),
                Cell::fromValue($sum(TransactionType::Income->label(), $row), $this->money),
                Cell::fromValue($sum(TransactionType::Expense->label(), $row), $this->money),
                Cell::fromValue("=B{$row}-C{$row}", $this->money),
                Cell::fromValue($row === 2 ? "={$openingCell}+D2" : '=E'.($row - 1)."+D{$row}", $this->money),
            ]));
            $row++;
        }

        $last = $row - 1;
        $writer->addRow(new Row([
            Cell::fromValue('Total', $this->total),
            Cell::fromValue("=SUM(B2:B{$last})", $this->moneyTotal),
            Cell::fromValue("=SUM(C2:C{$last})", $this->moneyTotal),
            Cell::fromValue("=SUM(D2:D{$last})", $this->moneyTotal),
            Cell::fromValue("=E{$last}", $this->moneyTotal),
        ]));
    }

    private function writeTransactions($sheet, Writer $writer, $transactions): void
    {
        $sheet->setName('Transações');
        $sheet->setSheetView((new SheetView())->setFreezeRow(2));
        $sheet->setColumnWidth(12, 1);
        $sheet->setColumnWidth(11, 2, 4);
        $sheet->setColumnWidth(40, 3);
        $sheet->setColumnWidth(20, 5);
        $sheet->setColumnWidth(16, 6, 7);
        $sheet->setColumnWidth(14, 8);
        $sheet->setColumnWidth(8, 9);
        $sheet->setColumnWidth(18, 10);

        $writer->addRow(Row::fromValues(
            ['Data', 'Tipo', 'Descrição', 'Status', 'Categoria', 'Valor', 'Valor (±)', 'Origem', 'ID', 'Lançado em'],
            $this->header,
        ));

        $row = 2;
        foreach ($transactions as $transaction) {
            $writer->addRow(new Row([
                Cell::fromValue(CarbonImmutable::parse($transaction->date)->toDateTimeImmutable(), $this->date),
                new StringCell($transaction->type->label(), null),
                new StringCell($transaction->description, null),
                new StringCell($transaction->status->label(), null),
                new StringCell($transaction->category?->name ?? 'Sem categoria', null),
                Cell::fromValue((float) $transaction->amount, $this->money),
                Cell::fromValue("=IF(B{$row}=\"".TransactionType::Income->label()."\",F{$row},-F{$row})", $this->money),
                new StringCell($transaction->source->label(), null),
                Cell::fromValue($transaction->id),
                Cell::fromValue($transaction->created_at->toDateTimeImmutable(), (new Style())->setFormat('dd/mm/yyyy hh:mm')),
            ]));
            $row++;
        }

        $sheet->setAutoFilter(new AutoFilter(0, 1, 9, max($row - 1, 2)));
    }

    private function writeCategories($sheet, Writer $writer): void
    {
        $sheet->setName('Categorias');
        $sheet->setSheetView((new SheetView())->setFreezeRow(2));
        $sheet->setColumnWidth(24, 1);
        $sheet->setColumnWidth(10, 2, 4, 5, 6);
        $sheet->setColumnWidth(12, 3);
        $sheet->setColumnWidth(18, 7, 8);
        $sheet->setColumnWidth(13, 9);

        $writer->addRow(Row::fromValues(
            ['Categoria', 'Ícone', 'Aceita', 'Cor', 'Ativa', 'Ordem', 'Receitas no período', 'Despesas no período', 'Lançamentos'],
            $this->header,
        ));

        $row = 2;
        foreach (Category::orderBy('sort_order')->orderBy('name')->get() as $category) {
            $match = self::TX.'!$'.self::COL_CATEGORY.':$'.self::COL_CATEGORY.",A{$row}";
            $sumType = fn (TransactionType $type) => '=SUMIFS('.self::TX.'!$'.self::COL_AMOUNT.':$'.self::COL_AMOUNT.",{$match},"
                .self::TX.'!$'.self::COL_TYPE.':$'.self::COL_TYPE.',"'.$type->label().'")';
            $writer->addRow(new Row([
                new StringCell($category->name, null),
                new StringCell((string) $category->icon, null),
                new StringCell($category->type->label(), null),
                new StringCell($category->color, (new Style())->setFontColor(ltrim($category->color, '#'))),
                new StringCell($category->active ? 'Sim' : 'Não', null),
                Cell::fromValue($category->sort_order),
                Cell::fromValue($sumType(TransactionType::Income), $this->money),
                Cell::fromValue($sumType(TransactionType::Expense), $this->money),
                Cell::fromValue("=COUNTIFS({$match})"),
            ]));
            $row++;
        }
    }

    private function openingBalanceCell(): string
    {
        return "'Resumo'!B".$this->openingRow;
    }

    private function texts(array $values, ?Style $style = null): Row
    {
        return new Row(array_map(fn ($value) => new StringCell((string) $value, $style), $values));
    }

    private function describeFilters(array $filters): string
    {
        $status = match ($filters['status'] ?? 'paid') {
            'all' => 'todas (efetivadas e pendentes)',
            'pending' => 'somente pendentes',
            default => 'somente efetivadas',
        };
        $categories = empty($filters['category_ids'])
            ? 'todas'
            : Category::withTrashed()->whereIn('id', $filters['category_ids'])->orderBy('name')->pluck('name')->implode(', ');
        $source = isset($filters['source']) ? TransactionSource::from($filters['source'])->label() : 'todas';

        return "status {$status} · categorias: {$categories} · origem: {$source}";
    }
}
