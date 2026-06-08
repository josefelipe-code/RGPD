# Prompt — Fase 03: módulo Contactos

## Objetivo

Construir el módulo de Contactos como agenda categorizada reutilizable para todo el sistema.

## Dependencias

- **Fase 00**: Modelos `Contact`, `Category` y sus migraciones/factories (ya existen)
- **Fase 01**: Rutas modulares, layout con menú, convenciones (ya existen)

## Contexto del proyecto

Los modelos `Contact` y `Category` ya existen con relación `belongsToMany`.
Las categorías seed (proveedores, soporte, administración, otros) ya están creadas.
Esta fase debe construir la UI Livewire (CRUDs en modal, tablas con búsqueda) sobre esos modelos existentes.

## Tu misión

Implementá el módulo Contactos y Categorías.

## Regla obligatoria de implementación

Antes de implementar, consultá **Laravel Boost** para seguir la configuración real del proyecto y tomar decisiones compatibles con su entorno activo. Usalo para definir arquitectura, diseño de CRUDs, validaciones, componentes Livewire y organización del módulo, respetando paquetes o patrones presentes en el proyecto si aplican.

## Alcance funcional

1. CRUD de contactos.
2. CRUD de categorías de contactos.
3. Las categorías deben contemplar al menos:
   - proveedores
   - soporte
   - administración
   - otros
4. Cada contacto debe poder usarse como:
   - destinatario principal
   - copia
   - copia oculta
5. Preparar el modelo para que múltiples contactos puedan asociarse a reglas de comunicación futuras.

## Reglas de UI

- Formularios crear/editar en modal.
- Listados en tabla.
- Búsqueda sobre todos los campos.
- Filtros acumulables con etiquetas.

## Restricciones de propiedad

- Tocá SOLO archivos de contactos/categorías.
- NO implementes proveedores como módulo separado.
- NO implementes envío real de correos.
- NO implementes expediente ni bandeja.

## Reglas de negocio obligatorias

- Proveedores es una categoría de contactos, no un módulo aparte.
- El modelo debe servir para reglas futuras de correo con múltiples destinatarios y CC/BCC.

## Entregables esperados

- Módulo Contactos
- Módulo Categorías
- Seeds mínimos opcionales para categorías base
- Tests del módulo

## Definición de terminado

El sistema puede gestionar contactos categorizados y reutilizables sin depender de los demás módulos.
