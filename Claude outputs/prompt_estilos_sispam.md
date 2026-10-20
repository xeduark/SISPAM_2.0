# Mejoras de estilos en SISPAM 2 (tarea aparte de la fase en curso)

Esta tarea es solo de estilos y va aparte de la fase en la que estás trabajando. No abandones esa fase ni cambies su alcance:

- Si estás a mitad de un paso de la fase, termínalo y haz su commit antes de empezar.
- Revisa `git status`. No mezcles en tus commits cambios pendientes de la fase, y si un archivo de esta tarea tiene cambios sin commit, avísame antes de tocarlo.
- Usa commits propios para esta tarea (por ejemplo «Estilos: …»).
- Cuando termines, retoma la fase justo donde ibas.

## Reglas

- Sigue `estilos_generador_consolidado.md` y la sección «Estilos visuales» del CLAUDE.md. No cambies la apariencia general ni agregues colores o fuentes fuera de la guía.
- No compiles Tailwind ni instales Node: el panel usa el CSS ya compilado de Filament 3 más `public/css/sispam-tema.css`.
- Solo toca CSS y atributos de clase en las vistas. No cambies modelos, servicios, migraciones, rutas, pruebas ni lógica de Livewire.
- No toques `resources/views/tickets/impresion.blade.php`: es para la impresora térmica y debe seguir en blanco y negro.
- Respeta el estilo de `sispam-tema.css`: clases `sispam-*` con BEM, comentarios en español que citen la sección de la guía (§) y solo variables del tema, sin colores sueltos.

## 1. Clases de Tailwind que no existen (lo más importante)

Filament 3 trae su CSS precompilado con solo las clases que usa él mismo. Las clases que no están en `public/css/filament/**/*.css` ni en `sispam-tema.css` se ignoran sin dar error. Hay 35 en 9 vistas (verifícalo tú mismo, la lista puede no estar completa). Rutas relativas a `resources/views/`:

- `filament/pages/consultar-paciente.blade.php`: bg-warning-50, border-warning-300, text-warning-800, dark:bg-warning-950/40, dark:border-warning-700, dark:text-warning-300, sm:grid-cols-2, lg:grid-cols-3, uppercase, tracking-wide, mt-4, dark:text-gray-300.
- `filament/pages/llamar-turnos.blade.php`: text-5xl, sm:grid-cols-2, mt-4, dark:divide-gray-700.
- `filament/pages/atender-entrega.blade.php`: list-decimal, pl-5, sm:grid-cols-2, mt-4, dark:text-gray-300.
- `filament/pages/orientacion.blade.php`: uppercase, tracking-widest, ps-5, dark:text-gray-300.
- `filament/pages/reporte-entregas.blade.php`: dark:border-gray-800, pr-3, mt-4.
- `filament/pages/existencias-inventario.blade.php`: dark:border-gray-800, pr-3, uppercase.
- `filament/resources/transcripcion/revisar.blade.php`: dark:text-gray-300, pl-5.
- `filament/pacientes/acciones-guardado.blade.php` y `livewire/selector-de-sede.blade.php`: opacity-60.

Reemplázalas por clases `sispam-*` en `sispam-tema.css` (o por clases que sí existan en el CSS de Filament) para que quede así:

- El aviso «Este afiliado no está activo en Savia» de Consultar paciente, con fondo, borde y texto de advertencia en ambos temas.
- Las secciones de datos (por ejemplo «Programas especiales y RIAS») en 1 columna en teléfono, 2 desde 640 px y 3 desde 1024 px.
- El número del turno en atención de Llamar turnos a 48 px.
- Las etiquetas en mayúsculas con su espaciado entre letras, y las listas con numeración o viñetas y sangría.
- En tema oscuro, los textos secundarios legibles y los bordes y divisores oscuros.
- Los botones atenuados mientras se guarda.

## 2. Contraste de los botones

Hoy no llegan a 4,5:1 (WCAG AA) el botón primario en tema oscuro (blanco sobre #4d8ac4: 3,6:1, y 3:1 en hover) ni el de advertencia (3,6:1 en tema oscuro y 4,4:1 en claro). Súbelos a 4,5:1 o más sin cambiar los colores base de la guía, también en hover y foco. Por ejemplo: texto #101417 en los botones con relleno del tema oscuro y fondo warning-700 en el botón de advertencia del tema claro. Aplica el cambio solo a los botones con relleno, no a los de contorno.

## 3. Un solo archivo de variables

La paleta está copiada en `AdminPanelProvider`, `sispam-tema.css`, `errors/plantilla.blade.php` y `sala/turnos.blade.php`. Crea `public/css/cem-variables.css` con las variables de la guía para tema claro y oscuro, y úsalo en el panel, en la plantilla de errores y en la pantalla de sala en vez de repetir los valores (la pantalla de sala sigue siempre en tema oscuro). Las paletas PHP de `AdminPanelProvider` se quedan porque Filament las necesita; agrégales un comentario que diga que deben coincidir con ese archivo.

Pasa también `actas/entrega.blade.php` y `ordenes/entrega.blade.php` a Arial y a los colores de la guía (texto, gris secundario y el rojo #c53030 en vez de #b91c1c), sin perder legibilidad al imprimir.

## 4. Detalles

- La plantilla de errores usa `prefers-color-scheme`. Haz que use el tema que la persona eligió en el panel (la preferencia que Filament guarda en localStorage; verifica la clave en vendor) y el del sistema operativo solo si no eligió ninguno.
- Las tarjetas (secciones, tablas y estadísticas) con borde 1px #dfe4e8 en tema claro y #303a40 en oscuro, en vez del ring translúcido de Filament, sin que el borde quede doble.
- Títulos y texto principal en #1f2933 (claro) y #edf2f4 (oscuro), en vez de #101417 y blanco.
- En `sispam-tema.css`, cambia `.fi-btn-color-primary` (obsoleta en Filament) por `.fi-color-primary`.

## 5. Que no vuelva a pasar

- Crea `scripts/verificar-clases-css.php` (PHP, sin Node) que revise las vistas que se pintan dentro del panel (`resources/views/filament` y `resources/views/livewire`) y liste las clases que no existen en `public/css/filament/**/*.css` ni en `sispam-tema.css`. Que termine con error si encuentra alguna.
- Agrega al CLAUDE.md, en «Estilos visuales», que en vistas propias no se usan clases de Tailwind que Filament no traiga compiladas (se crea una clase `sispam-*`), y que hay que correr ese script antes de cerrar cambios en vistas.

## Cómo trabajar

1. Antes de editar, muéstrame la lista de archivos que vas a tocar y espera mi visto bueno.
2. Al terminar, el script debe salir sin clases faltantes y las pruebas deben pasar como indica el CLAUDE.md.
3. Dime qué revisar en tema claro y oscuro: Consultar paciente con un afiliado inactivo, Llamar turnos, Atender entrega, Orientación, Reporte de entregas, Existencias, Revisar transcripción, una página de error, la pantalla de sala y un acta de entrega.
