<?php

namespace App\Livewire\Users;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class Index extends Component
{
    public string $name = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    public string $role = 'editor';

    /** @var array<int, array{password?: string, password_confirmation?: string}> */
    public array $passwordForms = [];

    public function mount(): void
    {
        $this->ensureAdministrator();
    }

    public function createUser(): void
    {
        $this->ensureAdministrator();

        $this->name = trim($this->name);
        $this->email = Str::lower(trim($this->email));

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class, 'email')],
            'password' => ['required', 'string', 'min:12', 'max:255', 'confirmed'],
            'role' => ['required', Rule::enum(UserRole::class)],
        ]);

        DB::transaction(function () use ($validated): void {
            $this->lockAdministratorActor();

            User::query()->create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => $validated['password'],
                'role' => UserRole::from($validated['role']),
                'is_active' => true,
            ]);
        }, 3);

        $this->reset(['name', 'email', 'password', 'password_confirmation']);
        $this->role = UserRole::Editor->value;
        session()->flash('saved', 'User created.');
    }

    public function toggleActive(int $userId): void
    {
        $this->ensureAdministrator();

        DB::transaction(function () use ($userId): void {
            [$actor, $activeAdministrators] = $this->lockAdministratorActor();
            $user = User::query()->lockForUpdate()->findOrFail($userId);
            $willBeActive = ! $user->is_active;

            if (! $willBeActive) {
                $this->ensureMayRemoveFromActiveAdministrators(
                    actor: $actor,
                    user: $user,
                    activeAdministrators: $activeAdministrators,
                    selfMessage: 'You cannot deactivate your own account.',
                    finalMessage: 'The final active administrator cannot be deactivated.',
                );
            }

            $user->forceFill(['is_active' => $willBeActive])->save();
        }, 3);

        session()->flash('saved', 'User status updated.');
    }

    public function changeRole(int $userId, string $role): void
    {
        $this->ensureAdministrator();

        $validated = validator(
            ['role' => $role],
            ['role' => ['required', Rule::enum(UserRole::class)]],
        )->validate();
        $newRole = UserRole::from($validated['role']);

        DB::transaction(function () use ($userId, $newRole): void {
            [$actor, $activeAdministrators] = $this->lockAdministratorActor();
            $user = User::query()->lockForUpdate()->findOrFail($userId);

            if ($user->role === $newRole) {
                return;
            }

            if ($newRole !== UserRole::Administrator) {
                $this->ensureMayRemoveFromActiveAdministrators(
                    actor: $actor,
                    user: $user,
                    activeAdministrators: $activeAdministrators,
                    selfMessage: 'You cannot demote your own account.',
                    finalMessage: 'The final active administrator cannot be demoted.',
                );
            }

            $user->forceFill(['role' => $newRole])->save();
        }, 3);

        session()->flash('saved', 'User role updated.');
    }

    public function setPassword(int $userId): void
    {
        $this->ensureAdministrator();

        $passwordKey = "passwordForms.$userId.password";
        $validated = $this->validate([
            $passwordKey => ['required', 'string', 'min:12', 'max:255', 'confirmed'],
        ]);

        DB::transaction(function () use ($userId, $validated, $passwordKey): void {
            $this->lockAdministratorActor();
            $user = User::query()->lockForUpdate()->findOrFail($userId);
            $user->forceFill([
                'password' => data_get($validated, $passwordKey),
                'remember_token' => Str::random(60),
            ])->save();

            DB::table('sessions')->where('user_id', $user->getKey())->delete();
        }, 3);

        unset($this->passwordForms[$userId]);
        $this->resetValidation($passwordKey);
        session()->flash('saved', 'Password updated.');
    }

    public function render()
    {
        $this->ensureAdministrator();

        $users = User::query()
            ->orderByRaw("case when role = 'administrator' then 0 else 1 end")
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        return view('livewire.users.index', [
            'users' => $users,
            'activeAdministratorCount' => $users
                ->filter(fn (User $user): bool => $user->is_active && $user->isAdministrator())
                ->count(),
        ])->layout('components.layouts.app', ['title' => 'Users · Fast Landings']);
    }

    private function ensureAdministrator(): void
    {
        $userId = Auth::id();

        abort_unless($userId && User::query()
            ->whereKey($userId)
            ->where('role', UserRole::Administrator->value)
            ->where('is_active', true)
            ->exists(), 403);
    }

    /**
     * Re-authorize against locked database state so concurrent administrators
     * cannot deactivate or demote one another and leave no active administrator.
     *
     * @return array{User, Collection<int, User>}
     */
    private function lockAdministratorActor(): array
    {
        $activeAdministrators = User::query()
            ->where('role', UserRole::Administrator->value)
            ->where('is_active', true)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $actor = $activeAdministrators->firstWhere('id', Auth::id());
        abort_unless($actor instanceof User, 403);

        return [$actor, $activeAdministrators];
    }

    /** @param Collection<int, User> $activeAdministrators */
    private function ensureMayRemoveFromActiveAdministrators(
        User $actor,
        User $user,
        Collection $activeAdministrators,
        string $selfMessage,
        string $finalMessage,
    ): void {
        if ($actor->is($user)) {
            throw ValidationException::withMessages(['users' => $selfMessage]);
        }

        if ($user->is_active && $user->isAdministrator() && $activeAdministrators->count() <= 1) {
            throw ValidationException::withMessages(['users' => $finalMessage]);
        }
    }
}
