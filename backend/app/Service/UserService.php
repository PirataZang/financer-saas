<?php

namespace App\Service;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class UserService
{
    /**
     * Get a paginated list of users.
     */
    public function list(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = User::query();

        if (!empty($filters['search'])) {
            $query->where(function ($q) use ($filters) {
                $q->where('name', 'like', '%' . $filters['search'] . '%')
                  ->orWhere('email', 'like', '%' . $filters['search'] . '%');
            });
        }

        return $query->latest()->paginate($perPage);
    }

    /**
     * Get a single user by ID.
     */
    public function index(int $id): array
    {
        $user = User::findOrFail($id);

        return [...$user->toArray(), 'permissions' => $user->permissionKeys()];
    }

    /**
     * Create a new user.
     */
    public function create(User $actor, array $data): User
    {
        $master = $this->pullMaster($actor, $data);
        $user = User::create($data);

        if ($master !== null) {
            $user->forceFill(['master' => $master])->save();
        }

        return $user;
    }

    /**
     * Update an existing user.
     */
    public function update(User $actor, int $id, array $data): User
    {
        $user = $this->findManageable($actor, $id);
        $master = $this->pullMaster($actor, $data);

        if ($master === false || (array_key_exists('active', $data) && !$data['active'])) {
            $this->assertNotLastMaster($user);
        }

        $user->fill($data);
        if ($master !== null) {
            $user->forceFill(['master' => $master]);
        }
        $user->save();

        return $user;
    }

    /**
     * Delete a user.
     */
    public function delete(User $actor, int $id): bool
    {
        $user = $this->findManageable($actor, $id);
        $this->assertNotLastMaster($user);

        return $user->delete();
    }

    /**
     * Only a master can grant or remove master (it is not mass-assignable).
     */
    private function pullMaster(User $actor, array &$data): ?bool
    {
        if (!array_key_exists('master', $data)) {
            return null;
        }

        $master = (bool) $data['master'];
        unset($data['master']);

        if (!$actor->master) {
            throw new AccessDeniedHttpException('Somente um usuário master pode definir quem é master.');
        }

        return $master;
    }

    /**
     * Never leave the system without an active master (nobody could grant permissions).
     */
    private function assertNotLastMaster(User $user): void
    {
        if ($user->master && !User::where('master', true)->where('active', true)->whereKeyNot($user->id)->exists()) {
            throw new AccessDeniedHttpException('Este é o único usuário master ativo; defina outro master antes.');
        }
    }

    /**
     * Replace the user's permissions. A non-master actor can only grant or revoke
     * permissions they hold themselves, so the screen can't be used to escalate.
     *
     * @param  list<string>  $keys
     * @return list<string>
     */
    public function syncPermissions(User $actor, int $id, array $keys): array
    {
        $user = $this->findManageable($actor, $id);

        if (!$actor->master) {
            $current = $user->permissionKeys();
            $changed = array_merge(array_diff($keys, $current), array_diff($current, $keys));
            $notHeld = array_diff($changed, $actor->permissionKeys());

            if ($notHeld) {
                throw new AccessDeniedHttpException(
                    'Você só pode conceder ou remover permissões que você possui: '.implode(', ', $notHeld)
                );
            }
        }

        $before = $user->permissionKeys();
        $user->syncPermissions($keys);
        $after = $user->permissionKeys();

        if ($before !== $after) {
            activity(class_basename($user))
                ->performedOn($user)
                ->causedBy($actor)
                ->event('updated')
                ->withProperties([
                    'old' => ['permissions' => implode(', ', $before) ?: '—'],
                    'attributes' => ['permissions' => implode(', ', $after) ?: '—'],
                ])
                ->log('updated');
        }

        return $after;
    }

    /**
     * Only a master can change or delete a master account; otherwise users.update
     * would let anyone reset the master's password and take over.
     */
    private function findManageable(User $actor, int $id): User
    {
        $user = User::findOrFail($id);

        if ($user->master && !$actor->master) {
            throw new AccessDeniedHttpException('Somente um usuário master pode alterar outro master.');
        }

        return $user;
    }
}
