# Prompt — Fase 06: módulo Expedientes

## Objetivo

Construir el núcleo de expedientes RGPD como módulo propio.

## Dependencias

- **Fase 00**: Modelos `Case`, `CaseMilestone`, `MailMessage`, enums `CaseStatus`, `MilestoneAction` (ya existen)
- **Fase 01**: Rutas modulares, layout con menú, convenciones (ya existen)
- **Fase 03**: Contactos (se consume para proveedor)
- **Fase 04**: Cuentas de correo (se consumen)
- **Fase 05**: Bandeja de entrada (se consume para crear desde correo)

## Contexto del proyecto

Los modelos `Case` y `CaseMilestone` ya existen con relaciones a User, Contact (proveedor), MailAccount.
Los estados (pendiente_cliente, pendiente_proveedor, concluido) ya están definidos como enum.
Esta fase debe construir la UI Livewire y la lógica de gestión del ciclo de vida del expediente.

## Tu misión

Implementá el módulo Expedientes respetando el flujo definido en `proyecto.md`.

## Regla obligatoria de implementación

Antes de implementar, consultá **Laravel Boost** para seguir la configuración real del proyecto y tomar decisiones compatibles con su entorno activo. Usalo para definir el diseño del módulo, separación entre lógica de dominio, componentes Livewire, acciones y persistencia, respetando paquetes o patrones presentes en el proyecto si aplican.

## Alcance funcional

1. Modelo de expediente con datos mínimos:
   - correo de solicitud
   - teléfono
   - proveedor
   - cuenta/empresa
   - fecha de inicio
   - usuario que lo tramita
   - estado de solicitud
2. Estados visibles:
   - pendiente de cliente
   - pendiente de proveedor
   - concluido
3. Crear expediente desde contexto de correo.
4. Edición permitida del expediente.
5. Registrar hitos auditables:
   - abrió expediente
   - respondió cliente
   - respondió proveedor
   - cerró expediente
6. Asociación dual del expediente por:
   - correo del remitente
   - teléfono
7. Mostrar expedientes relacionados por mismo correo o mismo teléfono.
8. Filtrado de expedientes por cuenta de correo.
9. Tabla del módulo con búsqueda por todos los campos y filtros acumulables.
10. Cambio manual de estado por usuario.
11. Preparar sincronización de movimiento de correo a carpeta según estado, si la infraestructura ya existe; si no, dejar contrato claro de integración.
12. Subflujo de comunicaciones salientes dentro del expediente:
    - responder al cliente,
    - contactar al proveedor,
    - seleccionar plantilla base según contexto,
    - editar asunto y cuerpo antes del envío,
    - seleccionar cuenta emisora y firma,
    - aplicar regla de copia oculta (BCC) a cuenta de soporte en comunicaciones al proveedor,
    - registrar el mensaje saliente como hito auditable del expediente,
    - sugerir transición de estado después del envío, sin forzarla automáticamente.

## Restricciones de propiedad

- Tocá SOLO archivos del módulo expedientes.
- NO implementes cliente de correo completo.
- NO implementes dashboard final.
- NO implementes administración ni contactos más allá de consumirlos.

## Reglas de negocio obligatorias

- Un expediente concluido en principio no se reabre; si hay nueva gestión, se crea uno nuevo con referencia a anteriores.
- Las respuestas entrantes no cambian estado solas: disparan revisión humana.
- El expediente debe mostrar progreso por etapas, no centrarse solo en timeline.
- Los envíos salientes son siempre supervisados: el sistema sugiere plantilla, cuenta y firma; el usuario edita y confirma.
- Las transiciones de estado tras un envío son sugerencias, no cambios automáticos.
- Todo envío a proveedor debe incluir copia oculta (BCC) a la cuenta de soporte configurada.
- Cada mensaje saliente debe registrarse como hito auditable del expediente.

## Entregables esperados

- Módulo Expedientes completo
- Listado y formulario modal
- Relación con contactos/cuentas/usuarios
- Historial mínimo auditable
- Tests del módulo

## Definición de terminado

El núcleo de expedientes puede gestionarse de principio a fin sin depender del dashboard global, aunque sí pueda integrarse después con bandeja e informes.
