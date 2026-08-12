<?php

namespace Lumina\Core\Tests;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Minimal owner model for standalone tests.
 *
 * The package intentionally leaves the owner model to the host application
 * (`config('auth.providers.users.model')`); `Site::factory()` needs one to
 * satisfy the `owner_id` foreign key, so the testbench environment swaps in
 * this lightweight stand-in.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string $password
 */
class User extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }
}
