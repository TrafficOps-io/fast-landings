<x-ui::page width="max-w-7xl">
    <x-ui::page-header
        title="Users"
        eyebrow="Administration"
    />

    <x-ui::feedback />

    <x-ui::panel
        title="Create user"
        description="New users can sign in immediately with the password you assign."
    >
        <form wire:submit="createUser" class="grid gap-4 lg:grid-cols-2">
            <label class="d-form-control grid gap-1.5">
                <span class="text-sm font-medium">Name</span>
                <input
                    wire:model="name"
                    type="text"
                    required
                    autocomplete="name"
                    class="d-input d-input-bordered w-full @error('name') d-input-error @enderror"
                >
                @error('name') <span class="text-sm text-error" role="alert">{{ $message }}</span> @enderror
            </label>

            <label class="d-form-control grid gap-1.5">
                <span class="text-sm font-medium">Email address</span>
                <input
                    wire:model="email"
                    type="email"
                    required
                    autocomplete="email"
                    class="d-input d-input-bordered w-full @error('email') d-input-error @enderror"
                >
                @error('email') <span class="text-sm text-error" role="alert">{{ $message }}</span> @enderror
            </label>

            <label class="d-form-control grid gap-1.5">
                <span class="text-sm font-medium">Password</span>
                <input
                    wire:model="password"
                    type="password"
                    required
                    minlength="12"
                    autocomplete="new-password"
                    class="d-input d-input-bordered w-full @error('password') d-input-error @enderror"
                >
                @error('password') <span class="text-sm text-error" role="alert">{{ $message }}</span> @enderror
            </label>

            <label class="d-form-control grid gap-1.5">
                <span class="text-sm font-medium">Confirm password</span>
                <input
                    wire:model="password_confirmation"
                    type="password"
                    required
                    minlength="12"
                    autocomplete="new-password"
                    class="d-input d-input-bordered w-full"
                >
            </label>

            <label class="d-form-control grid gap-1.5 lg:max-w-xs">
                <span class="text-sm font-medium">Role</span>
                <select wire:model="role" class="d-select d-select-bordered w-full">
                    @foreach (\App\Enums\UserRole::cases() as $roleOption)
                        <option value="{{ $roleOption->value }}">{{ ucfirst($roleOption->value) }}</option>
                    @endforeach
                </select>
                @error('role') <span class="text-sm text-error" role="alert">{{ $message }}</span> @enderror
            </label>

            <div class="flex items-end lg:justify-end">
                <button
                    type="submit"
                    class="d-btn d-btn-primary w-full lg:w-auto"
                    wire:loading.attr="disabled"
                    wire:target="createUser"
                >
                    <span class="d-loading d-loading-spinner d-loading-sm" wire:loading wire:target="createUser"></span>
                    Create user
                </button>
            </div>
        </form>
    </x-ui::panel>

    <x-ui::panel
        title="Existing users"
        description="Manage access, roles, and credentials. Your own administrative access is protected."
    >
        <x-ui::data-table density="compact">
            <x-slot:head>
                <tr>
                    <th>User</th>
                    <th>Status</th>
                    <th>Role</th>
                    <th>Access</th>
                    <th class="min-w-72">Password</th>
                </tr>
            </x-slot:head>

            <x-slot:body>
                @foreach ($users as $user)
                    @php
                        $isSelf = auth()->id() === $user->id;
                        $protectAdministrator = $user->isAdministrator()
                            && ($isSelf || ($user->is_active && $activeAdministratorCount <= 1));
                        $passwordKey = "passwordForms.{$user->id}.password";
                    @endphp
                    <tr wire:key="user-{{ $user->id }}">
                        <td>
                            <div class="font-medium">{{ $user->name }}</div>
                            <div class="text-xs text-base-content/55">{{ $user->email }}</div>
                            @if ($isSelf)
                                <span class="mt-1 inline-block text-xs text-primary">Current account</span>
                            @endif
                        </td>
                        <td>
                            <x-ui::status-badge
                                :label="$user->is_active ? 'Active' : 'Inactive'"
                                :tone="$user->is_active ? 'success' : 'neutral'"
                            />
                        </td>
                        <td>
                            <select
                                class="d-select d-select-bordered d-select-sm min-w-36"
                                wire:change="changeRole({{ $user->id }}, $event.target.value)"
                                wire:loading.attr="disabled"
                                wire:target="changeRole"
                                @disabled($protectAdministrator)
                                aria-label="Role for {{ $user->name }}"
                            >
                                @foreach (\App\Enums\UserRole::cases() as $roleOption)
                                    <option value="{{ $roleOption->value }}" @selected($user->role === $roleOption)>
                                        {{ ucfirst($roleOption->value) }}
                                    </option>
                                @endforeach
                            </select>
                        </td>
                        <td>
                            <button
                                type="button"
                                class="d-btn d-btn-sm {{ $user->is_active ? 'd-btn-outline d-btn-error' : 'd-btn-outline d-btn-success' }}"
                                wire:click="toggleActive({{ $user->id }})"
                                wire:loading.attr="disabled"
                                wire:target="toggleActive({{ $user->id }})"
                                @disabled($user->is_active && $protectAdministrator)
                            >
                                {{ $user->is_active ? 'Deactivate' : 'Activate' }}
                            </button>
                        </td>
                        <td>
                            <details class="d-collapse d-collapse-arrow rounded-box border border-base-300 bg-base-100">
                                <summary class="d-collapse-title min-h-0 px-3 py-2 text-sm font-medium">Set a new password</summary>
                                <div class="d-collapse-content px-3 pb-3">
                                    <form wire:submit="setPassword({{ $user->id }})" class="grid gap-2 pt-1">
                                        <input
                                            wire:model="passwordForms.{{ $user->id }}.password"
                                            type="password"
                                            required
                                            minlength="12"
                                            autocomplete="new-password"
                                            placeholder="New password"
                                            aria-label="New password for {{ $user->name }}"
                                            class="d-input d-input-bordered d-input-sm w-full @error($passwordKey) d-input-error @enderror"
                                        >
                                        <input
                                            wire:model="passwordForms.{{ $user->id }}.password_confirmation"
                                            type="password"
                                            required
                                            minlength="12"
                                            autocomplete="new-password"
                                            placeholder="Confirm password"
                                            aria-label="Confirm new password for {{ $user->name }}"
                                            class="d-input d-input-bordered d-input-sm w-full"
                                        >
                                        @error($passwordKey)
                                            <span class="text-xs text-error" role="alert">{{ $message }}</span>
                                        @enderror
                                        <button
                                            type="submit"
                                            class="d-btn d-btn-primary d-btn-sm justify-self-start"
                                            wire:loading.attr="disabled"
                                            wire:target="setPassword({{ $user->id }})"
                                        >
                                            Update password
                                        </button>
                                    </form>
                                </div>
                            </details>
                        </td>
                    </tr>
                @endforeach
            </x-slot:body>
        </x-ui::data-table>
    </x-ui::panel>
</x-ui::page>
