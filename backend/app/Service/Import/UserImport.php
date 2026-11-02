<?php

namespace App\Service\Import;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Usuário da empresa com o mesmo e-mail é atualizado (inclusive a senha, se vier na planilha, como em
 * Usuários › Alterar). Masters nunca; e-mail de outra empresa dá a mesma resposta do cadastro, sem revelar nada.
 * A senha é segredo: não fica em claro no banco nem volta pela API.
 */
class UserImport extends ImportDefinition
{
    public function type(): string
    {
        return 'user';
    }

    public function slug(): string
    {
        return 'usuarios';
    }

    public function fields(): array
    {
        return [
            'name' => ['Nome', ['nomecompleto', 'usuario', 'nomedousuario', 'username']],
            'email' => ['E-mail', ['login', 'emaildousuario', 'mail']],
            'password' => ['Senha', ['password', 'senhainicial', 'senhaprovisoria'], 'secret' => true],
            'active' => ['Ativo', ['ativado'], 'list' => ['Sim', 'Não']],
        ];
    }

    public function requires(): array
    {
        return ['all' => ['name', 'email'], 'any' => []];
    }

    public function viewPermissions(): array
    {
        return ['users.view'];
    }

    public function managePermissions(): array
    {
        return ['users.create', 'users.update'];
    }

    public function normalize(array $row): array
    {
        $data = $this->clean($row);

        if ($data['email'] !== null) {
            $data['email'] = mb_strtolower($data['email']);
        }
        if ($data['active'] !== null) {
            $data['active'] = $this->toBool($data['active']);
        }

        return array_filter($data, fn ($value, $key) => !in_array($key, ['password', 'active'], true) || $value !== null, ARRAY_FILTER_USE_BOTH);
    }

    public function rules(?Model $existing): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            // Obrigatória só para quem vai ser criado; quem já existe mantém a senha se a coluna vier vazia.
            'password' => [$existing ? 'nullable' : 'required', 'string', 'min:8'],
            'active' => ['sometimes', 'boolean'],
        ];
    }

    public function findExisting(array $data): ?Model
    {
        $email = $data['email'] ?? null;
        if (!$email) {
            return null;
        }

        $user = User::whereRaw('lower(email) = ?', [$email])->first();
        if ($user?->master) {
            throw ValidationException::withMessages(['email' => ['Usuário master não pode ser alterado por importação.']]);
        }
        if ($user) {
            return $user;
        }

        // O e-mail é o login e é único entre empresas: o de outra empresa é recusado sem dizer que existe.
        if (User::withoutGlobalScope('company')->whereRaw('lower(email) = ?', [$email])->exists()) {
            throw ValidationException::withMessages(['email' => ['Não foi possível usar este e-mail. Informe outro.']]);
        }

        return null;
    }

    public function save(array $data, ?Model $existing): void
    {
        $payload = array_filter($data, fn ($value) => $value !== null);

        $existing ? $existing->update($payload) : User::create($payload);
    }

    public function example(): array
    {
        return ['Maria Souza', 'maria@exemplo.com', 'trocar-123', 'Sim'];
    }
}
