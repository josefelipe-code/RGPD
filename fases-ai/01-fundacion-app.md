# Prompt — Fase 01: Consolidación de Foundations

## Objetivo

Consolidar y completar la base técnica del proyecto, partiendo de lo que YA EXISTE (autenticación, permisos, layout, admin pages) y agregando lo que falta para que los módulos funcionales puedan trabajar en paralelo.

## Contexto del proyecto

Este proyecto YA TIENE construido (no reinventar):
- ✅ Autenticación Fortify (login, registro, 2FA, verificación email)
- ✅ Spatie Permission con tablas migradas
- ✅ Layout `app.blade.php` con sidebar Flux
- ✅ Dashboard placeholder
- ✅ Páginas admin: usuarios, roles, permisos (con middleware de permisos)
- ✅ Rutas settings: perfil, apariencia, seguridad
- ✅ User model con `HasRoles`, `TwoFactorAuthenticatable`, `Notifiable`
- ✅ Tests Feature: Admin, Auth, Dashboard, Settings

De la Fase 00 (previa) YA EXISTE:
- ✅ Modelos de dominio: MailAccount, Contact, Category, Template, Signature, Case, CaseMilestone, MailMessage
- ✅ Enums de dominio
- ✅ Servicios ImapService y MailSender
- ✅ Integración IMAP y SMTP configurada

## Tu misión

Completá SOLO lo que falta de la fundación transversal. No desarrolles módulos funcionales completos.

## Regla obligatoria de implementación

Antes de implementar, explorá el proyecto para identificar qué quedó resuelto por la Fase 00 y qué ya aporta el stack activo. Después consultá **Laravel Boost** para continuar en sintonía con ese entorno real.

## Alcance

### 1. Completar estructura de rutas modular

Definir prefijos de ruta para cada módulo bajo `routes/`:
- `routes/admin.php` — ya existe, verificar que cubra administración
- Crear stubs o archivos vacíos para futuros módulos:
  - `routes/contacts.php`
  - `routes/communications.php`
  - `routes/inbox.php`
  - `routes/cases.php`
  - `routes/reports.php`
- Registrar cada uno en `bootstrap/app.php` o donde corresponda

### 2. Consolidar layout autenticado

El layout ya existe (`resources/views/layouts/app.blade.php`). Completar:
- Menú lateral con estructura base (secciones colapsables, placeholders para módulos futuros)
- La estructura del menú debe coincidir con la navegación definida en `proyecto.md`:
  - Dashboard
  - Bandeja de entrada
  - Expedientes
  - Contactos (con sub-sección Categorías)
  - Configuración (con sub-secciones)
  - Administrador (con sub-secciones)
- Cada ítem debe respetar permisos con `@can` o directivas de Spatie
- Los ítems de módulos no implementados deben aparecer deshabilitados o con placeholder

### 3. Convención de rutas y módulos

Documentar en un archivo `docs/convenciones.md` (o similar):
- Prefijo de ruta por módulo
- Namespace de Livewire components por módulo
- Patrón de naming para vistas (`resources/views/pages/{modulo}/`)
- Cómo agregar un módulo nuevo sin romper otros

### 4. Tests de smoke

- Verificar que los tests existentes pasan
- Agregar test de smoke para:
  - Usuario autenticado puede acceder al dashboard
  - Usuario sin permisos no accede a `/admin`
  - Layout renderiza correctamente el menú lateral
  - Las rutas de módulos futuros existen (aunque devuelvan 404 o placeholder)

### 5. Seeder de usuario administrador

- Crear seeder que garantice un usuario admin con rol y permisos completos
- Este usuario debe poder acceder a todo el sistema
- Documentar credenciales en `.env.example` o README

### 6. Documentar qué aporta el kit y qué se construyó

Crear un breve documento `docs/hallazgos-foundation.md` que registre:
- Qué trajo el Livewire Starter Kit
- Qué se agregó en Fase 00 (IMAP + modelos)
- Qué se completó en esta Fase 01
- Qué queda pendiente para fases siguientes

## Restricciones

- NO implementes lógica completa de bandeja, expedientes, contactos ni configuración
- NO reemplaces ni rehagas piezas que ya funcionan (Fortify, Spatie, admin pages existentes)
- NO decidas UX específica de módulos
- El menú lateral debe ser un esqueleto — no implementar la lógica de cada ítem

## Criterios de diseño

- Todo debe quedar listo para trabajo paralelo en fases 02-07
- Evitar archivos "Dios"
- Preferir namespaces por módulo
- La base debe quedar alineada con Laravel Boost

## Entregables esperados

- Archivos de rutas modulares (stubs o funcionales)
- Menú lateral con estructura completa (con placeholders)
- Documento de convenciones de extensión
- Tests de smoke pasando
- Seeder de admin
- Documento de hallazgos

## Definición de terminado

La app levanta, autentica, tiene menú lateral con estructura completa (aunque algunos ítems sean placeholders), las rutas modulares están definidas, los tests pasan, y los módulos futuros pueden crearse sin tocar entre sí archivos comunes.
