<?php

namespace App\Models;

use App\Modules\IAM\Domain\IdentityState;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;

/** @property IdentityState $estado */
#[Fillable(['nombre_mostrado', 'correo_normalizado', 'estado'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasUlids;

    protected $table = 'iam_identidades';

    public const CREATED_AT = 'creado_en';

    public const UPDATED_AT = 'actualizado_en';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['estado' => IdentityState::class, 'ultimo_acceso_en' => 'datetime'];
    }

    /** @return Attribute<string, never> */
    protected function name(): Attribute
    {
        return Attribute::make(get: fn () => $this->nombre_mostrado);
    }

    /** @return Attribute<?string, never> */
    protected function email(): Attribute
    {
        return Attribute::make(get: fn () => $this->correo_normalizado);
    }
}
