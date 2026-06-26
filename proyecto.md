# Proyecto RGPD - Descubrimiento

## Resumen de la idea actual

Se busca una aplicación web multiusuario para gestionar solicitudes de ejercicio de supresión de datos RGPD que hoy se manejan manualmente desde varias cuentas de correo.

## Problema actual

- Las solicitudes llegan a varias cuentas de correo.
- Varias personas gestionan esas casillas y no tienen visibilidad compartida del trabajo.
- El seguimiento se hace desde el propio correo, sin un expediente central.
- Se olvidan respuestas dadas o pendientes a clientes.
- No hay recordatorios ni control de tiempos para gestiones hacia proveedores.

## Objetivo inicial

Centralizar la gestión de expedientes RGPD en una aplicación web simple, con trabajo colaborativo entre usuarios y seguimiento del ciclo completo de cada solicitud.

## Funcionalidades mencionadas hasta ahora

1. Configurar acceso a varias cuentas de correo para leer la bandeja de entrada.
2. Gestionar contactos de proveedores para redirigir solicitudes según corresponda.
3. Gestionar firmas de correo según la cuenta emisora.
4. Gestionar plantillas de respuesta a clientes.
5. Gestionar plantillas de respuesta a proveedores.
6. Gestionar expedientes RGPD como núcleo del flujo operativo.

## Hipótesis de valor

- Reducir olvidos y falta de seguimiento.
- Dar visibilidad compartida entre personas que operan las mismas cuentas.
- Estandarizar respuestas y reenvíos.
- Tener control de estado, tiempos y responsables por expediente.

## Pendiente por aclarar

- Flujo exacto del expediente desde que entra un correo hasta su cierre.
- Estados del expediente.
- Reglas para asignación entre usuarios.
- Criterios de vencimiento, recordatorios y escalaciones.
- Qué acciones salen por correo y cuáles quedan internas.
- Relación exacta entre cliente, proveedor, cuenta de correo y expediente.

## Flujo operativo entendido hasta ahora

### 1. Entrada y clasificación inicial

Cuando entra un correo, primero hay que clasificarlo para determinar si:

- es spam,
- es un correo por otro tema no relacionado con RGPD,
- o es una solicitud real de supresión de datos RGPD.

### 2. Validación mínima de identificación

Si es una solicitud real, se debe comprobar si el cliente informó su número de teléfono para poder validar si existe en la base de datos.

#### 2.1 Falta teléfono

Si el cliente no informa teléfono:

- se responde con la plantilla de “falta teléfono”,
- el expediente pasa a estado **Pendiente de cliente**,
- hoy esto se gestiona moviendo el correo a una carpeta del buzón con ese nombre.

#### 2.2 El cliente informa teléfono

Si el cliente informa teléfono, se valida si existe en la base de datos.

##### 2.2.1 El teléfono no existe en base de datos

- se responde con la plantilla de “no tenemos registro de llamada”,
- el flujo aparentemente podría cerrarse ahí, aunque esto todavía conviene confirmarlo.

##### 2.2.2 El teléfono sí existe en base de datos

Entonces:

- se identifica qué proveedor facilitó el contacto,
- se marca el registro para baja en la base de datos,
- se determina el tipo de solicitud,
- se responde al cliente con la plantilla correspondiente,
- se reenvía o deriva la solicitud al proveedor,
- el expediente pasa a estado **Pendiente de proveedor**.

### 3. Tipos de solicitud detectados

Por ahora existen al menos estos tipos:

- Solicitud solo de baja de los datos.
- Solicitud de baja + origen y consentimiento de los datos.
- Solicitud solo de origen y consentimiento de los datos.

### 4. Seguimiento en estado Pendiente de proveedor

Cuando el expediente queda pendiente de proveedor, se necesita:

- medir cuántos días lleva en espera,
- generar recordatorios o alertas para evitar olvidos.

### 5. Respuesta del proveedor y cierre

Cuando responde el proveedor hay dos caminos principales:

#### 5.1 Solicitud solo de baja

- cuando el proveedor confirma, el expediente puede pasar directamente a **Concluido**.

#### 5.2 Solicitudes con origen y/o consentimiento

- el cliente debe recibir respuesta con el origen de los datos y confirmación del proveedor,
- en algunos casos el proveedor responde al cliente directamente y deja a la empresa en copia para constancia,
- en otros casos la empresa responde al cliente usando su plantilla y adjuntando la información recibida,
- luego de eso el expediente pasa a **Concluido**.

## Responsabilidades de los módulos en la comunicación saliente

### Qué provee cada módulo

- **Configuración**: provee exclusivamente plantillas, firmas, cuentas de correo y configuración de alertas por estado. No participa del envío ni de la composición de mensajes salientes.
- **Bandeja de entrada**: maneja la detección, clasificación inicial y sugerencia de inicio de expediente. No compone ni envía respuestas a clientes ni comunicaciones a proveedores.
- **Expedientes**: es el módulo dueño del flujo completo de composición, edición y envío de respuestas a clientes y comunicaciones a proveedores, porque esas acciones requieren el contexto completo del caso (estado, historial, proveedor asociado, plantilla aplicable, cuenta emisora y firma).

### Principio de supervisión humana

Todo envío saliente está supervisado por una persona:

- el sistema sugiere la acción, la plantilla y los datos del caso,
- el usuario edita asunto y cuerpo antes de enviar,
- el usuario elige cuenta emisora y firma,
- el envío solo se ejecuta cuando el usuario confirma.

### Regla de copia oculta a soporte

Toda comunicación saliente hacia un proveedor debe incluir en copia oculta (BCC) una cuenta de soporte, para dejar constancia y trazabilidad institucional de la gestión.

### Registro de mensajes salientes

Cada correo enviado desde el sistema debe quedar registrado y asociado al expediente correspondiente, como parte del historial auditable del caso.

## Estados identificados hasta ahora

- Pendiente de cliente
- Pendiente de proveedor
- Concluido

Falta confirmar si también se necesitan estados explícitos como:

- Nuevo
- En revisión
- Falso positivo / No RGPD
- Cerrado sin acción

## Dudas abiertas importantes

- Qué pasa exactamente cuando el teléfono no existe: ¿se concluye el expediente inmediatamente?
- Cómo se distingue operativamente entre spam, otro tema y solicitud RGPD válida.
- Si la marca de baja en base de datos la hace cualquier usuario o solo ciertos perfiles.
- Si puede haber más de un proveedor involucrado en un mismo expediente.
- Cuáles son los plazos esperados de respuesta para cliente y proveedor.

## Datos mínimos del expediente (primera versión)

El expediente debería mostrar como mínimo:

- correo de solicitud,
- teléfono sobre el que se solicita el ejercicio,
- proveedor,
- empresa o cuenta de correo asociada,
- fecha de inicio,
- usuario que lo tramita,
- estado de la solicitud.

## Observación analítica

Con estos campos ya aparece un modelo base bastante claro:

- **canal/origen**: correo de solicitud,
- **sujeto afectado**: teléfono,
- **tercero relacionado**: proveedor,
- **contexto operativo**: empresa/cuenta,
- **trazabilidad temporal**: fecha de inicio,
- **responsabilidad interna**: usuario tramitador.

Todavía faltaría confirmar si también son obligatorios desde el inicio:

- tipo de solicitud,
- fecha de última acción,
- fecha límite o antigüedad,
- historial de interacciones.

## Reglas operativas de inicio y asignación

- Un expediente se inicia siempre por un usuario que entra al sistema, ve el correo, valida la información y decide abrir el expediente.
- No existe reparto previo ni asignación automática por cuenta de correo.
- Cualquier usuario puede tomar un correo y comenzar su tramitación.
- El usuario que inicia o tramita el expediente debe quedar registrado para dar trazabilidad operativa.
- El usuario que inicia el expediente no necesariamente es quien lo termina; otro usuario podría continuarlo o cerrarlo.
- Debe quedar trazabilidad por etapa o hito: si otro usuario continúa el expediente, el sistema debe registrar qué etapa realizó cada usuario.

## Problema operativo actual asociado

- Hoy no hay visibilidad clara de quién comenzó a gestionar cada correo en la bandeja.
- Eso genera falta de coordinación y riesgo de duplicidad, abandono o pérdida de seguimiento.

## Estados formales confirmados por ahora

En principio se quieren mantener los mismos estados que hoy se reflejan como carpetas en el buzón:

- Pendiente de cliente
- Pendiente de proveedor
- Concluido

## Integración deseada con carpetas del buzón

- A medida que el expediente cambie de estado, la aplicación idealmente debería mover el correo del buzón a la carpeta correspondiente.
- Esto sugiere que el sistema no solo gestiona expedientes, sino que también sincroniza el estado operativo con la organización del correo.

## Asociación automática de respuestas de proveedores

Las respuestas de proveedores deberían poder asociarse al expediente correspondiente.

Las claves de asociación identificadas por ahora son:

- correo del proveedor,
- teléfono del expediente.

## Regla de trazabilidad en envíos a proveedores

- Toda respuesta o reenvío al proveedor debe incluir en copia oculta una cuenta de soporte para dejar constancia y trazabilidad.

## Variante de entrada alternativa

- No todos los expedientes nacen desde un correo recibido en los buzones principales.
- A veces el caso entra por un correo de un proveedor dirigido al equipo de soporte.
- En ese escenario no se reenvía a otro proveedor, sino que se le responde directamente con el número de teléfono cuya supresión se solicita.

## Implicación funcional importante

El sistema no tiene un único flujo lineal.

Por ahora ya se identifican al menos dos variantes de entrada:

1. **Entrada por correo de cliente**: se valida, se identifica proveedor, se responde al cliente y se deriva al proveedor.
2. **Entrada por correo de proveedor a soporte**: se gestiona directamente con ese proveedor, sin reenvío a un tercero.

Esto implica que más adelante habrá que modelar:

- tipos de origen del expediente,
- reglas de asociación de correos entrantes,
- y variantes del flujo según origen y destinatario real de la gestión.

## Hitos auditables por usuario

Por ahora, el sistema debería registrar al menos qué usuario realizó estos hitos:

- abrió el expediente,
- respondió al cliente,
- respondió al proveedor,
- cerró el expediente.

## Implicación de trazabilidad

No alcanza con guardar un único “usuario responsable”.

También hace falta un historial mínimo de acciones para saber:

- quién inició el caso,
- quién comunicó con el cliente,
- quién comunicó con el proveedor,
- y quién dio el expediente por concluido.

## Pantalla principal deseada

La entrada principal de la aplicación debería ser un dashboard.

### Indicadores principales

Mostrar cantidades de expedientes por estado:

- Sin iniciar
- Pendiente de cliente
- Pendiente de proveedor
- Concluidos en el mes

### Bandeja operativa

Debajo del resumen debería existir un listado de correos pendientes para iniciar expedientes.

### Notificaciones y recordatorios

- Debe existir un icono o área de notificaciones.
- Allí se deben mostrar expedientes que llevan X días en estados no concluidos.
- Ese umbral de días debe ser configurable.

### Área de respuestas de proveedores detectadas

- El sistema podría detectar cuándo un correo entrante corresponde a una respuesta de proveedor.
- Esos casos deberían mostrarse en un área lateral derecha o sección destacada.
- Esa sección serviría para que el usuario revise el caso, responda si hace falta y cambie el estado del expediente.

### Regla de control sobre estados

- El cambio de estado del expediente lo realiza siempre un usuario.
- Aunque el sistema detecte eventos o sugiera acciones, no debería concluir o mover estados finales sin intervención del usuario.

## Nueva necesidad detectada: conciliación con trabajo manual en el buzón

Si alguien del equipo tramita un caso manualmente desde el buzón, el sistema idealmente debería:

- detectar esa situación,
- sugerir iniciar el expediente si aún no existe,
- asignar o registrar un usuario responsable de su inicio,
- asociar al expediente las respuestas enviadas al cliente y al proveedor.

## Implicación funcional importante

Esto introduce una capacidad adicional: el sistema no solo debe crear expedientes desde cero, sino también **reconstruir o vincular actividad que ocurrió parcialmente fuera de la aplicación**.

Eso probablemente requerirá más adelante definir reglas para:

- detección de hilos ya gestionados manualmente,
- sugerencias de creación de expediente,
- asociación automática o semiautomática de correos al expediente,
- validación humana antes de confirmar el vínculo.

## Regla de automatización confirmada

- Cuando el sistema detecte situaciones relevantes en el correo, no debe ejecutar acciones por sí solo.
- Debe solamente sugerir opciones al usuario.
- Además, debería notificar por correo al administrador del sistema.

## Principio operativo derivado

La aplicación actúa como **asistente operativo con supervisión humana**, no como automatismo autónomo.

Esto significa que:

- el usuario decide,
- el sistema sugiere,
- y el administrador puede ser alertado ante situaciones detectadas.

## Roles y permisos entendidos hasta ahora

### Administrador del sistema

- Tiene acceso total al sistema.

### Usuarios operativos

- También tienen acceso a todo lo necesario para operar.
- La restricción principal identificada por ahora es la eliminación de expedientes.

### Permiso sensible

- Eliminar un expediente no debería estar permitido para cualquier usuario.
- Solo podrán hacerlo usuarios que tengan ese permiso específico.

## Implicación funcional

Por ahora el modelo de permisos parece simple:

- acceso operativo amplio para casi todos,
- con control especial solo sobre acciones sensibles como eliminación de expediente.

Más adelante habrá que confirmar si además del borrado existen otras acciones restringidas, por ejemplo configuraciones globales, gestión de cuentas de correo o plantillas.

## Módulo de administración

El sistema contará con un módulo de administración.

### Gestión de acceso

Debe incluir:

- gestión de usuarios,
- gestión de roles,
- gestión de permisos.

### Flexibilidad de delegación

- El administrador debe poder delegar permisos en otros usuarios según considere necesario.

### Trazas y auditoría del sistema

Otro componente del módulo de administración será el de trazas de usuario.

Estas trazas deberían cubrir al menos:

- acciones de usuarios sobre expedientes,
- configuraciones realizadas en el sistema,
- otras acciones operativas relevantes ejecutadas dentro de la aplicación.

## Implicación funcional importante

Esto confirma que la auditoría no solo aplica al expediente RGPD, sino también a la administración del sistema.

Por lo tanto, más adelante habrá que distinguir al menos dos niveles de trazabilidad:

- trazabilidad operativa del expediente,
- trazabilidad administrativa y de configuración.

## Configuraciones de negocio obligatorias identificadas

Además de usuarios, roles y permisos, el sistema debería permitir configurar al menos:

- cuentas de correo,
- contactos,
- categorías de contactos,
- plantillas de respuesta a clientes,
- plantillas de respuesta a proveedores,
- firmas,
- días de recordatorio por estado de expediente.

## Gestión de contactos

El sistema debería contar con una gestión de contactos reutilizable.

Los contactos pueden pertenecer a categorías como por ejemplo:

- proveedores,
- soporte,
- administración,
- otros.

## Uso operativo de contactos

- En algunos casos será necesario enviar correos a más de una persona.
- Eso aplica tanto para copia visible como para copia oculta.
- Por lo tanto, los contactos no son solo una agenda: también forman parte de reglas operativas de comunicación.

## Firmas

- Las firmas pueden estar asociadas a cuentas de correo.

## Recordatorios por estado

- Los días de recordatorio deben poder configurarse según el estado del expediente.

## Implicación funcional importante

Estas configuraciones no son accesorios administrativos; afectan directamente el flujo operativo del expediente y la generación de correos.

## Comportamiento esperado de las plantillas

- Se usarán plantillas base para las respuestas.
- Las plantillas de correo deben incluir también el asunto correspondiente.
- Antes de enviar, el usuario debe poder editarlas.
- Habrá campos frecuentes a personalizar, como por ejemplo:
  - teléfono,
  - nombre del cliente,
  - datos adicionales que surjan en el caso concreto.

## Principio funcional derivado

Las plantillas no deben tratarse como textos rígidos o cerrados.

Deben funcionar como una base operativa reutilizable, pero con capacidad de ajuste manual antes del envío, porque el proceso real admite variaciones y excepciones.

## Visualización del avance del expediente

- No se prioriza una línea de tiempo detallada como vista principal.
- Lo importante es ver por qué etapas transita el expediente.

## Implicación funcional

La visualización del expediente debería centrarse más en:

- etapa actual,
- etapas ya completadas,
- posibles transiciones o próximos pasos,

que en una cronología exhaustiva de eventos.

## Tratamiento de falsos positivos, spam o correos no RGPD

- Debe existir la posibilidad de eliminarlos directamente.
- Esa acción debería notificar al administrador del sistema.

## Implicación funcional

La clasificación inicial no solo separa casos válidos de inválidos, sino que también habilita una acción sensible de descarte con aviso administrativo.

## Comportamiento general esperado para los CRUD

Todos los CRUD del sistema, incluido el de expedientes, deberían permitir búsqueda por todos sus campos.

### Patrón general de interfaz

1. El formulario de crear y editar debería abrirse en modal.
2. Los listados deberían mostrarse en tabla.
3. En la tabla deben existir acciones como editar o eliminar, según corresponda.

### Búsqueda y filtros encadenados

- Sobre cada tabla debería existir un campo de búsqueda.
- Esa búsqueda debe filtrar por todo el contenido de la tabla.
- Cada búsqueda aplicada debe reflejarse visualmente como una etiqueta o filtro activo.
- Debe ser posible aplicar una nueva búsqueda sobre los resultados ya filtrados.
- Eso generaría múltiples etiquetas acumuladas, representando filtros encadenados sobre el conjunto visible.

## Comportamiento particular del expediente

- En el caso de expedientes, la creación no nace principalmente desde un CRUD clásico dentro de “gestión de expedientes”.
- Cuando se listen los correos, allí deben mostrarse las acciones disponibles.
- Desde ese listado de correos se debería abrir el modal para crear el expediente.

## Regla confirmada de sincronización con carpetas del buzón

- Cuando un expediente cambie de estado, el correo asociado debería moverse a la carpeta correspondiente de ese estado en el buzón.

## Implicación funcional importante

El expediente tiene un comportamiento híbrido:

- por un lado forma parte de un CRUD consultable y administrable,
- pero por otro nace operativamente desde la bandeja de correos y sufre sincronización con el buzón según el estado.

## Edición del expediente

- El expediente debe permitir edición después de creado.
- Por ahora no se definieron campos bloqueados tras su inicio.

## Comportamiento cuando responde el cliente

### Situación actual entendida

Si un expediente está en **Pendiente de cliente** y el cliente responde:

- se revisa la nueva información,
- se responde según corresponda,
- el expediente puede pasar a **Pendiente de proveedor**,
- o puede pasar a **Concluido** si finalmente no se encuentra el número.

También puede ocurrir que:

- el cliente corrija un dato previo, por ejemplo el número de teléfono,
- en ese caso el expediente debe poder editarse,
- y luego moverse al estado que corresponda según la nueva validación.

## Criterio funcional recomendado

El expediente no debería tratar las respuestas del cliente como un caso cerrado o rígido, sino como una reanudación del caso.

Eso implica que:

- una respuesta del cliente reabre operativamente el análisis del expediente,
- el usuario valida la nueva información,
- actualiza los datos del expediente si hace falta,
- y decide manualmente el próximo estado.

## Señales visuales sobre respuestas recibidas

- Cuando un expediente en **Pendiente de cliente** reciba respuesta, debería existir una señal visual de que hubo respuesta y que está pendiente de revisión.
- Esa alerta idealmente también debería reflejarse sobre el correo en la bandeja de entrada.

## Recomendación funcional derivada

La bandeja de entrada no debería mostrar solo correos “nuevos”, sino también correos relevantes que cambiaron su situación operativa.

Por ejemplo, un correo asociado a expediente podría mostrar una marca visual equivalente a:

- respondido por cliente,
- respondido por proveedor,
- pendiente de revisión.

## Reglas de asociación entre correo, teléfono y expediente

- El expediente debe asociarse tanto al **correo del remitente** como al **teléfono** involucrado.
- No conviene depender únicamente del hilo de correo o del asunto, porque si el asunto cambia la asociación automática puede fallar.

## Motivos de negocio para esta asociación dual

- Un mismo cliente puede volver a escribir en el futuro por un número distinto.
- En ese caso conviene saber que ese correo ya tuvo expedientes previos.
- También puede ocurrir que el cliente vuelva a escribir por la misma solicitud.
- En ese escenario debería poder detectarse que ya existe o existió un expediente relacionado para responder en consecuencia.

## Problema actual que esto resuelve

- Hoy falta visibilidad histórica sobre si un remitente ya tuvo expedientes anteriores.
- También cuesta detectar rápidamente si una nueva entrada es realmente un caso nuevo o una continuación/repetición de uno ya tramitado.

## Recomendación funcional

El sistema debería usar varios criterios de contexto para sugerir asociaciones o antecedentes, al menos:

- correo del remitente,
- teléfono,
- historial previo de expedientes del mismo remitente.

La decisión final de vincular, crear uno nuevo o reutilizar contexto debería seguir quedando del lado del usuario.

## Navegación por antecedentes relacionados

- Desde un expediente actual debería poder verse si existen expedientes anteriores relacionados.
- Al menos debería ser posible consultar:
  - otros expedientes del mismo correo,
  - otros expedientes del mismo teléfono.

## Valor operativo

Esto ayuda a:

- detectar reincidencias o continuidad de casos,
- distinguir mejor entre solicitud nueva y solicitud ya tramitada,
- responder con más contexto al cliente,
- y reducir duplicidades en la gestión.

## Manejo de evidencias y adjuntos

- No se busca que el sistema guarde adjuntos o evidencias como archivos propios dentro del expediente.
- Lo que sí debe existir es la referencia al correo relacionado y una forma clara de saber dónde verlo.

## Implicación funcional

El expediente debe funcionar más como una capa de gestión y trazabilidad sobre el correo, que como un repositorio documental independiente.

## Comentarios internos

- De momento no se considera necesario incluir comentarios internos o notas manuales dentro del expediente.

## Informes y exportación

- Se considera valioso poder generar un informe de un expediente cuando sea necesario.
- Un caso importante es contar con un informe utilizable ante una instancia judicial.
- Ese informe debería incluir toda la traza del expediente.

### Contenido esperado del informe

- Debe incluir un resumen por línea de tiempo de acciones.
- Debe permitir localizar los correos asociados en la carpeta correspondiente al **estado actual del expediente**.
- Debe reflejar al menos que:
  - se recibió la solicitud,
  - se respondió,
  - se envió al proveedor,
  - y se recibió la respuesta del proveedor.

### Envío del informe

- El informe debe poder enviarse desde una de las cuentas de correo configuradas.
- Ese envío debe salir con la firma correspondiente de esa cuenta.

## Implicación funcional

Aunque no se planteó aún un módulo amplio de reportes, sí aparece una necesidad clara de **generación de informe por expediente** con trazabilidad completa y valor probatorio/administrativo.

### Aclaración importante sobre el informe

- Lo más común no es pedir el informe de un expediente ya concluido.
- Lo normal es necesitar el informe mientras el expediente sigue en curso, por ejemplo si el cliente reclama por vías legales.
- Por eso el informe debe poder generarse independientemente del estado del expediente.

## Reapertura de expedientes concluidos

- En principio, un expediente concluido debería permanecer concluido.
- Si surge una nueva gestión, se prefiere abrir un expediente nuevo.
- Ese nuevo expediente debería mostrar visible la relación con expedientes anteriores asociados.

## Criterio funcional derivado

Se prioriza preservar la integridad histórica de cada expediente cerrado, evitando reabrirlo, pero manteniendo continuidad operativa mediante asociaciones entre expedientes relacionados.

## Estructura general de navegación en la app

- Cada gestión o módulo principal debe tener su propio apartado dentro de la aplicación.
- El dashboard inicial no reemplaza esos módulos operativos.

## Rol del dashboard

- El dashboard se entiende más como una vista de orientación inicial.
- Su función principal es mostrar situación general, prioridades y ayudar a decidir qué hacer.
- Desde allí el usuario debería dirigirse al apartado correspondiente para actuar.

## Rol del apartado de expedientes

- La búsqueda operativa de expedientes debe hacerse dentro del apartado específico de expedientes, no en el dashboard.
- Allí es donde deben existir las capacidades completas de consulta, búsqueda y gestión del expediente.

## Implicación funcional

La aplicación debe separar claramente:

- **panel de control inicial** para visión general y alertas,
- **módulos de gestión** para trabajo detallado y acciones operativas.

## Navegación principal esperada

- Se imagina un menú lateral.
- Ese menú lateral debería concentrar los accesos a las diferentes funcionalidades del sistema.

## Estructura actual imaginada del menú lateral

### 1. Administrador

- Usuarios
- Roles
- Permisos
- Registro de actividad

### 2. Bandeja de entrada

### 3. Expedientes

### 4. Contactos

- Categorías

### 5. Configuración

- Cuentas de correo
- Firmas de correo
- Plantillas de respuestas
- Alertas por estados

## Observación analítica

La estructura tiene bastante sentido y se ve coherente con todo lo definido hasta ahora.

Se confirma que **proveedores** no es un módulo aparte, sino una **categoría dentro de Contactos**.

## Bandeja de entrada por cuenta

- La bandeja de entrada debe poder filtrarse por cuenta de correo.
- No se prioriza una mezcla indiferenciada de correos de todas las cuentas.

## Expedientes por cuenta

- Los expedientes también deben poder filtrarse por cuenta de correo.
- La segmentación por cuenta no aplica solo a la bandeja, sino también a la gestión del expediente.

## Visualización esperada de la bandeja de entrada

- La bandeja no se concibe como una tabla rígida, sino más como un cliente de correo.
- Debe listar los correos y permitir ver el cuerpo del mensaje al seleccionarlo.
- Además debe reflejar alertas visuales ya definidas, por ejemplo respuestas recibidas y otros indicadores operativos relevantes.

## Visualización esperada del listado de expedientes

El listado de expedientes debe mostrar como base los datos mínimos ya definidos para el expediente, especialmente:

- correo de solicitud,
- teléfono,
- proveedor,
- empresa o cuenta de correo asociada,
- fecha de inicio,
- usuario que lo tramita,
- estado de la solicitud.

## Estado técnico verificado 2026-06-08

Este documento describe el producto y el flujo funcional. El estado técnico real verificado en el código es:

- El proyecto ya no es greenfield: existen módulos de administración, contactos, configuración, bandeja y expedientes.
- Git está activo en `main` y sincronizado con `origin/main`.
- Laravel arranca en local con PHP 8.4, Laravel 13.8, Livewire 4, Flux 2, Fortify, Spatie Permission y SQLite.
- La base local tiene datos mínimos de prueba: usuario, rol, permisos, una cuenta de correo, mensajes, categorías, contacto, plantilla y firma.
- Hay una migración pendiente: `2026_05_17_220000_add_user_id_to_templates`.
- La suite de tests no está completamente verde: fallan 2 tests de configuración por validación `smtp_connection`.
- `public/storage` todavía no está linkeado.
- El dashboard sigue siendo placeholder; las métricas, alertas e informes reales siguen pendientes.
