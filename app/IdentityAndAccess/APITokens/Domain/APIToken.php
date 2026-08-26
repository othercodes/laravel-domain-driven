<?php

declare(strict_types=1);

namespace App\IdentityAndAccess\APITokens\Domain;

use Laravel\Sanctum\PersonalAccessToken;

/**
 * Class APIToken
 *
 * Owns the token table instead of publishing Sanctum's migration, which
 * declares a `morphs('tokenable')` and would therefore store a bigint that
 * cannot hold the uuid identifiers this application gives its users.
 *
 * Registered via Sanctum::usePersonalAccessTokenModel() in the bounded
 * context service provider.
 *
 * @property string $tokenable_type
 * @property string $tokenable_id
 * @property string $name
 * @property string $token
 * @property string|null $abilities
 *
 * @author Unay Santisteban <usantisteban@othercode.io>
 */
class APIToken extends PersonalAccessToken
{
    protected $table = 'iaa_api_tokens';
}
