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

## Estado actual verificado del proyecto (NO es greenfield)

Verificado el **2026-06-08** contra código, rutas, migraciones, base SQLite y tests locales.

El proyecto YA TIENE construido:

- ✅ Repositorio Git activo en `main`, sincronizado con `origin/main`.
- ✅ Autenticación Fortify funcionando (login, registro, 2FA, verificación email).
- ✅ Spatie Permission migrado y activo (`permission_tables`) con roles/permisos.
- ✅ Layout `app.blade.php` con navegación Flux.
- ✅ Dashboard placeholder (`dashboard.blade.php`).
- ✅ Páginas Livewire admin: usuarios, roles, permisos (`resources/views/pages/admin/`).
- ✅ Módulo contactos: categorías y contactos (`resources/views/pages/contactos/`).
- ✅ Módulo configuración de comunicaciones: cuentas de correo, firmas y plantillas (`resources/views/pages/configuracion/`).
- ✅ Módulo bandeja de entrada (`resources/views/pages/bandeja/⚡inbox.blade.php`).
- ✅ Módulo expedientes: índice y detalle (`resources/views/pages/expedientes/`).
- ✅ Modelos de dominio base: `MailAccount`, `MailMessage`, `Category`, `Contact`, `Template`, `Signature`, `Expedient`, `CaseMilestone`.
- ✅ Servicio de sincronización IMAP base (`App\Services\Bandeja\ImapSyncService`) y comando `SyncMailMessages`.
- ✅ Tests Feature/Unit para Auth, Admin, Settings, Mail, Contactos, Configuración, Bandeja, Expedientes y modelos.
- ✅ Dependencias instaladas (`vendor/`, `node_modules/`) y build Vite presente (`public/build/manifest.json`).

Puntos pendientes o NO verdes:

- ❌ La suite no está completamente verde: `php artisan test --compact --filter=ConfiguracionAccessTest` falla en 2 tests por validación `smtp_connection` que intenta conectar a SMTP.
- ❌ Hay una migración pendiente: `2026_05_17_220000_add_user_id_to_templates`.
- ❌ `public/storage` no está linkeado.
- ❌ El dashboard sigue siendo placeholder; no hay métricas, alertas ni informes reales.
- ❌ El envío real SMTP desde cuentas de negocio aún no está confirmado como flujo productivo completo.
- ❌ La integración final visual/navegación/cruces entre módulos todavía necesita revisión de cierre.

Nota operativa: se limpió cache con `php artisan optimize:clear`; si reaparecen errores con rutas absolutas antiguas en `storage/framework/views`, volver a limpiar vistas/cache antes de diagnosticar UI.

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
