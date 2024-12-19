<?php

declare(strict_types=1);

namespace App\IdentityAndAccess\Users\Domain\Contracts;

use App\IdentityAndAccess\Users\Domain\User;
use Illuminate\Support\Collection;

interface UserRepository
{
    public function find(string $id): ?User;

    public function match(array $criteria): Collection;

    public function save(User $user): User;

    public function delete(User $user): void;
}
