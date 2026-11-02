<?php

namespace App\Jobs;

use App\Models\Company;
use App\Models\Import;
use App\Models\ImportRow;
use App\Service\ImportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * One chunk (ImportService::CHUNK rows) of an import: a slow or failing chunk never holds the others
 * back. A row that fails is recorded on the row itself (status failed + reason).
 */
class ImportRowsChunk implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    /** @param list<int> $rowIds */
    public function __construct(public int $importId, public int $companyId, public array $rowIds)
    {
    }

    public function handle(ImportService $service): void
    {
        Company::as($this->companyId, function () use ($service) {
            $def = $service->definition(Import::findOrFail($this->importId)->type);

            ImportRow::where('import_id', $this->importId)->whereIn('id', $this->rowIds)->where('status', 'pending')
                ->each(fn (ImportRow $row) => $service->process($row, $def));
        });
    }

    /** Crash (not a bad row): don't leave the import "processing" forever. */
    public function failed(Throwable $e): void
    {
        Company::as($this->companyId, fn () => ImportRow::where('import_id', $this->importId)
            ->whereIn('id', $this->rowIds)->where('status', 'pending')
            ->update(['status' => 'failed', 'error' => 'Falha ao processar este lote. Tente importar novamente.', 'secret' => null]));
    }
}
