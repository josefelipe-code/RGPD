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
     - seleccionar una plantilla disponible compatible con el destinatario (cliente o proveedor),
     - editar asunto y cuerpo antes del envío,
     - usar la cuenta del buzón sincronizado fijada al expediente, sin permitir cambiarla en el composer,
     - seleccionar firma y plantilla compatibles con la cuenta,
     - imponer desde servidor el BCC de soporte obligatorio configurado para la cuenta operativa; cualquier BCC manual es adicional y debe deduplicarse,
     - registrar el mensaje saliente como hito auditable del expediente,
     - aplicar la transición obligatoria del flujo después del despacho satisfactorio.

## Restricciones de propiedad

- Tocá SOLO archivos del módulo expedientes.
- NO implementes cliente de correo completo.
- NO implementes dashboard final.
- NO implementes administración ni contactos más allá de consumirlos.

## Reglas de negocio obligatorias

- Un expediente concluido en principio no se reabre; si hay nueva gestión, se crea uno nuevo con referencia a anteriores.
- Las respuestas entrantes no cambian estado solas: disparan revisión humana.
- El expediente debe mostrar progreso por etapas, no centrarse solo en timeline.
- Los envíos salientes son siempre supervisados: el sistema ofrece plantilla y firma compatibles con la cuenta fija; el usuario edita y confirma.
- La cuenta del buzón sincronizado queda fijada al expediente y no puede cambiarse desde el composer.
- El composer permite seleccionar únicamente plantillas disponibles compatibles con el destinatario: cliente o proveedor.
- No se puede reenviar a un proveedor sin una respuesta previa al cliente.
- El flujo obligatorio es: respuesta al cliente → `PendingClient`; después, reenvío al proveedor → `PendingProvider`. Tras el reenvío, el estado queda fijado en `PendingProvider`.
- Cada cuenta de correo operativa tiene un único BCC de soporte obligatorio. El servidor debe imponerlo y la UI no puede eliminarlo; si se admite BCC manual, este es adicional y se deduplica.
- Cualquier usuario puede gestionar el expediente según sus permisos; no existe una restricción adicional basada en asignación o responsable.
- El sistema debe conservar auditoría del usuario que realizó cada acción o fase del expediente, incluido cada mensaje saliente.
- El despacho se considera satisfactorio solo si el mensaje queda en Enviados. Si queda en Bandeja de salida o Borradores, se registra y maneja como fallo. Esto acredita el despacho al buzón, no la entrega final al destinatario.

## Cambios técnicos previstos

- Configuración de un BCC de soporte obligatorio por cuenta operativa, aplicado y deduplicado del lado servidor.
- Selección de firma y plantilla compatible con la cuenta fija del expediente y con el destinatario correspondiente.
- Auditoría de usuario y acción para cada fase y comunicación del expediente.

## Entregables esperados

- Módulo Expedientes completo
- Listado y formulario modal
- Relación con contactos/cuentas/usuarios
- Historial mínimo auditable
- Tests del módulo

## Definición de terminado

El núcleo de expedientes puede gestionarse de principio a fin sin depender del dashboard global, aunque sí pueda integrarse después con bandeja e informes.
