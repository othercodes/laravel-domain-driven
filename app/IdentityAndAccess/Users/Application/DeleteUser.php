<?php

namespace App\IdentityAndAccess\Users\Application;

use App\IdentityAndAccess\Users\Domain\User;
use Laravel\Jetstream\Contracts\DeletesUsers;

class DeleteUser implements DeletesUsers
{
    /**
     * Delete the given user.
     */
    public function delete(User $user): void
    {
        $user->deleteProfilePhoto();
        $user->delete();
    }
}
