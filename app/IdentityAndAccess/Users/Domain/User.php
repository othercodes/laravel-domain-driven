<?php

namespace App\IdentityAndAccess\Users\Domain;

use App\IdentityAndAccess\Users\Domain\Events\UserCreated;
use App\IdentityAndAccess\Users\Domain\Events\UserDeleted;
use App\IdentityAndAccess\Users\Domain\Events\UserEmailUpdated;
use App\IdentityAndAccess\Users\Domain\Events\UserNameUpdated;
use App\IdentityAndAccess\Users\Domain\Factories\UserFactory;
use App\Shared\Domain\HasDomainEvents;
use App\Shared\Domain\HasProfilePhoto;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Hash;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Class User
 *
 * @property string $id
 * @property string $name
 * @property string $email
 * @property string|null $profile_photo_path
 * @property string $profile_photo_url
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_confirmed_at
 *
 * @method static UserFactory factory()
 *
 * @author Unay Santisteban <usantisteban@othercode.io>
 */
class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens;
    use HasDomainEvents;
    use HasFactory;
    use HasProfilePhoto;
    use HasUuids;
    use Notifiable;
    use TwoFactorAuthenticatable;

    /**
     * Creates a new User and registers the UserCreated domain event.
     *
     * The identifier is assigned up front so the event can carry it before
     * the aggregate is persisted. A caller may supply its own id, which is set
     * explicitly rather than through $fillable, so mass assignment is never
     * a way to choose an identifier.
     *
     * Publishing the registered events is the application layer's job, see
     * CreateUser.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function new(array $attributes = []): self
    {
        $user = new self(Arr::except($attributes, ['id']));
        $user->id = $attributes['id'] ?? $user->newUniqueId();
        $user->registerDomainEvent(UserCreated::new($user->id));

        return $user;
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_recovery_codes',
        'two_factor_secret',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var list<string>
     */
    protected $appends = [
        'profile_photo_url',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }

    public function updateName(string $name): self
    {
        if ($name !== $this->name) {
            $this->forceFill([
                'name' => $name,
            ]);

            $this->registerDomainEvent(UserNameUpdated::new($this->id));
        }

        return $this;
    }

    public function updateEmail(string $email): self
    {
        if ($email !== $this->email) {
            $this->forceFill([
                'email' => $email,
                'email_verified_at' => null,
            ]);

            $this->registerDomainEvent(UserEmailUpdated::new($this->id));
        }

        return $this;
    }

    public function updatePassword(string $password): self
    {
        $this->forceFill([
            'password' => Hash::make($password),
            'password_user_defined' => true,
        ]);

        return $this;
    }

    public function toBeDeleted(): self
    {
        $this->registerDomainEvent(UserDeleted::new($this->id));

        return $this;
    }
}
