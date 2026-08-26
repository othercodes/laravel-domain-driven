<?php

use App\IdentityAndAccess\APITokens\Domain\APIToken;
use App\IdentityAndAccess\Users\Domain\User;
use Laravel\Sanctum\Sanctum;

test('sanctum issues tokens through the APIToken model', function () {
    $user = User::factory()->create();

    $token = $user->createToken('test-token')->accessToken;

    expect($token)->toBeInstanceOf(APIToken::class)
        ->and($token->getTable())->toBe('iaa_api_tokens')
        ->and($token->tokenable_id)->toBe($user->id);
});

test('a uuid identified user can be authenticated by token', function () {
    $user = User::factory()->create();

    $this->withHeader('Authorization', 'Bearer '.$user->createToken('test-token')->plainTextToken)
        ->getJson('/api/user')
        ->assertOk()
        ->assertJsonPath('id', $user->id);
});

test('the api rejects unauthenticated requests', function () {
    $this->getJson('/api/user')->assertUnauthorized();
});

test('sanctum is not using its own personal access token model', function () {
    expect(Sanctum::personalAccessTokenModel())->toBe(APIToken::class);
});
