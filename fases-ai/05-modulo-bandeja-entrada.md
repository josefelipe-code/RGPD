# Prompt — Fase 05: módulo Bandeja de entrada

## Objetivo

Construir la bandeja de entrada como superficie operativa similar a un cliente de correo.

## Dependencias

- **Fase 00**: Modelo `MailMessage`, servicio `ImapService` (ya existen)
- **Fase 01**: Rutas modulares, layout con menú, convenciones (ya existen)
- **Fase 04**: Cuentas de correo configuradas (se consumen, no se crean aquí)

## Contexto del proyecto

El modelo `MailMessage` ya existe con campos para dirección (incoming/outgoing), estado, y asociación opcional a Case.
El servicio `ImapService` de Fase 00 ya puede conectarse y fetch mensajes.
Esta fase debe construir la UI tipo cliente de correo y la lógica de clasificación/sugerencias.

## Tu misión

Implementá el módulo Bandeja de entrada sin desarrollar el núcleo completo de expedientes.

## Regla obligatoria de implementación

Antes de implementar, consultá **Laravel Boost** para seguir la configuración real del proyecto y tomar decisiones compatibles con su entorno activo. Usalo para definir la arquitectura del módulo, componentes Livewire, acciones, servicios auxiliares y organización del código, respetando paquetes o patrones presentes en el proyecto si aplican.

## Alcance funcional

1. Vista tipo cliente de correo:
   - listado de correos
   - vista del cuerpo al seleccionar
2. Filtro por cuenta de correo.
3. Estados visuales en la bandeja para señalar:
   - sin expediente iniciado
   - asociado a expediente
   - respondido por cliente
   - respondido por proveedor
   - pendiente de revisión
4. Acciones visibles por correo para:
   - iniciar expediente
   - descartar/eliminar correo no RGPD
   - sugerir asociación a expediente existente si corresponde
5. Si se detecta spam/falso positivo/no RGPD, permitir eliminación con notificación al administrador.
6. Si se detecta actividad manual en buzón, sugerir iniciar expediente y notificar al administrador.

## Restricciones de propiedad

- Tocá SOLO archivos del módulo bandeja.
- NO implementes el CRUD completo de expedientes.
- Si necesitás abrir un modal de creación de expediente, dejá un contrato claro o un componente puente aislado bajo namespace de bandeja.
- NO modifiques dashboard final ni menú global.

## Reglas de negocio obligatorias

- El sistema solo sugiere; no ejecuta automáticamente acciones sensibles.
- Las detecciones deben quedar preparadas para revisión humana.
- La bandeja debe comportarse como cliente de correo, no como tabla administrativa seca.

## Entregables esperados

- Pantalla de bandeja por cuenta
- Vista de mensaje seleccionado
- Alertas visuales en correos
- Acciones operativas básicas y sugerencias
- Tests del módulo

## Definición de terminado

La bandeja sirve para clasificar, revisar y disparar acciones operativas sin depender del dashboard global ni del módulo completo de expedientes.
