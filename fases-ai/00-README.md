# Microfases para construir la app RGPD

## Objetivo

Estos archivos definen prompts listos para entregar a agentes IA futuros.

## Stack real del proyecto

- Laravel 13.x
- Livewire 4.x + Flux UI 2.x
- Laravel Fortify (autenticación)
- Spatie Laravel Permission (roles/permisos)
- Laravel Boost (guía de implementación)
- Pest 4.x (testing)
- Tailwind CSS 4.x
- Symfony Mailer v8 (envío de correos — ya disponible)

## Estado actual del proyecto (NO es greenfield)

El proyecto YA TIENE construido:

- ✅ Autenticación Fortify funcionando (login, registro, 2FA, verificación email)
- ✅ Spatie Permission migrado y activo (`permission_tables`)
- ✅ Layout `app.blade.php` con sidebar Flux
- ✅ Dashboard placeholder (`dashboard.blade.php`)
- ✅ Páginas Livewire admin: usuarios, roles, permisos (bajo `resources/views/pages/admin/`)
- ✅ Rutas admin con middleware de permisos (`routes/admin.php`)
- ✅ Rutas settings: perfil, apariencia, seguridad
- ✅ Tests Feature: Admin, Auth, Dashboard, Settings
- ✅ User model con traits `HasRoles`, `TwoFactorAuthenticatable`, `Notifiable`

El proyecto NO TIENE todavía:

- ❌ Modelos de dominio: MailAccount, Contact, Category, Template, Signature, Case/Expediente, ActivityLog
- ❌ Integración IMAP para recepción de correos
- ❌ Envío real SMTP desde la aplicación (Mailer está disponible pero no configurado para las cuentas de negocio)
- ❌ Módulo bandeja de entrada
- ❌ Módulo expedientes
- ❌ Módulo contactos
- ❌ Módulo configuración de comunicaciones
- ❌ Dashboard con datos reales, alertas e informes

## Regla transversal obligatoria: consultar y seguir Laravel Boost

Todos los agentes que ejecuten estas fases deben **consultar Laravel Boost** y seguirlo como guía activa de implementación, tomando en cuenta la configuración real que tenga el proyecto en ese momento.

Esto implica:

- revisar cómo está configurado realmente el proyecto antes de implementar,
- seguir la arquitectura, patrones, convenciones y criterios que Laravel Boost recomiende para esa configuración,
- usar Boost para definir componentes, organización de código y decisiones estructurales,
- evitar inventar una arquitectura paralela si Boost ya propone una forma consistente de resolverla,
- respetar herramientas o paquetes que el proyecto ya tenga activos o que Boost indique usar,
- mantener coherencia entre fases aunque las implemente agentes distintos.

## Criterio de decisión

Si un agente duda entre varias formas de implementar algo en Laravel, debe:

1. consultar primero Laravel Boost según el entorno real del proyecto,
2. priorizar la forma compatible con Laravel Boost,
3. mantener compatibilidad con Livewire Starter Kit, Livewire 4 y Flux UI,
4. documentar cualquier excepción real si Boost no cubre el caso.

## Orden de ejecución

Las fases son **secuenciales**, no paralelas, porque cada una depende de la anterior:

1. Ejecutar primero `00-imap-modelos-base.md` (IMAP + modelos de dominio base)
2. Luego `01-fundacion-app.md` (consolidar foundations existentes)
3. Luego `02` a `07` en orden (cada una depende de la fase 0)
4. Ejecutar `08-integracion-final.md` al final

> **Nota**: Las fases 02-07 pueden ejecutarse en paralelo entre sí una vez que 00 y 01 estén completas, siempre que respeten las reglas de propiedad.

## Regla de propiedad por fase

Cada fase debe tocar solo sus propios archivos y namespaces.

- `00` solo IMAP, modelos base, migraciones de dominio
- `01` solo consolidación de foundations (rutas, layout, convenciones, tests de smoke)
- `02` solo módulo administración
- `03` solo módulo contactos
- `04` solo configuración de comunicaciones
- `05` solo bandeja de entrada
- `06` solo expedientes
- `07` solo dashboard, alertas e informes
- `08` integración visual, navegación y cruces finales

## Regla importante

Las fases `02` a `07` NO deben editar menú lateral global, dashboard global final ni archivos de otras fases.
Si necesitan enlaces, usar rutas propias bajo prefijos estables y documentar cualquier punto de integración pendiente para `08`.
