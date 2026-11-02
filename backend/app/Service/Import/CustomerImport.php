<?php

namespace App\Service\Import;

use App\Enums\CustomerStatus;
use App\Models\Customer;
use App\Service\CustomerService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/** Cliente já existente (mesmo e-mail ou, na falta, mesmo telefone) é atualizado; célula em branco não apaga. */
class CustomerImport extends ImportDefinition
{
    public function __construct(private CustomerService $customers)
    {
    }

    public function type(): string
    {
        return 'customer';
    }

    public function slug(): string
    {
        return 'clientes';
    }

    public function fields(): array
    {
        return [
            'name' => ['Nome', ['nome', 'cliente', 'nomedocliente', 'clientename', 'razaosocial']],
            'email' => ['E-mail', ['emailcliente', 'mail']],
            'phone' => ['Telefone', ['celular', 'fone', 'whatsapp', 'tel', 'contato']],
            'status' => ['Status', ['situacao', 'etapa', 'funil'], 'list' => array_map(fn ($case) => $case->label(), CustomerStatus::cases())],
            'active' => ['Ativo', ['ativado'], 'list' => ['Sim', 'Não']],
            'zip_code' => ['CEP', ['codigopostal', 'zip']],
            'street' => ['Rua', ['logradouro', 'endereco']],
            'number' => ['Número', ['num', 'nro']],
            'complement' => ['Complemento', []],
            'neighborhood' => ['Bairro', []],
            'reference' => ['Referência', ['pontodereferencia']],
            'city' => ['Cidade', ['municipio']],
            'state' => ['Estado', ['uf']],
            'country' => ['País', []],
        ];
    }

    public function requires(): array
    {
        return ['all' => ['name'], 'any' => ['email', 'phone']];
    }

    public function messages(): array
    {
        return [...parent::messages(), 'enum' => 'Use Lead, Em aquecimento, Cliente ou Cancelado.'];
    }

    public function normalize(array $row): array
    {
        $data = $this->clean($row);

        if ($data['status'] !== null) {
            foreach (CustomerStatus::cases() as $case) {
                if (in_array(self::norm($data['status']), [self::norm($case->value), self::norm($case->label())], true)) {
                    $data['status'] = $case->value;
                }
            }
        }

        if ($data['active'] !== null) {
            $data['active'] = $this->toBool($data['active']);
        }

        // Vazios saem: vale o padrão do sistema (novo) ou o que o cliente já tem (atualização).
        return array_filter($data, fn ($value, $key) => !in_array($key, ['status', 'active'], true) || $value !== null, ARRAY_FILTER_USE_BOTH);
    }

    public function rules(?Model $existing): array
    {
        return $this->customers->rules();
    }

    /** ponytail: 2 consultas por linha; em lote (whereIn) se 5.000 linhas ficar lento. */
    public function findExisting(array $data): ?Model
    {
        $email = isset($data['email']) ? mb_strtolower($data['email']) : null;
        $phone = preg_replace('/\D/', '', (string) ($data['phone'] ?? ''));

        $byEmail = $email ? Customer::where('email', $email)->first() : null;
        $byPhone = $phone ? Customer::where('phone', $phone)->first() : null;

        if ($byEmail && $byPhone && !$byEmail->is($byPhone)) {
            throw ValidationException::withMessages(['email' => ['E-mail e telefone pertencem a clientes diferentes.']]);
        }

        return $byEmail ?? $byPhone;
    }

    public function save(array $data, ?Model $existing): void
    {
        $payload = array_filter($data, fn ($value) => $value !== null);

        $existing ? $existing->update($payload) : Customer::create($payload);
    }

    public function example(): array
    {
        return [
            'Maria Souza', 'maria@exemplo.com', '(47) 99999-0000', 'Lead', 'Sim',
            '89160-000', 'Rua XV de Novembro', '120', 'Sala 3', 'Centro', 'Ao lado da praça', 'Rio do Sul', 'SC', 'Brasil',
        ];
    }
}
