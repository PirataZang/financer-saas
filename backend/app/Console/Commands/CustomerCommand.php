<?php

namespace App\Console\Commands;

use App\Enums\CustomerStatus;
use App\Models\Company;
use App\Models\Customer;
use App\Service\CustomerService;
use App\Service\ZipCodeService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;

/**
 * Customer records from the terminal, through the same CustomerService and validation
 * rules as the API (email or phone, enum status, normalization, soft delete).
 */
class CustomerCommand extends Command
{
    private const FIELDS = [
        'name', 'email', 'phone', 'status', 'active',
        'zip-code', 'street', 'number', 'complement', 'neighborhood', 'reference', 'city', 'state', 'country',
    ];

    protected $signature = 'customer
        {operation=list : list | create | update | delete}
        {id? : ID do cliente (update/delete)}
        {--search= : Filtra a listagem por nome, e-mail ou telefone}
        {--filter-status= : Filtra a listagem por status}
        {--limit=20 : Quantidade de linhas na listagem}
        {--name=} {--email=} {--phone=}
        {--status= : lead | warming_up | customer | cancelled}
        {--active= : 1 ou 0}
        {--zip-code= : CEP; preenche o endereço vazio pela consulta de CEP}
        {--street=} {--number=} {--complement=} {--neighborhood=} {--reference=}
        {--city=} {--state=} {--country=}
        {--force : Não pede confirmação (delete)}
        {--company=1 : ID da empresa (padrão: 1, Empresa Padrão)}';

    protected $description = 'Gerencia clientes (mesmas regras da API)';

    public function handle(CustomerService $customers, ZipCodeService $zipCodes): int
    {
        $company = Company::find((int) $this->option('company'));
        if (!$company) {
            $this->error("Empresa #{$this->option('company')} não encontrada.");

            return self::FAILURE;
        }

        return Company::as($company->id, fn () => $this->operate($customers, $zipCodes));
    }

    private function operate(CustomerService $customers, ZipCodeService $zipCodes): int
    {
        try {
            return match ($this->argument('operation')) {
                'list' => $this->list($customers),
                'create' => $this->create($customers, $zipCodes),
                'update' => $this->update($customers, $zipCodes),
                'delete' => $this->delete($customers),
                default => throw new InvalidArgumentException('Operação inválida. Use: list, create, update ou delete.'),
            };
        } catch (ValidationException $e) {
            foreach ($e->validator->errors()->all() as $message) {
                $this->error($message);
            }
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());
        } catch (ModelNotFoundException) {
            $this->error("Cliente #{$this->argument('id')} não encontrado.");
        }

        return self::FAILURE;
    }

    private function list(CustomerService $customers): int
    {
        $filters = Validator::make(
            ['search' => $this->option('search'), 'status' => $this->option('filter-status')],
            ['search' => 'nullable|string', 'status' => ['nullable', Rule::enum(CustomerStatus::class)]],
        )->validate();

        $page = $customers->list($filters, (int) $this->option('limit'));

        if ($page->isEmpty()) {
            $this->warn('Nenhum cliente encontrado.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Nome', 'E-mail', 'Telefone', 'Status', 'Ativo', 'Cidade/UF'],
            collect($page->items())->map(fn (Customer $c) => [
                $c->id, $c->name, $c->email, $c->phone, $c->status_label,
                $c->active ? 'sim' : 'não', trim("{$c->city}/{$c->state}", '/'),
            ]),
        );
        $this->line("Mostrando {$page->count()} de {$page->total()}.");

        return self::SUCCESS;
    }

    private function create(CustomerService $customers, ZipCodeService $zipCodes): int
    {
        $data = $this->givenFields();
        $data['name'] ??= $this->ask('Nome');
        if (empty($data['email']) && empty($data['phone'])) {
            $data['email'] = $this->ask('E-mail (enter para informar telefone)');
            if (!$data['email']) {
                $data['phone'] = $this->ask('Telefone com DDD');
            }
        }

        $data = $this->withAddressFromZipCode($data, $zipCodes);
        $customer = $customers->create(Validator::make($data, $customers->rules())->validate());
        $this->info("Cliente #{$customer->id} ({$customer->name}) criado como {$customer->status_label}.");

        return self::SUCCESS;
    }

    private function update(CustomerService $customers, ZipCodeService $zipCodes): int
    {
        $id = (int) ($this->argument('id') ?? $this->ask('ID do cliente'));
        $customers->index($id);

        $data = $this->withAddressFromZipCode($this->givenFields(), $zipCodes);
        if (!$data) {
            throw new InvalidArgumentException('Nada para alterar. Informe ao menos uma opção, ex.: --status=customer.');
        }

        $customer = $customers->update($id, Validator::make($data, $customers->rules(partial: true))->validate());
        $this->info("Cliente #{$customer->id} ({$customer->name}) atualizado.");

        return self::SUCCESS;
    }

    private function delete(CustomerService $customers): int
    {
        $id = (int) ($this->argument('id') ?? $this->ask('ID do cliente'));
        $customer = $customers->index($id);

        if (!$this->option('force') && !$this->confirm("Excluir o cliente #{$customer->id} ({$customer->name})?")) {
            $this->line('Cancelado.');

            return self::SUCCESS;
        }

        $customers->delete($id);
        $this->info("Cliente #{$customer->id} excluído (soft delete, o histórico é mantido).");

        return self::SUCCESS;
    }

    /**
     * Options actually given on the command line, as API field names (zip-code → zip_code).
     */
    private function givenFields(): array
    {
        $data = [];
        foreach (self::FIELDS as $option) {
            if ($this->option($option) !== null) {
                $data[str_replace('-', '_', $option)] = $this->option($option);
            }
        }

        return $data;
    }

    /**
     * Fills only the address fields not given explicitly; a lookup failure just warns.
     */
    private function withAddressFromZipCode(array $data, ZipCodeService $zipCodes): array
    {
        if (empty($data['zip_code']) || strlen(preg_replace('/\D/', '', $data['zip_code'])) !== 8) {
            return $data;
        }

        try {
            $address = $zipCodes->lookup($data['zip_code']);
        } catch (RuntimeException) {
            $this->warn('Consulta de CEP indisponível; endereço mantido como informado.');

            return $data;
        }

        if (!$address) {
            $this->warn('CEP não encontrado; endereço mantido como informado.');

            return $data;
        }

        return $data + array_filter($address);
    }
}
