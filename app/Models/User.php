<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Delete the lines of this user's own recipes before the database cascades the user's
     * preparations, products, units and private ingredients. Postgres checks the recipe_lines
     * component/unit foreign keys at the end of each cascaded delete, not of the whole user
     * deletion, so without this, a product line using the user's own preparation (or unit)
     * makes the deletion fail depending on cascade order. Once these lines are gone nothing
     * else can reference the user's private rows (RecipeLine's saving() invariants), so the
     * cascade completes. One query, whatever the number of recipes.
     *
     * Atomicity is handled by delete() below, so callers need no transaction of their own.
     */
    protected static function booted(): void
    {
        static::deleting(function (User $user): void {
            RecipeLine::query()
                ->whereIn('parent_preparation_id', Preparation::query()->select('id')->where('owner_id', $user->id))
                ->orWhereIn('product_id', Product::query()->select('id')->where('owner_id', $user->id))
                ->delete();
        });
    }

    /**
     * Delete the user, atomically.
     *
     * @return bool|null
     *
     * @throws \Throwable e.g. a QueryException from a foreign key; the transaction rolls everything back first
     */
    public function delete()
    {
        // The `deleting` hook pre-deletes recipe lines in a separate statement, so the whole deletion must be one transaction.
        return DB::transaction(fn () => parent::delete());
    }

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
            'two_factor_confirmed_at' => 'datetime',
        ];
    }
}
