<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private function identifier(Blueprint $table, string $name = 'id'): void
    {
        $table->char($name, 26)->charset('ascii')->collation(DB::getDriverName() === 'sqlite' ? 'BINARY' : 'ascii_bin');
    }

    public function up(): void
    {
        foreach (['iam_roles', 'iam_identidad_roles', 'iam_rol_permisos'] as $name) {
            if (Schema::hasTable($name) && DB::table($name)->exists()) {
                throw new RuntimeException('Populated RBAC baseline requires explicit reconciliation before adoption.');
            }
        }
        $baselinePermissions = [
            '01J00000000000000000000001' => 'residencias.evidencias.revisar',
            '01J00000000000000000000002' => 'residencias.liberaciones.resolver',
            '01J00000000000000000000003' => 'laboratorios.solicitudes.revisar',
            '01J00000000000000000000004' => 'inventarios.articulos.actualizar',
            '01J00000000000000000000005' => 'inventarios.administrar',
            '01J00000000000000000000006' => 'publicaciones.administrar',
        ];
        if (Schema::hasTable('iam_permisos')) {
            foreach (DB::table('iam_permisos')->get(['id', 'codigo']) as $permission) {
                if (($baselinePermissions[$permission->id] ?? null) !== $permission->codigo) {
                    throw new RuntimeException('Unknown permission baseline requires explicit reconciliation before adoption.');
                }
            }
        }
        if (! Schema::hasTable('iam_roles')) {
            Schema::create('iam_roles', function (Blueprint $table): void {
                $this->identifier($table);
                $table->primary('id');
                $table->string('codigo', 100)->unique();
                $table->string('nombre', 160);
                $table->text('descripcion')->nullable();
                $table->dateTime('creado_en', 6);
                $table->dateTime('actualizado_en', 6);
            });
        }
        if (! Schema::hasColumn('iam_roles', 'version')) {
            Schema::table('iam_roles', fn (Blueprint $table) => $table->unsignedInteger('version')->default(1));
        }
        if (! Schema::hasTable('iam_permisos')) {
            Schema::create('iam_permisos', function (Blueprint $table): void {
                $this->identifier($table);
                $table->primary('id');
                $table->string('codigo', 160)->unique();
                $table->text('descripcion')->nullable();
                $table->dateTime('creado_en', 6);
            });
        }
        if (! Schema::hasTable('iam_identidad_roles')) {
            Schema::create('iam_identidad_roles', function (Blueprint $table): void {
                foreach (['id_identidad', 'id_rol', 'id_identidad_otorgante'] as $column) {
                    $this->identifier($table, $column);
                }
                $table->dateTime('otorgado_en', 6);
                $table->dateTime('expira_en', 6)->nullable();
                $table->primary(['id_identidad', 'id_rol']);
                $table->foreign('id_identidad')->references('id')->on('iam_identidades');
                $table->foreign('id_rol')->references('id')->on('iam_roles');
            });
        }
        if (! Schema::hasTable('iam_rol_permisos')) {
            Schema::create('iam_rol_permisos', function (Blueprint $table): void {
                $this->identifier($table, 'id_rol');
                $this->identifier($table, 'id_permiso');
                $table->dateTime('otorgado_en', 6);
                $table->primary(['id_rol', 'id_permiso']);
                $table->foreign('id_rol')->references('id')->on('iam_roles');
                $table->foreign('id_permiso')->references('id')->on('iam_permisos');
            });
        }
        if (! Schema::hasColumn('iam_rol_permisos', 'alcance')) {
            Schema::table('iam_rol_permisos', fn (Blueprint $table) => $table->enum('alcance', ['SELF', 'GLOBAL'])->default('SELF'));
        }
        Schema::create('iam_controles', function (Blueprint $table): void {
            $this->identifier($table);
            $table->primary('id');
            $table->string('codigo', 30)->unique();
        });
        Schema::create('iam_historial_roles', function (Blueprint $table): void {
            $this->identifier($table);
            $table->primary('id');
            $this->identifier($table, 'id_identidad');
            $this->identifier($table, 'id_rol');
            $table->string('accion', 30);
            $table->dateTime('ocurrido_en', 6);
            $table->dateTime('expira_en', 6)->nullable();
            $table->unsignedInteger('version_autorizacion');
            $table->index(['id_identidad', 'ocurrido_en']);
        });
        DB::table('iam_controles')->insert(['id' => (string) Str::ulid(), 'codigo' => 'RBAC']);
        foreach (config('authorization.permissions') as $code => $scopes) {
            DB::table('iam_permisos')->insert(['id' => (string) Str::ulid(), 'codigo' => $code, 'descripcion' => $code, 'creado_en' => now()]);
        }
        $permissions = DB::table('iam_permisos')->pluck('id', 'codigo');
        foreach (config('authorization.roles') as $code => $grants) {
            $role = (string) Str::ulid();
            DB::table('iam_roles')->insert(['id' => $role, 'codigo' => $code, 'nombre' => $code, 'creado_en' => now(), 'actualizado_en' => now(), 'version' => 1]);
            foreach ($grants as $permission => $scope) {
                DB::table('iam_rol_permisos')->insert(['id_rol' => $role, 'id_permiso' => $permissions[$permission], 'alcance' => $scope, 'otorgado_en' => now()]);
            }
        }
    }

    public function down(): void
    {
        throw new RuntimeException('RBAC requires roll-forward; use a disposable database for fresh tests.');
    }
};
