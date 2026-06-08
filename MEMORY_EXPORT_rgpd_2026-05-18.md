# Export de memoria — proyecto `rgpd`

Generado desde Engram el 2026-05-18.

> Nota: esto es un export legible y curado de la memoria persistida del proyecto. No es un volcado raw de toda la base interna de Engram.

## Resumen rápido

- El proyecto **no era greenfield**; ya tenía base Laravel/Livewire/Fortify/Spatie avanzada.
- Se **redefinieron las fases** para ajustarlas a la arquitectura real.
- Se avanzó en **Fase 03 (Contactos/Categorías)**, **Fase 04 (Configuración/Plantillas)**, **Fase 05 (Bandeja IMAP)** y **Fase 06 (Expedientes)**.
- Hubo varios hardenings importantes en **seguridad/autorización/XSS** dentro de Bandeja.

## Sesiones recientes detectadas

- 2026-05-17 14:10:18 — 57 observaciones
- 2026-05-17 19:45:46 — 8 observaciones
- 2026-05-17 19:48:54 — 6 observaciones

## Observaciones exportadas

### #953 — Exploración arquitectura RGPD existente
- **Tipo**: architecture
- **What**: Exploración completa de la arquitectura real del proyecto RGPD para ajustar `fases-ai/` a la realidad existente.
- **Why**: El proyecto ya tenía base funcional y había que dejar de tratarlo como greenfield.
- **Where**: `proyecto.md`, `fases-ai/*.md`, stack Laravel 13.8 + Livewire 4.3 + Flux 2.14 + Fortify + Spatie Permission.
- **Learned**:
  - Ya existían auth Fortify, permisos Spatie, layout, sidebar Flux, dashboard y páginas admin.
  - No existían todavía integración IMAP/SMTP real, bandeja, expedientes y varios modelos de dominio.
  - Convención clave: páginas Livewire en `resources/views/pages/`, uso de Flux, modales para CRUD y tablas con búsqueda.

### #955 — Redefiní fases para arquitectura Laravel existente
- **Tipo**: architecture
- **What**: Se redefinió el plan de fases agregando una **Fase 00** para IMAP + modelos base y reescribiendo la Fase 01.
- **Why**: La secuencia original duplicaba trabajo ya resuelto.
- **Where**: `fases-ai/00-README.md`, `fases-ai/00-imap-modelos-base.md`, `fases-ai/01-fundacion-app.md`, `fases-ai/02-08.md`.
- **Learned**: La secuencia correcta debía arrancar por IMAP/modelos base, no por foundations ya existentes.

### #980 — Verified Phase 04 communications status
- **Tipo**: discovery
- **What**: Se verificó que en Fase 04 solo existía el slice de **cuentas de correo**.
- **Why**: Había que confirmar qué estaba realmente construido antes de seguir.
- **Where**: `routes/configuracion.php`, `app/Models/MailAccount.php`, `app/Services/MailAccountConfigService.php`, `resources/views/pages/configuracion/⚡mail-accounts.blade.php`, tests de configuración.
- **Learned**:
  - No había implementación de firmas, plantillas ni parámetros/alertas en ese momento.
  - Tampoco había servicios explícitos de IMAP o envío.

### #982 — Implementé bloque base Template y Signature
- **Tipo**: pattern
- **What**: Se implementó la base de `Template` y `Signature` con migraciones, modelos, factories y permisos.
- **Why**: Era el siguiente micro-paso acordado de Fase 00.
- **Where**: `app/Models/Template.php`, `app/Models/Signature.php`, migraciones correspondientes, factories, seeder de permisos y `tests/Unit/Models/TemplateSignatureTest.php`.
- **Learned**: Se mantuvo un diseño simple con strings/booleans, sin enums todavía.

### #986 / #988 — Fase 03 Contactos y Categorías
- **Tipo**: decision + session_summary
- **What**: Se implementó el módulo CRUD de **Contactos** y **Categorías** con páginas Livewire full-page, rutas, permisos, sidebar y tests.
- **Why**: Correo y expedientes necesitaban una base consistente de contactos categorizados.
- **Where**: `routes/contactos.php`, `resources/views/pages/contactos/⚡contacts.blade.php`, `resources/views/pages/contactos/⚡categories.blade.php`, sidebar, seeder y tests `tests/Feature/Contactos/*`.
- **Learned**:
  - Patrón del repo: Livewire single-file con `⚡`, `Route::livewire()` y Flux para tablas/modales.
  - Flux free no incluye todos los iconos esperables.
  - Algunas factories chocaban con datos seeded; en tests convenía usar slugs explícitos.

### #994 — Estado Fase 04-05 RGPD mayo 2026
- **Tipo**: discovery
- **What**: Se relevó el estado de Fase 04 y 05.
- **Why**: Hacía falta coordinar trabajo paralelo sin pisadas.
- **Where**: proyecto completo.
- **Learned**:
  - Había 4 migraciones pendientes para `mail_messages`, `cases`, `case_milestones` y FK `case_id`.
  - No existía `ImapService` real, ni Jobs/Commands para sync IMAP.
  - El sidebar todavía no exponía Bandeja.

### #997 / #998 — Plantillas en Configuración de comunicaciones
- **Tipo**: session_summary + decision
- **What**: Se cerró **Plantillas** como slice vertical completo con ruta propia, página Livewire, navegación y tests.
- **Why**: Era el siguiente paso de menor riesgo luego de confirmar que Firmas ya estaba listo.
- **Where**: `routes/configuracion.php`, `resources/views/layouts/app/sidebar.blade.php`, `resources/views/pages/configuracion/⚡templates.blade.php`, `tests/Feature/Configuracion/TemplatesTest.php`.
- **Learned**:
  - `Template` ya tenía base de dominio lista.
  - Quedó pendiente el bloque de parámetros/alertas, que todavía requería definición funcional.

### #999 — Fase 05 Bandeja de entrada - implementation slice
- **Tipo**: architecture
- **What**: Se implementó el slice de **Bandeja** con servicio de sync IMAP, comando Artisan, permisos, rutas, página Livewire y 18 tests.
- **Why**: El usuario pidió una entrega operativa de bandeja sin meterse con otros frentes en paralelo.
- **Where**: `routes/bandeja.php`, `app/Services/Bandeja/ImapSyncService.php`, `app/Console/Commands/SyncMailMessages.php`, `resources/views/pages/bandeja/⚡inbox.blade.php`, seeder de permisos y `tests/Feature/Bandeja/*`.
- **Learned**:
  - Se usó `webklex/php-imap` con `MailAccount::imapConfig()`.
  - `Livewire::test()` necesitó `Livewire::actingAs($user)` para auth.
  - La migración de `mail_messages` estaba pendiente y hubo que correrla como parte del setup.

### #1000 / #1002 — Hardening de Fase 05
- **Tipo**: bugfix + session_summary
- **What**: Se corrigieron fugas multi-cuenta, riesgo XSS por HTML externo, falta de `wire:key` y se documentó el cierre de la sesión de trabajo.
- **Why**: La primera implementación de bandeja tenía fallas críticas de seguridad y aislamiento.
- **Where**: `resources/views/pages/bandeja/⚡inbox.blade.php`, `tests/Feature/Bandeja/InboxTest.php`, sidebar.
- **Learned**:
  - La validación de ownership no puede confiar en `selectedAccountId`; hubo que centralizarla.
  - NUNCA usar `{!! !!}` sobre HTML de correos externos.
  - Se agregó cobertura extra para aislamiento entre usuarios y rechazo de cuentas inactivas.

### #1012 / #1016 / #1019 — Ajustes posteriores en Bandeja
- **Tipo**: bugfix
- **What**:
  - Se reexpuso el acceso visible a Bandeja en el sidebar.
  - Se corrigió el parseo de fecha IMAP usando `Attribute::toDate()`.
  - Se ajustó la UI a columnas laterales desde `md` y se agregó render HTML básico sanitizado.
- **Why**: Sin entrada visible el módulo era difícil de validar; además había un 500 por parseo de fecha y la UI seguía demasiado apilada.
- **Where**: `resources/views/layouts/app/sidebar.blade.php`, `app/Services/Bandeja/ImapSyncService.php`, `tests/Feature/Bandeja/ImapSyncServiceTest.php`, `resources/views/pages/bandeja/⚡inbox.blade.php`.
- **Learned**:
  - En `webklex/php-imap` v6.2 la fecha llega como `Attribute` y la conversión correcta es `toDate()`.
  - En Livewire SFC conviene evitar la secuencia literal `?>` dentro de regex embebidas; se usó `\x3E` para no romper compilación cacheada.

### #1006 / #1015 / #1017 / #1018 — Fase 06 Expedientes
- **Tipo**: architecture + bugfix + decision + pattern
- **What**:
  - Micro-paso 1: rutas e índice base de Expedientes.
  - Micro-paso 2: create/edit en índice mediante `flux:modal`.
  - Micro-paso 3: detalle de expediente + timeline de hitos + alta mínima de hitos.
- **Why**: El usuario pidió avanzar en pasos chicos sin chocar con Fases 3, 4 y 5.
- **Where**: `routes/expedientes.php`, `resources/views/pages/expedientes/⚡index.blade.php`, `resources/views/pages/expedientes/⚡show.blade.php`, `tests/Feature/Expedientes/*`.
- **Learned**:
  - `CaseStatus` solo tenía `pending_client`, `pending_provider`, `concluded`.
  - Route model binding funcionó bien con `Expedient` aunque la tabla fuera `cases`.
  - Durante el micro-paso 2 se corrigió una migración rota en templates que frenaba tests con `RefreshDatabase`.

## Estado funcional consolidado

### Ya resuelto
- Base Laravel/Livewire/Fortify/Spatie existente y validada.
- Replanificación de fases para la realidad del repo.
- Módulo Contactos/Categorías.
- Slice de Plantillas.
- Slice operativo de Bandeja con sync IMAP y varios hardenings.
- Inicio sólido de Expedientes con índice, CRUD básico, detalle e hitos.

### Pendientes explícitos detectados en la memoria
- Parámetros/alertas de Configuración de comunicaciones.
- Puente claro entre Bandeja y Expedientes.
- Definir si sync IMAP quedará por scheduler, job recurrente o ambos.
- Evaluar sanitización HTML más rica si se quiere render más completo de correos.

## Archivos clave mencionados repetidamente

- `resources/views/pages/bandeja/⚡inbox.blade.php`
- `app/Services/Bandeja/ImapSyncService.php`
- `app/Console/Commands/SyncMailMessages.php`
- `resources/views/pages/expedientes/⚡index.blade.php`
- `resources/views/pages/expedientes/⚡show.blade.php`
- `resources/views/pages/configuracion/⚡templates.blade.php`
- `resources/views/pages/contactos/⚡contacts.blade.php`
- `resources/views/pages/contactos/⚡categories.blade.php`
- `resources/views/layouts/app/sidebar.blade.php`
- `database/seeders/RolesAndPermissionsSeeder.php`

## Trazabilidad

Observaciones incluidas en este export:

`#953, #955, #980, #982, #986, #988, #994, #997, #998, #999, #1000, #1002, #1006, #1012, #1015, #1016, #1017, #1018, #1019`
