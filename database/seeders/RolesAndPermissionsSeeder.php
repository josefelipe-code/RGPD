<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Seed the application's roles and permissions.
     */
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = collect([
            'admin.acceder',
            'usuarios.ver',
            'usuarios.crear',
            'usuarios.actualizar',
            'usuarios.eliminar',
            'roles.ver',
            'roles.crear',
            'roles.actualizar',
            'roles.eliminar',
            'permisos.ver',
            'permisos.crear',
            'permisos.actualizar',
            'permisos.eliminar',
            'configuracion.acceder',
            'cuentas-correo.ver',
            'cuentas-correo.crear',
            'cuentas-correo.actualizar',
            'cuentas-correo.eliminar',
            'plantillas.ver',
            'plantillas.crear',
            'plantillas.actualizar',
            'plantillas.eliminar',
            'firmas.ver',
            'firmas.crear',
            'firmas.actualizar',
            'firmas.eliminar',
            'contactos.ver',
            'contactos.crear',
            'contactos.actualizar',
            'contactos.eliminar',
            'categorias.ver',
            'categorias.crear',
            'categorias.actualizar',
            'categorias.eliminar',
            'mensajes-correo.ver',
            'mensajes-correo.crear',
            'mensajes-correo.actualizar',
            'mensajes-correo.eliminar',
            'bandeja.ver',
            'bandeja.sincronizar',
            'bandeja.clasificar',
            'expedientes.ver',
            'expedientes.crear',
            'expedientes.actualizar',
            'expedientes.eliminar',
            'hitos.ver',
            'hitos.crear',
            'hitos.actualizar',
            'hitos.eliminar',
        ])->map(fn (string $permission): Permission => Permission::findOrCreate($permission, 'web'));

        Role::findOrCreate('Super Administrador', 'web')->syncPermissions($permissions);
    }
}
