# Prompt — Fase 08: integración final y consolidación UX

## Objetivo

Integrar todos los módulos ya construidos por otros agentes sin rediseñarlos desde cero.

## Dependencias

- **Fases 00-07**: Todos los módulos funcionales completos

## Contexto del proyecto

El menú lateral skeleton ya existe (creado en Fase 01).
Todos los módulos funcionales ya están implementados.
Esta fase debe consolidar navegación, permisos visibles, enlaces cruzados y experiencia coherente.

## Tu misión

Tomá los módulos existentes y hacé la consolidación final de navegación, permisos visibles, enlaces cruzados y experiencia coherente de punta a punta.

## Regla obligatoria de implementación

Antes de implementar, consultá **Laravel Boost** para seguir la configuración real del proyecto y tomar decisiones compatibles con su entorno activo. Usalo para cerrar inconsistencias arquitectónicas y consolidar una integración coherente entre módulos sin romper sus límites, respetando paquetes o patrones presentes en el proyecto si aplican.

## Alcance funcional

1. Construir el menú lateral final con esta estructura:
   - Administrador
     - Usuarios
     - Roles
     - Permisos
     - Registro de actividad
   - Bandeja de entrada
   - Expedientes
   - Contactos
     - Categorías
   - Configuración
     - Cuentas de correo
     - Firmas de correo
     - Plantillas de respuestas
     - Alertas por estados
2. Verificar permisos visibles y accesos a módulos.
3. Enlazar dashboard con módulos correspondientes.
4. Enlazar bandeja con creación/consulta de expediente.
5. Enlazar expedientes relacionados, referencias de correo, alertas e informes.
6. Vincular correos entrantes y salientes con el ciclo de vida del expediente para trazabilidad completa de comunicaciones.
7. Conectar accesos directos desde la bandeja de entrada hacia el flujo de comunicaciones del expediente (responder cliente, contactar proveedor), sin duplicar lógica de envío en la bandeja.
8. Reflejar detección de respuestas de proveedor y cliente como señales para revisión del usuario, sin cambios automáticos de estado.
9. Asegurar que la lógica de sincronización con carpetas del buzón se mantenga como asistente: sugiere movimientos de carpeta, notifica eventos detectados, pero nunca modifica estados finales sin intervención humana.
10. Resolver pequeños huecos de integración sin invadir la lógica interna de cada módulo salvo necesidad real.
11. Ejecutar verificación final integral.

## Restricciones

- No reescribas módulos enteros si ya funcionan.
- Priorizá consolidación y coherencia sobre refactor masivo.

## Definición de terminado

La app se percibe como un solo producto coherente, con navegación lateral completa, módulos conectados y flujo operativo entendible de punta a punta.
