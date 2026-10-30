<?php

namespace App\Http\Requests\Roles;

use App\Enums\Permission;
use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Creating (POST /roles) or editing (PATCH /roles/{role}) a role: a name and the
 * whole set of permissions.
 */
class RoleRequest extends FormRequest
{
    // Whether you may manage this role at all, before validating: a member without
    // roles.manage gets 403, not a list of field errors. The controller then checks
    // the permissions being granted.
    public function authorize(): bool
    {
        $role = $this->route('role');

        return $role instanceof Role
            ? $this->user()->can('update', $role)
            : $this->user()->can('create', Role::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // No control or invisible characters: "Admin" plus a zero-width space would
            // pass the unique index and look exactly like Admin on the members page.
            'name' => ['required', 'string', 'max:50', 'not_regex:/[\p{Cc}\p{Cf}]/u'],
            'permissions' => ['present', 'array', 'max:'.count(Permission::cases())],
            'permissions.*' => ['string', 'distinct', Rule::enum(Permission::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['name.not_regex' => 'Use only visible characters in the name.'];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $editing = $this->route('role');

                // Within the current organization (the scope), ignoring case like the unique index.
                $taken = Role::query()
                    ->whereRaw('lower(name) = lower(?)', [$this->validated('name')])
                    ->when($editing instanceof Role, fn ($query) => $query->whereKeyNot($editing->id))
                    ->exists();
                if ($taken) {
                    $validator->errors()->add('name', 'Another role already has this name.');
                }

                // Approximate: two creates at once may both pass at 49. Per-user throttling
                // keeps the overshoot small, and it only bounds rows, not access.
                if (! $editing instanceof Role && Role::query()->count() >= Role::MAX_PER_ORGANIZATION) {
                    $validator->errors()->add('name', 'An organization can have at most '.Role::MAX_PER_ORGANIZATION.' roles.');
                }
            },
        ];
    }

    /**
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return array_map(fn (string $value) => Permission::from($value), $this->validated('permissions'));
    }
}
