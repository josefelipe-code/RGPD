# Prompt — Fase 07: dashboard, alertas e informes

## Objetivo

Construir el dashboard inicial, las alertas y la generación de informes de expediente.

## Dependencias

- **Fase 00**: Modelos `Case`, `CaseMilestone`, `MailMessage` (se consumen)
- **Fase 01**: Dashboard placeholder existente (se reemplaza con datos reales)
- **Fase 04**: Configuración de alertas por estado (se consume para umbrales)
- **Fase 05**: Bandeja de entrada (se consume para correos pendientes)
- **Fase 06**: Expedientes (se consume para indicadores e informes)

## Contexto del proyecto

El dashboard placeholder ya existe en `resources/views/dashboard.blade.php`.
Los modelos de dominio ya existen para consultar datos.
Esta fase debe reemplazar el placeholder con indicadores reales, alertas y generación de informes.

## Tu misión

Implementá SOLO estas capacidades transversales:

- dashboard inicial
- alertas de expedientes en espera
- informe trazable por expediente

## Regla obligatoria de implementación

Antes de implementar, consultá **Laravel Boost** para seguir la configuración real del proyecto y tomar decisiones compatibles con su entorno activo. Usalo para definir arquitectura, componentes Livewire, composición del dashboard, acciones de informe y separación de responsabilidades, respetando paquetes o patrones presentes en el proyecto si aplican.

## Alcance funcional

1. Dashboard con indicadores:
   - sin iniciar
   - pendiente de cliente
   - pendiente de proveedor
   - concluidos en el mes
2. Área de notificaciones para expedientes con X días en estados no concluidos.
3. El umbral de días debe consumir la configuración de alertas por estado.
4. Sección destacada para respuestas de proveedores detectadas, si el dato existe.
5. El dashboard debe orientar y derivar a la acción, no reemplazar los módulos operativos.
6. Generar informe por expediente con:
   - resumen cronológico de acciones
   - quién hizo cada intervención relevante si el dato existe
   - referencia a correos asociados
   - referencia a ubicación según estado actual del expediente
   - hitos clave: recibido, respondido, enviado a proveedor, respuesta de proveedor
7. Permitir enviar el informe desde una cuenta configurada con su firma correspondiente.

## Restricciones de propiedad

- Tocá SOLO archivos del dashboard/alertas/informes.
- NO implementes CRUDs completos de otros módulos.
- NO resuelvas bandeja o expediente desde cero; consumí sus datos.

## Reglas de negocio obligatorias

- El dashboard no es el lugar para la búsqueda operativa profunda.
- El informe debe poder generarse aunque el expediente siga en curso.
- El informe tiene valor administrativo/judicial, no solo visual.

## Entregables esperados

- Dashboard inicial funcional
- Alertas configurables visibles
- Generación y envío de informe por expediente
- Tests del módulo

## Definición de terminado

El sistema ofrece orientación inicial, alertas y un informe usable del expediente sin invadir la propiedad funcional de otros módulos.
