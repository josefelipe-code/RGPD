# Prompt — Fase 02: módulo Administración

## Objetivo

Construir el módulo de Administración de forma aislada.

## Dependencias

- **Fase 00**: Modelos base, enums, servicios IMAP/SMTP (ya existen)
- **Fase 01**: Rutas modulares, layout con menú, convenciones (ya existen)
- **Pre-existente**: Fortify, Spatie Permission, User model, admin pages skeleton

## Contexto del proyecto

El proyecto YA TIENE:
- Spatie Permission instalado y migrado
- Rutas admin en `routes/admin.php` con middleware `can:admin.acceder`
- Páginas Livewire skeleton: `pages::admin.users`, `pages::admin.roles`, `pages::admin.permissions`
- User model con trait `HasRoles`

Esta fase debe COMPLETAR esas páginas skeleton con CRUD funcional, no crearlas desde cero.

## Tu misión

Implementá el módulo Administración con estas secciones:

- Usuarios
- Roles
- Permisos
- Registro de actividad

## Regla obligatoria de implementación

Antes de implementar, explorá el proyecto real para identificar qué dejó preparado la fundación y qué ya aporta el stack activo. Después consultá **Laravel Boost** para seguir la configuración real del proyecto y tomar decisiones compatibles con su entorno activo. Usalo para definir arquitectura, organización de acciones, validaciones, componentes Livewire y estructura del módulo, respetando paquetes o patrones presentes en el proyecto (por ejemplo Spatie, Fortify u otros si aplican).

## Alcance funcional

1. CRUD de usuarios.
2. CRUD de roles.
3. CRUD o gestión de permisos.
4. Asignación flexible de roles y permisos a usuarios.
5. Vista de registro de actividad del sistema.
6. Registrar trazas administrativas y operativas cuando aplique desde este módulo.

## Reglas de UI

- Formularios crear/editar en modal.
- Listados en tabla.
- Búsqueda sobre todo el contenido de la tabla.
- Filtros acumulables visibles como etiquetas.

## Restricciones de propiedad

- Tocá SOLO archivos del módulo administración y los modelos compartidos estrictamente necesarios.
- NO implementes lógica de contactos, configuración, bandeja, dashboard ni expedientes.
- NO modifiques menú lateral final global; si hace falta, dejá rutas y páginas listas para que integración final las enlace.
- NO dupliques capacidades que ya estén resueltas por paquetes o configuración activa del proyecto; integralas siguiendo Laravel Boost.

## Reglas de negocio obligatorias

- Administrador con acceso total.
- Debe existir permiso específico para eliminar expediente, aunque el módulo expediente no se implemente acá.
- Debe existir trazabilidad de acciones del sistema.

## Entregables esperados

- Páginas Livewire del módulo administración
- Gestión funcional de usuarios/roles/permisos
- Registro de actividad consultable
- Tests del módulo

## Definición de terminado

El módulo funciona aislado, con rutas propias y sin depender de que existan implementados los otros módulos funcionales.
