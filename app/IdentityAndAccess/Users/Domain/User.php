<?php

namespace App\IdentityAndAccess\Users\Domain;

use App\IdentityAndAccess\Users\Domain\Events\UserDeleted;
use App\IdentityAndAccess\Users\Domain\Events\UserEmailUpdated;
use App\IdentityAndAccess\Users\Domain\Events\UserNameUpdated;
use App\IdentityAndAccess\Users\Infrastructure\Persistence\UserFactory;
use App\Shared\Domain\HasDomainEvents;
use App\Shared\Domain\HasProfilePhoto;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Hash;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * Class User
 *
 * @property string $id
 * @property string $name
 * @property string $email
 * @property string|null $profile_photo_path
 * @property string $profile_photo_url
 *
 * @method static UserFactory factory()
 *
 * @author Unay Santisteban <usantisteban@othercode.io>
 */
class User extends Authenticatable implements MustVerifyEmail
{
    use HasDomainEvents;
    use HasFactory;
    use HasProfilePhoto;
    use Notifiable;
    use TwoFactorAuthenticatable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
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
     * @var array<int, string>
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

            $this->registerDomainEvent(new UserNameUpdated($this));
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

            $this->registerDomainEvent(new UserEmailUpdated($this));
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
        $this->registerDomainEvent(new UserDeleted($this->id));

        return $this;
    }
}
