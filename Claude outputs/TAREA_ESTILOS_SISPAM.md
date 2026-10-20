# Tarea aparte: aplicar el estilo de SISPAM 2 en conta_cem

## Contexto

- Esta tarea es solo de interfaz y va aparte de la fase 1. El paso 3 ya está en su commit y no hay nada pendiente: haz esta tarea en commits «Estilos: …» y después sigue con el paso 4 (Plan de cuentas), ya con el estilo nuevo.
- Descarta el texto sobre SISPAM que te pegaron antes: llegó cortado y era para corregir SISPAM, no para esta sesión.
- Objetivo: que conta_cem se vea como SISPAM 2 (`C:\xampp\htdocs\SISPAM_2.0`), que está hecho en Filament 3 con la identidad del CEM. Debe tener la misma paleta, la misma letra, la misma marca y la misma estructura de pantalla: barra lateral con el menú, barra superior y contenido en secciones.

## Referencia (solo lectura: no modifiques nada en SISPAM_2.0)

- `app/Providers/Filament/AdminPanelProvider.php`: paletas `primary` (azul institucional) y `gray` (escala 50 a 950), success, warning, danger e info, letra Arial y tema claro por defecto.
- `public/css/sispam-tema.css`: sombras, radios, encabezados de tabla, botones, insignias, campos y la marca.
- `resources/views/filament/marca.blade.php`: estructura de la marca.
- `resources/views/errors/plantilla.blade.php`: páginas de error.
- Para medidas y estructura de Filament 3:
  - Vistas en `vendor/filament/filament/resources/views/components` (layout, sidebar, topbar, page).
  - Vistas en `vendor/filament/support/resources/views/components` (section, button, badge, input, modal, dropdown).
  - Vistas en `vendor/filament/tables/resources/views`.
  - El CSS compilado `public/css/filament/filament/app.css`, para pasar sus clases a valores concretos.

## Cómo hacerlo

- No instales Filament. conta_cem usa Livewire 4 y Filament 3 exige Livewire 3, así que habría que rehacer los pasos 1 a 3.
- Replica el diseño con clases propias en `public/css/conta.css` y en los componentes Blade que ya existen. No copies el CSS compilado de Filament ni uses sus clases `fi-*` o utilidades de Tailwind: en SISPAM eso dejó estilos que no se aplican.
- Sigue sin Tailwind y sin Node, como hasta ahora.
- Íconos: Heroicons, los mismos de Filament. Puedes usar el paquete `blade-ui-kit/blade-heroicons` o copiar los SVG en el componente `icono`.
- Para abrir y cerrar el menú en el celular y los menús desplegables, usa Alpine, que ya viene con Livewire.

## Qué debe quedar

1. **Paleta como en SISPAM.**
   - Variables para las escalas `--primary-50` a `--primary-950` y `--gray-50` a `--gray-950`, con los mismos valores de `AdminPanelProvider`, más success, warning, danger e info.
   - Botón primario en el tono 600 (hover 700) en tema claro, y en el 500 (hover 400) en tema oscuro.
   - Fondos y tarjetas con los mismos tonos de gris que usa Filament.
2. **Barra lateral.**
   - Fija a la izquierda en escritorio. En el celular se abre con un botón ☰, como en Filament.
   - Arriba, la marca. Debajo, el menú agrupado:
     - Inicio.
     - Configuración: Empresa, Plan de cuentas, Terceros, Centros de costo, Tipos de comprobante y Periodos.
     - Comprobantes.
     - Reportes: Balance de prueba y Libro auxiliar.
     - Registro de actividad.
   - Grupos plegables y el ítem activo resaltado, como en SISPAM. Lo que aún no existe va deshabilitado o como «En construcción» (con `Route::has()`).
3. **Barra superior** como la de SISPAM.
   - Selector de tema con tres opciones: claro, oscuro y sistema.
   - El espacio del usuario y el botón «Cerrar sesión» preparados pero ocultos hasta que llegue Authentik. Todavía no hay inicio de sesión.
4. **Marca** como `marca.blade.php` de SISPAM: «COMITÉ DE / ESTUDIOS / MÉDICOS», el divisor, el título «Conta CEM» y el subtítulo «Sistema Contable», con las mismas medidas de `sispam-tema.css`.
5. **Encabezado de página** como en Filament: título de 25 px en negrita, migas de pan y botones de acción a la derecha. Reemplaza al encabezado alto de 115 px y al enlace «← Volver».
6. **Componentes** con el aspecto de Filament más `sispam-tema.css`:
   - **Secciones:** tarjetas con encabezado y descripción.
   - **Tablas:**
     - Barra de búsqueda arriba.
     - Encabezados en mayúsculas de 12 px.
     - Filas con divisor y efecto al pasar el cursor.
     - Acciones por fila con ícono.
     - Estado vacío con ícono y texto.
     - Paginación.
   - **Formularios:**
     - Etiqueta arriba y asterisco en los campos obligatorios.
     - Campos con borde suave y anillo azul al enfocar.
     - Ayuda y errores en rojo debajo del campo.
   - **Botones:** radio de 8 px, en negrita, en variantes primario, gris y peligro.
   - **Insignias:** en píldora, con fondo suave de su color.
   - **Notificaciones:** como las de Filament (arriba a la derecha), para los mensajes de éxito, advertencia y error.
   - **Confirmaciones:** ventanas modales para acciones como anular.
7. **Inicio.**
   - Los 4 indicadores con el aspecto de las estadísticas de Filament.
   - Las tarjetas de módulo como secciones.
   - Las acciones usan el azul institucional, como en SISPAM. Los colores por módulo quedan solo en los íconos de esas tarjetas.
8. **Texto:** Arial, con tamaño base de 14 px en tablas y formularios, como Filament. Los títulos van como en SISPAM.
9. **Páginas de error** como `errors/plantilla.blade.php` de SISPAM.
10. **Lo que ya funciona se mantiene:** formato de fechas y pesos, valores alineados a la derecha, Livewire y el tema sin parpadeo.

## Reglas

- Solo interfaz: layout, componentes, vistas, `conta.css` y `theme.js`. No cambies modelos, servicios, migraciones, rutas ni reglas contables.
- Pasa al estilo nuevo todas las pantallas que existen: inicio, configuración, empresa, terceros, centros de costo, bitácora y errores.
- Actualiza el CLAUDE.md: reemplaza «Identidad visual» y «Convenciones de interfaz» por la nueva estructura y los componentes, para que los pasos 4 a 8 nazcan con este estilo.
- Cuando termines, borra este archivo.

## Cómo trabajar

1. Antes de editar, muéstrame el plan (componentes nuevos o cambiados y cómo vas a tomar las medidas de SISPAM) y espera mi visto bueno.
2. Haz commits «Estilos: …».
3. Corre las pruebas (contra `conta_cem_test`). Revisa cada pantalla en tema claro y oscuro, en escritorio y en celular.
4. Dime qué revisar en http://127.0.0.1:8080 para compararlo con SISPAM.
5. Después sigue con el paso 4.
