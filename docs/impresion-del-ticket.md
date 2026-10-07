# Impresión del ticket

El papel que se lleva el paciente cuando el orientador lo registra.

## Puesta en marcha

```bash
php artisan migrate
php artisan db:seed --class=SedeSeeder   # nombres y códigos nuevos
```

Tres migraciones:

- `2026_10_06_100000_renombrar_sedes_y_codigos` — los códigos de tres
  caracteres (ver «Los códigos» más abajo).
- `2026_10_06_110000_dar_permiso_de_imprimir_al_orientador` — le da
  `tickets.imprimir` al rol que ya esté en la base.
- `2026_10_06_120000_turno_por_sede_sin_prefijo` — el turno pierde el prefijo
  de la cola y el consecutivo pasa a ser de la sede.

## Lo que sale en el papel

```
   SEDE: PREMIUM PLAZA (PRP)
 ------------------------------

            0060

 ------------------------------
     TK-PRP-261005-0060

           PACIENTE
   LUZ MARINA ARANGO GARZON
 ------------------------------
     05/10/2026 07:42 AM
      PRIORIDAD: NORMAL

 ┌ – – – – – – – – – – – – – ┐
     ALERTA DE INGRESO
       PREMIUM PLAZA
 └ – – – – – – – – – – – – – ┘
```

Todo en **monospace y mayúsculas**: no es capricho, es lo que imprime limpio en
una térmica y lo que se lee de lejos en el mostrador.

## Lo que NO sale, y por qué

El nombre del paciente **sí** va: tiene que poder reconocer su papel entre
varios en un mostrador. Lo que no entra es lo clínico.

| No sale | Por qué |
|---|---|
| El documento del paciente | No hace falta para reconocer el papel |
| Los medicamentos | Dato clínico directo |
| **El nombre de la cola** | «Alto costo y oncológicos» dice a qué va |
| **El motivo de la prioridad** | «Gestante», «discapacidad» son datos de salud |

La prioridad sale como `PRIORIDAD: NORMAL` o `PRIORIDAD: PREFERENCIAL` —el
qué—, nunca el motivo —el porqué—.

La línea que separa un caso del otro: **un ticket se queda en un mostrador, se
cae al piso y lo recoge cualquiera.** Que diga a nombre de quién es no cuenta
nada de su salud; que diga a qué cola va, sí.

Hay una prueba que falla si algo clínico se asoma
(`test_en_el_papel_no_hay_nada_clinico`). Agregar un campo a
`resources/views/tickets/impresion.blade.php` es ponerlo en el bolsillo de un
desconocido, así que es el sitio para pensarlo dos veces — el mismo papel que
cumple `SalaController::turnosDeLaSede()` para la pantalla de la sala.

## El código de barras

**Todavía no está.** El mockup lo lleva, debajo de la prioridad y con el número
otra vez como leyenda, pero se dejó para cuando se confirme que de verdad hay
escáner en el ingreso. El sitio exacto está marcado con un comentario en la
vista: se agrega ese bloque y nada más cambia.

Cuando llegue el momento, conviene un paquete probado
(`picqer/php-barcode-generator` genera Code128 en SVG, sin dependencias) antes
que escribir el encoder a mano: uno mal hecho no da error, simplemente no lo
lee el escáner, y nadie se entera hasta producción.

## El turno y el número

| | Formato | Ejemplo |
|---|---|---|
| `turno` | **4 dígitos**, sin prefijo | `0060` |
| `numero` | `TK-` + código de sede + `yymmdd` + turno | `TK-PRP-261005-0060` |

**Quitar el prefijo obligó a cambiar quién numera.** Mientras el turno llevaba
`A-` o `B-`, que cada cola contara aparte estaba bien: `A-0060` y `B-0060` eran
distintos. Sin prefijo dejan de serlo, y `TicketConsultaDb::porTurnoDeHoy()`
busca el turno dentro de la sede: con dos colas contando por su lado se
encontraría dos tickets `0060` el mismo día.

Por eso `contadores_turno` pasó de `unique(cola_id, fecha)` a
`unique(sede_id, fecha)`, y el único de `tickets` de
`(sede_id, cola_id, fecha, turno)` a `(sede_id, fecha, turno)`. La migración es
`2026_10_06_120000_turno_por_sede_sin_prefijo`; al juntar contadores de una
misma sede conserva **el mayor**, porque quedarse con el menor repetiría
números ya entregados en papel.

El `prefijo` de la cola sigue existiendo, pero solo como **etiqueta corta** para
distinguirla en pantalla («Dispensación general (A)»).

**Los tickets emitidos antes conservan su número viejo**
(`SP-LA30-20261003-A023`). El módulo de entrega los busca por número exacto, así
que reescribirlos sería dejarlos sin encontrar. Los dos formatos conviven.

## Cómo está hecho

Una ruta, un controlador y una vista suelta:

```
GET /tickets/{ticket}/imprimir  →  TicketImpresionController
                                →  resources/views/tickets/impresion.blade.php
```

**El CSS va embebido.** El panel no compila Tailwind (ver `CLAUDE.md`), y
además una vista de impresión que dependa de un build es una vista que un día
sale en blanco.

**El turno ocupa todo el ancho del papel.** El tamaño no es fijo: lo calcula
`TicketImpresionController::tamanoDelTurno()` según cuántos caracteres tenga el
turno: «0060» son cuatro y «10000» son cinco. Con un tamaño fijo, o el
corto se ve pequeño o el largo se sale del papel. La cuenta sale de que el
contenido mide 66 mm útiles (≈ 187 pt) y una monoespaciada avanza 0,6 em por
carácter; por eso el turno lleva `letter-spacing: 0`.

El papel se declara con `@page { size: 80mm auto }` —ancho fijo, alto según el
contenido— y el contenido va en 72 mm, que es lo que queda descontando los
márgenes del cabezal térmico. El diálogo de impresión se abre solo al cargar:
quien llega ahí viene de pulsar «Imprimir», no a mirar la página.

El botón «Imprimir de nuevo» que se ve en pantalla desaparece al imprimir
(`@media print`).

## Desde dónde se imprime

| Dónde | Cuándo |
|---|---|
| Notificación al generar el ticket | Lo normal: el orientador registra la visita y le da el papel |
| Acción **Imprimir** en el listado de Tickets | Se perdió el papel o se atascó la impresora |
| Acción **Imprimir** en la ficha del ticket | Lo mismo, desde el detalle |

Las tres abren en otra pestaña, para no perder la pantalla en la que se estaba.

## Permisos

**`tickets.imprimir` es aparte de `tickets.ver`**, y esa es la decisión que
importa. El ORIENTADOR genera el ticket pero **no tiene `tickets.ver`**: no
entra al listado de Tickets ni ve los medicamentos de nadie. Si imprimir
dependiera de `ver`, no podría darle el papel al paciente que acaba de
registrar —o habría que darle acceso a todo el módulo, que es peor.

| Rol | Tiene |
|---|---|
| ORIENTADOR | `tickets.imprimir` y nada más de ese módulo |
| DISPENSADOR | No lo lleva: imprimir queda atado a generar la visita |

Además, **cada quien imprime solo lo de su sede**, salvo el administrador: la
misma regla de `FiltraPorSede`. Los botones se ocultan cuando no aplica, pero
quien manda es el controlador: la ruta vuelve a exigir las dos cosas, porque
esconder un botón no impide escribir la URL a mano.

### Dárselo a un rol que ya existe

`RolSeeder` usa `firstOrCreate`: si el rol ya está, **no lo toca**. Es a
propósito —así el seeder no pisa los permisos que el administrador haya
ajustado—, pero también significa que un permiso nuevo nunca llega a una
instalación que ya anda.

Por eso hay una migración de datos,
`2026_10_06_110000_dar_permiso_de_imprimir_al_orientador`, que lee los permisos
del ORIENTADOR, le suma `imprimir` dentro de `tickets` y los vuelve a guardar.
**Solo agrega**: nunca quita acciones ni reemplaza el arreglo. Correrla de
nuevo no cambia nada. Es el mismo patrón de
`2026_10_03_120000_marcar_cola_de_alto_costo`.

## Los códigos de sede

De **tres caracteres**, obligatorios y únicos: es lo que cabe cómodo en 80 mm y
lo que se alcanza a leer de un vistazo.

| Antes | Ahora | Código |
|---|---|---|
| Premium Plaza (PPLZ) | PREMIUM PLAZA | **PRP** |
| BIC (BIC) | EDIFICIO BIC | **BIC** |
| La 30 (LA30) | LA 30 | **L30** |
| Centro Comercial Aventura (AVEN) | AVENTURA | **AVT** |
| Sede Principal (PRIN) | Sede Principal | **SPR** |

**La migración hace un `update`, no borra ni recrea.** Las sedes tienen
usuarios, colas, ventanillas y tickets colgando: recrearlas les daría otro `id`
y rompería todas esas llaves foráneas. Y las busca **por su código actual**,
porque el nombre es justo lo que está cambiando.

**Los tickets ya emitidos conservan su número viejo.** `tickets.numero` lleva
el código dentro y el módulo de entrega busca por él: reescribirlos dejaría
tickets que entrega ya no encuentra. Solo los nuevos salen con el código nuevo.

### La sede siempre con su código

`Sede::etiqueta` arma «PREMIUM PLAZA (PRP)» **en un solo sitio**, y
`Sede::opciones()` lo usa para los selectores y filtros de Usuarios, Tickets,
Colas, Ventanillas, Auditoría, Entregas, Reporte de entregas y
`FiltraPorSede::campoSede`. Que se arme una sola vez es lo que garantiza que
todas las pantallas y el ticket impreso digan exactamente lo mismo.

Como el código va dentro de la etiqueta, escribir «PRP» en cualquiera de esos
selectores ya encuentra la sede: quien atiende se sabe el código antes que el
nombre completo.

## Pruebas

```bash
php artisan test --filter=ImpresionDeTicketTest   # 11
php artisan test --filter=CodigosDeSedeTest       # 13
php artisan test --filter=SedeResourceTest        #  7
```

De la impresión: que con `imprimir` y sin `ver` sí se pueda, que con solo `ver`
no, que sin sesión no, que no se imprima el de otra sede y que el administrador
sí, qué lleva el papel, que el preferencial diga el qué y no el porqué, y que
**no se asome nada del paciente ni de su salud**.

De los códigos: que la migración renombre sin borrar, que conserve el `id` y
todo lo que cuelga de la sede, que los tickets viejos conserven su número, que
correrla dos veces no haga daño, que se pueda volver atrás, que el seeder
encuentre la sede renombrada en vez de duplicarla, la etiqueta con código y el
número del ticket nuevo.
