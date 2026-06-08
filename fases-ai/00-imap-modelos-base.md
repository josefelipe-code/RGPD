# Prompt — Fase 00: IMAP + Modelos Base

## Objetivo

Crear la capa de integración IMAP/SMTP y los modelos de dominio base que TODOS los módulos funcionales necesitan. Esta es la fase fundacional real — sin ella, ningún módulo funcional puede operar.

## Contexto del proyecto

Este proyecto YA tiene:
- Laravel 13 + Livewire 4 + Flux UI 2
- Fortify (autenticación funcionando)
- Spatie Permission (roles/permisos migrados)
- Layout con sidebar Flux
- Páginas admin (usuarios, roles, permisos)
- Dashboard placeholder
- Symfony Mailer v8 disponible para envío

NO tiene:
- Integración IMAP para recibir correos
- Modelos de dominio (MailAccount, Contact, Template, Signature, Case, ActivityLog)
- Ningún módulo funcional

## Tu misión

Implementá SOLO la infraestructura de correo y los modelos de dominio base. No desarrolles módulos funcionales ni UI.

## Regla obligatoria de implementación

Antes de implementar, explorá el proyecto real para identificar qué paquetes están activos y qué convenciones existen. Después consultá **Laravel Boost** para entender cómo continuar en sintonía con ese entorno real.

## Alcance

### 1. Integración IMAP (recepción de correos)

- Investigar y seleccionar un paquete IMAP compatible con Laravel 13 (ej. `webklex/laravel-imap` o alternativa vigente).
- Instalar y configurar el paquete con su provider.
- Crear un servicio `ImapService` o equivalente que encapsule:
  - Conexión a cuentas IMAP configuradas
  - Fetch de mensajes de una cuenta
  - Marcado de mensajes (leído, movido a carpeta)
  - Movimiento de mensajes entre carpetas (para sincronización con estados de expediente)
- NO implementar polling automático ni jobs todavía — solo el servicio usable.

### 2. Configuración SMTP (envío de correos)

- Configurar `config/mail.php` para permitir envío desde múltiples cuentas de correo (no solo la cuenta global del `.env`).
- Crear un servicio `MailSender` o equivalente que permita:
  - Enviar un mail desde una cuenta específica con su firma
  - Adjuntar firma HTML al cuerpo del mensaje
  - Soporte para TO, CC, BCC (necesario para trazabilidad con copia oculta de soporte)

### 3. Modelos de dominio base

Crear los siguientes modelos con sus migraciones, factories y relaciones:

#### MailAccount
- `id`, `email`, `display_name`, `imap_host`, `imap_port`, `imap_encryption`, `imap_username`, `imap_password` (encriptado)
- `smtp_host`, `smtp_port`, `smtp_encryption`, `smtp_username`, `smtp_password` (encriptado)
- `is_active`, `sort_order`
- Relación: `hasMany(Template)`, `hasMany(Signature)`, `hasMany(Case)`

#### Contact
- `id`, `name`, `email`, `phone`, `company`, `notes`
- Relación: `belongsToMany(Category)`, `hasMany(Case)` (como proveedor)

#### Category
- `id`, `name`, `slug`, `description`, `color`
- Categorías seed: proveedores, soporte, administración, otros
- Relación: `belongsToMany(Contact)`

#### Template
- `id`, `mail_account_id` (nullable), `name`, `subject`, `body`, `type` (cliente/proveedor/general), `context` (nullable)
- Relación: `belongsTo(MailAccount)`

#### Signature
- `id`, `mail_account_id`, `name`, `html_body`, `is_default`
- Relación: `belongsTo(MailAccount)`

#### Case (Expediente)
- `id`, `case_number` (único, auto-generado), `sender_email`, `sender_phone`, `provider_id` (Contact), `mail_account_id`, `assigned_user_id` (User), `status` (enum: pendiente_cliente, pendiente_proveedor, concluido), `request_type` (nullable), `opened_at`, `closed_at` (nullable)
- Relación: `belongsTo(MailAccount)`, `belongsTo(User, 'assigned_user_id')`, `belongsTo(Contact, 'provider_id')`, `hasMany(CaseMilestone)`, `hasMany(MailMessage)`

#### CaseMilestone (hitos del expediente)
- `id`, `case_id`, `user_id`, `action` (enum: opened, replied_client, replied_provider, closed), `notes` (nullable), `created_at`
- Relación: `belongsTo(Case)`, `belongsTo(User)`

#### MailMessage (correo asociado a expediente)
- `id`, `case_id` (nullable — null si aún no asociado), `mail_account_id`, `message_id` (IMAP UID o Message-ID), `subject`, `from_email`, `from_name`, `body_html`, `body_text`, `received_at`, `direction` (enum: incoming, outgoing), `status` (enum: new, associated, replied_client, replied_provider, pending_review, discarded)
- Relación: `belongsTo(Case)`, `belongsTo(MailAccount)`

### 4. Convenciones y contratos

- Definir la convención de nombres para los modelos (singular, PascalCase, bajo `App\Models`)
- Crear enums PHP para: `CaseStatus`, `MilestoneAction`, `MailDirection`, `MailMessageStatus`, `TemplateType`
- Dejar contratos/interfaces claros para:
  - `ImapProvider` (abstracción del paquete IMAP concreto)
  - `MailSenderInterface` (abstracción del envío)

### 5. Seeders base

- Seeder de categorías de contacto (proveedores, soporte, administración, otros)
- Seeder de permisos mínimos para módulos futuros (si no existen ya)

## Restricciones

- NO implementes UI ni Livewire components
- NO implementes lógica de bandeja, expedientes ni dashboard
- NO crees controladores ni rutas
- NO implementes jobs de polling IMAP (solo el servicio)
- Los modelos deben ser "dumb" — sin lógica de negocio compleja, solo relaciones y casts

## Criterios de diseño

- Cada modelo en su propio archivo bajo `app/Models/`
- Enums bajo `app/Enums/`
- Servicios bajo `app/Services/`
- Migraciones en orden cronológico correcto
- Factories bajo `database/factories/`
- Relaciones Eloquent bidireccionales donde aplique
- Passwords de IMAP/SMTP encriptados con `crypt()` o accessor/mutator

## Entregables esperados

- Paquete IMAP instalado y configurado
- Servicio ImapService
- Servicio MailSender
- 8 modelos con migraciones, factories y relaciones
- 5 enums de dominio
- Seeders base
- Tests de modelos (relaciones, casts, factories)

## Definición de terminado

Los modelos existen, las migraciones corren, los factories generan datos válidos, el servicio IMAP puede conectarse a una cuenta real (test de conexión), y el servicio MailSender puede enviar un mail de prueba. No hay UI ni lógica de negocio todavía.
