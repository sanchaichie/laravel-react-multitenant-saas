<?php

namespace App\Actions\Account;

use App\Models\User;

class UpdateProfile
{
    /**
     * @param  array{name: string}  $data
     */
    public function handle(User $user, array $data): User
    {
        $user->update(['name' => $data['name']]);

        return $user;
    }
}
