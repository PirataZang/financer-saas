<?php

namespace App\Service\Import;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * O que muda de uma importação para outra (clientes, usuários…): campos, regras, como achar o registro
 * que já existe e como gravar. Leitura do arquivo, lotes na fila, erros e modelo são do ImportService.
 */
abstract class ImportDefinition
{
    /** Valor gravado em imports.type e usado na URL. */
    abstract public function type(): string;

    /** Nome do arquivo do modelo: modelo_importacao_{slug}.xlsx */
    abstract public function slug(): string;

    /**
     * campo => [label, aliases (cabeçalhos reconhecidos sozinhos), secret? (nunca volta da API nem fica em
     * claro no banco), list? (únicas opções aceitas: viram lista suspensa no modelo)].
     *
     * @return array<string, array{0: string, 1: list<string>, secret?: bool, list?: list<string>}>
     */
    abstract public function fields(): array;

    /**
     * Campos que o mapeamento precisa ter: todos os de `all` e ao menos um de `any` (se houver).
     *
     * @return array{all: list<string>, any: list<string>}
     */
    abstract public function requires(): array;

    /**
     * Texto da planilha → valores do cadastro.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    abstract public function normalize(array $row): array;

    /** @return array<string, mixed> regras de validação; $existing é o registro que a linha vai atualizar. */
    abstract public function rules(?Model $existing): array;

    /**
     * Registro que a linha vai atualizar, ou null para criar. Lança ValidationException se for ambíguo/proibido.
     *
     * @param  array<string, mixed>  $data  já normalizado
     */
    abstract public function findExisting(array $data): ?Model;

    /** @param  array<string, mixed>  $data  com os campos secretos de volta */
    abstract public function save(array $data, ?Model $existing): void;

    /** @return list<string> uma linha de exemplo do modelo, na ordem de fields(). */
    abstract public function example(): array;

    /** @return list<string> permissões que, além de imports.view, dão acesso ao histórico deste tipo. */
    public function viewPermissions(): array
    {
        return [];
    }

    /** @return list<string> permissões que, além de imports.create, deixam importar este tipo. */
    public function managePermissions(): array
    {
        return [];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'required' => 'Obrigatório.',
            'required_without' => 'Informe e-mail ou telefone.',
            'email' => 'E-mail inválido.',
            'min.string' => 'Mínimo de :min caracteres.',
            'max.string' => 'Máximo de :max caracteres.',
            'boolean' => 'Use Sim ou Não.',
            'string' => 'Texto inválido.',
        ];
    }

    /** @return list<string> */
    public function secretFields(): array
    {
        return array_keys(array_filter($this->fields(), fn ($field) => $field['secret'] ?? false));
    }

    /** Só minúsculas e letras/números: "E-mail", "e_mail" e "EMAIL" viram "email". */
    public static function norm(string $text): string
    {
        return preg_replace('/[^a-z0-9]/', '', Str::lower(Str::ascii($text)));
    }

    /**
     * Escalares aparados, vazio vira null; só os campos do tipo, todos presentes.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, ?string>
     */
    protected function clean(array $row): array
    {
        $data = [];
        foreach (array_keys($this->fields()) as $key) {
            $value = is_scalar($row[$key] ?? null) ? trim((string) $row[$key]) : '';
            $data[$key] = $value === '' ? null : $value;
        }

        return $data;
    }

    /** Sim/Não e variantes → bool; texto desconhecido segue como veio (a regra boolean reclama). */
    protected function toBool(string $value): bool|string
    {
        $flag = self::norm($value);

        return match (true) {
            in_array($flag, ['sim', 's', 'yes', 'y', 'true', '1', 'ativo', 'verdadeiro'], true) => true,
            in_array($flag, ['nao', 'n', 'no', 'false', '0', 'inativo', 'falso'], true) => false,
            default => $value,
        };
    }
}
