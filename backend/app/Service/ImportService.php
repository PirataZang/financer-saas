<?php

namespace App\Service;

use App\Jobs\ImportRowsChunk;
use App\Models\Company;
use App\Models\Import;
use App\Models\ImportRow;
use App\Service\Import\CustomerImport;
use App\Service\Import\ImportDefinition;
use App\Service\Import\UserImport;
use DateTimeInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\ReaderInterface;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use ZipArchive;

/**
 * Importação por planilha (clientes, usuários…): lê o arquivo, valida linha a linha com as regras do
 * cadastro e grava em lotes numa fila. O que muda de um tipo para outro está em Service\Import\*.
 * Registro já existente é ATUALIZADO com o que veio na planilha; célula em branco não apaga o que já existe.
 */
class ImportService
{
    public const MAX_ROWS = 5000;

    public const CHUNK = 100;

    private const TYPES = ['customer' => CustomerImport::class, 'user' => UserImport::class];

    public function definition(string $type): ImportDefinition
    {
        return app(self::TYPES[$type] ?? throw new NotFoundHttpException());
    }

    /* ---------- leitura do arquivo ---------- */

    /**
     * Cabeçalhos + linhas do arquivo, o mapeamento sugerido (campo => índice da coluna | null) e os campos do tipo.
     * A coluna "Erro" (do CSV de erros que o sistema gera) é descartada para poder reenviar o arquivo corrigido.
     */
    public function parse(string $type, UploadedFile $file): array
    {
        $def = $this->definition($type);
        $reader = $this->reader($file);
        $reader->open($file->getRealPath());

        $headers = [];
        $keep = [];
        $rows = [];
        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $cells = array_map($this->cell(...), $row->toArray());

                    if (!$headers) {
                        foreach ($cells as $i => $header) {
                            if ($header !== '' && ImportDefinition::norm($header) !== 'erro') {
                                $keep[] = $i;
                                $headers[] = $header;
                            }
                        }

                        continue;
                    }

                    $values = array_map(fn ($i) => $cells[$i] ?? '', $keep);
                    if (array_filter($values, fn ($v) => $v !== '')) {
                        $rows[] = $values;
                    }
                    if (count($rows) > self::MAX_ROWS) {
                        throw ValidationException::withMessages(['file' => ['O arquivo tem mais de '.self::MAX_ROWS.' linhas.']]);
                    }
                }
                break; // só a primeira aba
            }
        } finally {
            $reader->close();
        }

        if (!$headers) {
            throw ValidationException::withMessages(['file' => ['O arquivo está vazio ou sem cabeçalho na primeira linha.']]);
        }
        if (!$rows) {
            throw ValidationException::withMessages(['file' => ['O arquivo não tem nenhuma linha de dados.']]);
        }

        $requires = $def->requires();
        $fields = array_map(fn ($key, $field) => [
            'key' => $key,
            'label' => $field[0],
            'required' => in_array($key, $requires['all'], true),
            'secret' => $field['secret'] ?? false,
        ], array_keys($def->fields()), $def->fields());

        return ['headers' => $headers, 'rows' => $rows, 'mapping' => $this->suggestMapping($def, $headers), 'fields' => $fields, 'requires' => $requires];
    }

    private function reader(UploadedFile $file): ReaderInterface
    {
        if (strtolower($file->getClientOriginalExtension()) === 'xlsx') {
            return new XlsxReader();
        }

        // CSV: Excel pt-BR separa por ";", o resto do mundo por ",".
        $first = (string) fgets(fopen($file->getRealPath(), 'r'));
        $options = new CsvOptions();
        $options->FIELD_DELIMITER = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';

        return new CsvReader($options);
    }

    private function cell(mixed $value): string
    {
        return match (true) {
            $value instanceof DateTimeInterface => $value->format('Y-m-d'),
            is_float($value) && floor($value) === $value => (string) (int) $value,
            default => trim((string) $value),
        };
    }

    /** @param list<string> $headers */
    private function suggestMapping(ImportDefinition $def, array $headers): array
    {
        $normalized = array_map(ImportDefinition::norm(...), $headers);
        $taken = [];
        $mapping = [];

        foreach ($def->fields() as $key => $field) {
            $names = [ImportDefinition::norm($key), ImportDefinition::norm($field[0]), ...$field[1]];
            $index = null;
            foreach ($normalized as $i => $header) {
                if (!isset($taken[$i]) && in_array($header, $names, true)) {
                    $index = $i;
                    $taken[$i] = true;
                    break;
                }
            }
            $mapping[$key] = $index;
        }

        return $mapping;
    }

    /* ---------- validação ---------- */

    /**
     * @param  array<string, mixed>  $row
     * @return array{data: array<string, mixed>, errors: array<string, string>, existing: ?array{id: int, name: string}}
     */
    public function validate(string $type, array $row): array
    {
        return $this->check($this->definition($type), $row);
    }

    private function check(ImportDefinition $def, array $row): array
    {
        $data = $def->normalize($row);

        $errors = [];
        $existing = null;
        try {
            $existing = $def->findExisting($data);
        } catch (ValidationException $e) {
            $errors = array_map(fn ($messages) => $messages[0], $e->errors());
        }

        $errors += array_map(
            fn ($messages) => $messages[0],
            Validator::make($data, $def->rules($existing), $def->messages())->errors()->toArray(),
        );

        // Rule::enum busca a mensagem sozinha (em inglês), ignorando a lista de mensagens.
        if (isset($errors['status']) && isset($def->messages()['enum'])) {
            $errors['status'] = $def->messages()['enum'];
        }

        return ['data' => $data, 'errors' => $errors, 'existing' => $existing ? ['id' => $existing->id, 'name' => $existing->name] : null];
    }

    /* ---------- importação ---------- */

    /**
     * Grava todas as linhas (as inválidas já como falha, com o motivo) e enfileira as válidas em lotes.
     * Campos secretos vão criptografados para `secret`, nunca em `data`.
     *
     * @param  list<array{line: int, data: array<string, mixed>}>  $rows
     */
    public function store(string $type, string $filename, array $rows, ?int $userId): Import
    {
        $def = $this->definition($type);
        $secrets = array_flip($def->secretFields());

        $import = DB::transaction(function () use ($def, $type, $secrets, $filename, $rows, $userId) {
            $import = Import::create(['user_id' => $userId, 'type' => $type, 'filename' => $filename, 'total' => count($rows)]);

            $records = array_map(function ($row) use ($def, $secrets, $import) {
                $result = $this->check($def, $row['data']);
                $secret = array_intersect_key($result['data'], $secrets);

                return [
                    'company_id' => Company::current(),
                    'import_id' => $import->id,
                    'line' => $row['line'],
                    'data' => json_encode(array_diff_key($result['data'], $secrets)),
                    'secret' => $secret && !$result['errors'] ? Crypt::encryptString(json_encode($secret)) : null,
                    'status' => $result['errors'] ? 'failed' : 'pending',
                    'error' => $result['errors'] ? $this->errorText($def, $result['errors']) : null,
                ];
            }, $rows);

            foreach (array_chunk($records, 500) as $chunk) {
                ImportRow::insert($chunk);
            }

            return $import;
        });

        foreach ($import->rows()->where('status', 'pending')->orderBy('id')->pluck('id')->chunk(self::CHUNK) as $ids) {
            ImportRowsChunk::dispatch($import->id, Company::current(), $ids->all());
        }

        return $import;
    }

    /** Cria ou atualiza o registro de uma linha pendente e anota o resultado nela (apagando o segredo). */
    public function process(ImportRow $row, ImportDefinition $def): void
    {
        try {
            $data = [...$row->data, ...($row->secret ?? [])];
            $existing = $def->findExisting($data);

            $def->save($data, $existing);
            $row->forceFill(['secret' => null])->update(['status' => $existing ? 'updated' : 'created']);
        } catch (ValidationException $e) {
            $this->fail($row, $this->errorText($def, array_map(fn ($m) => $m[0], $e->errors())));
        } catch (\Throwable $e) {
            report($e);
            $this->fail($row, 'Erro inesperado ao salvar o registro.');
        }
    }

    private function fail(ImportRow $row, string $error): void
    {
        $row->forceFill(['secret' => null])->update(['status' => 'failed', 'error' => $error]);
    }

    /** @param array<string, string> $errors */
    private function errorText(ImportDefinition $def, array $errors): string
    {
        return collect($errors)
            ->map(fn ($message, $field) => ($def->fields()[$field][0] ?? $field).': '.$message)
            ->implode(' ');
    }

    /* ---------- consulta ---------- */

    public function list(string $type, int $perPage): LengthAwarePaginator
    {
        return Import::withProgress()->where('type', $type)->latest('id')->paginate($perPage);
    }

    public function find(int $id): Import
    {
        return Import::withProgress()->findOrFail($id);
    }

    /** Linhas que viraram (ou atualizaram) um registro, com os dados que a planilha trouxe. */
    public function imported(Import $import, int $perPage): LengthAwarePaginator
    {
        return $import->rows()->whereIn('status', ['created', 'updated'])->orderBy('line')->paginate($perPage);
    }

    public function failures(Import $import, int $perPage): LengthAwarePaginator
    {
        return $import->rows()->where('status', 'failed')->orderBy('line')->paginate($perPage);
    }

    /** CSV (";" + BOM, abre no Excel pt-BR) das linhas que falharam, com a coluna Erro; reenviável como está. */
    public function failuresCsv(Import $import)
    {
        $fields = $this->definition($import->type)->fields();

        return response()->streamDownload(function () use ($import, $fields) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [...array_column($fields, 0), 'Erro'], ';');
            foreach ($import->rows()->where('status', 'failed')->orderBy('line')->cursor() as $row) {
                fputcsv($out, [...array_map(fn ($key) => $this->csvValue($row->data[$key] ?? null), array_keys($fields)), $row->error], ';');
            }
            fclose($out);
        }, 'erros_importacao_'.$import->id.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function csvValue(mixed $value): string
    {
        return is_bool($value) ? ($value ? 'Sim' : 'Não') : (string) $value;
    }

    /**
     * Modelo .xlsx com os cabeçalhos que o mapeamento reconhece sozinho, uma linha de exemplo e, nas colunas
     * que só aceitam algumas opções (Status, Ativo…), uma lista suspensa que recusa qualquer outro valor.
     */
    public function template(string $type): string
    {
        $def = $this->definition($type);
        $fields = $def->fields();

        $path = tempnam(sys_get_temp_dir(), 'modelo');
        $writer = new Writer();
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(array_column($fields, 0)));
        $writer->addRow(Row::fromValues($def->example()));
        $writer->close();

        $this->addDropdowns($path, array_values(array_map(fn ($field) => $field['list'] ?? null, $fields)));

        return $path;
    }

    /**
     * OpenSpout não escreve validação de dados: injeta no XML da planilha, logo após as linhas.
     *
     * @param  list<?list<string>>  $lists  uma entrada por coluna (null = livre)
     */
    private function addDropdowns(string $path, array $lists): void
    {
        $rules = '';
        $count = 0;
        foreach ($lists as $i => $options) {
            if (!$options) {
                continue;
            }
            $column = chr(65 + $i); // ponytail: até 26 colunas (A–Z), de sobra para os modelos atuais
            $rules .= sprintf(
                '<dataValidation type="list" allowBlank="1" showErrorMessage="1" errorTitle="Valor inválido" error="Escolha uma das opções da lista." sqref="%s2:%s%d"><formula1>"%s"</formula1></dataValidation>',
                $column, $column, self::MAX_ROWS + 1, htmlspecialchars(implode(',', $options), ENT_XML1 | ENT_QUOTES),
            );
            $count++;
        }
        if (!$count) {
            return;
        }

        $zip = new ZipArchive();
        $zip->open($path);
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->addFromString('xl/worksheets/sheet1.xml', str_replace('</sheetData>', "</sheetData><dataValidations count=\"{$count}\">{$rules}</dataValidations>", $sheet));
        $zip->close();
    }
}
