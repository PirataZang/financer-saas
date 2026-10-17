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
        // Masters are platform accounts managed only in the database: never listed or editable here.
        $query = User::where('master', false);

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
        $user = User::where('master', false)->findOrFail($id);

        return [...$user->toArray(), 'permissions' => $user->permissionKeys()];
    }

    /**
     * Create a new user.
     */
    public function create(array $data): User
    {
        return User::create($data);
    }

    /**
     * Update an existing user.
     */
    public function update(int $id, array $data): User
    {
        $user = $this->findManageable($id);
        $user->update($data);

        return $user;
    }

    /**
     * Delete a user.
     */
    public function delete(int $id): bool
    {
        return $this->findManageable($id)->delete();
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
        $user = $this->findManageable($id);

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
                ->tap(fn ($activity) => $activity->company_id = $user->company_id)
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
     * Master accounts are created and changed only in the database, by nobody through the
     * API (not even another master). Users of another company are a plain 404 (scope).
     */
    private function findManageable(int $id): User
    {
        $user = User::findOrFail($id);

        if ($user->master) {
            throw new AccessDeniedHttpException('Usuários master só podem ser alterados diretamente no banco de dados.');
        }

        return $user;
    }
}
