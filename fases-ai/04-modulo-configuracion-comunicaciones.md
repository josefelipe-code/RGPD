# Prompt — Fase 04: módulo Configuración de comunicaciones

## Objetivo

Construir la parte de configuración de negocio relacionada con correos y operación.

## Dependencias

- **Fase 00**: Modelos `MailAccount`, `Template`, `Signature` y servicios IMAP/SMTP (ya existen)
- **Fase 01**: Rutas modulares, layout con menú, convenciones (ya existen)

## Contexto del proyecto

Los modelos `MailAccount`, `Template`, `Signature` ya existen con sus relaciones.
El servicio `MailSender` de Fase 00 ya permite enviar desde cuentas específicas.
Esta fase debe construir la UI Livewire para gestionar estas entidades.

## Tu misión

Implementá el módulo Configuración con estas secciones:

- Cuentas de correo
- Firmas de correo
- Plantillas de respuestas
- Alertas por estados

## Regla obligatoria de implementación

Antes de implementar, consultá **Laravel Boost** para seguir la configuración real del proyecto y tomar decisiones compatibles con su entorno activo. Usalo para definir arquitectura, diseño de formularios, organización de componentes y separación de responsabilidades, respetando paquetes o patrones presentes en el proyecto si aplican.

## Alcance funcional

1. CRUD de cuentas de correo.
2. CRUD de firmas de correo, asociables a cuentas.
3. CRUD de plantillas de respuestas.
4. Las plantillas deben incluir:
   - asunto
   - cuerpo
   - tipo o contexto de uso
5. Las plantillas deben estar preparadas para edición manual antes de enviar.
6. Configuración de alertas por estado de expediente, con días configurables.

## Reglas de UI

- Formularios crear/editar en modal.
- Listados en tabla.
- Búsqueda sobre todos los campos.
- Filtros acumulables con etiquetas.

## Restricciones de propiedad

- Tocá SOLO archivos de configuración de comunicaciones.
- NO implementes bandeja real ni envío final.
- NO implementes expediente.
- NO implementes dashboard.

## Reglas de negocio obligatorias

- Las firmas pertenecen a cuentas de correo.
- Las plantillas son base reusable, no respuestas rígidas.
- Debe contemplarse que luego se editarán nombre, teléfono y otros datos del caso antes del envío.

## Entregables esperados

- Módulo de cuentas de correo
- Módulo de firmas
- Módulo de plantillas
- Módulo de alertas por estado
- Tests del módulo

## Definición de terminado

La configuración operativa de comunicaciones queda administrable y lista para ser consumida por bandeja, expedientes e informes.
