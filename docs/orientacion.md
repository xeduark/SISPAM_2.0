# Orientación: recibir al paciente en una sola pantalla

La pantalla donde el orientador atiende la fila. Reemplaza, para el día a día,
el recorrido por el asistente de 5 pasos de Pacientes: se escribe el documento,
Savia llena los datos, se confirma el contacto, se toma la foto de la fórmula y
sale el turno.

Van las **fases 1 a 4** y el retiro del paso Orientación del asistente: la
pantalla, las varias fotos por visita, el aviso
cuando el paciente ya tiene una visita viva y la galería de fórmulas en su
ficha.

## Dónde está

`/admin/orientacion` — `App\Filament\Pages\Orientacion`.

Permiso **`orientacion.usar`**, el mismo que gobierna el paso Orientación del
asistente: quien puede abrir una visita allá puede abrirla aquí. No se creó un
módulo nuevo en la matriz. El `ORIENTADOR` sembrado ya lo tiene.

## Está hecha para el celular

El orientador trabaja en el teléfono, así que la pantalla es de **una sola
columna**, con los campos grandes y el botón de generar al alcance del pulgar.
En pantallas anchas las secciones se abren a dos columnas solas, con el grid
que ya trae Filament. El campo del documento abre el teclado numérico
(`inputmode="numeric"`).

Los estilos viven en `public/css/sispam-tema.css`, bajo el comentario
«Orientación», y no introducen colores ni fuentes fuera de
`estilos_generador_consolidado.md`: reusan las variables del tema y la paleta
`primary` registrada en `AdminPanelProvider`.

## Los tres momentos

```
① vacío      Solo el buscador. Documento → Enter.
② consultado La ficha del afiliado, el contacto por confirmar, la fórmula
             y la prioridad sugerida. Abajo, «Generar ticket».
③ generado   El turno en grande, para que el paciente lo lea desde el otro
             lado del mostrador. Botones «Imprimir ticket» y «Atender el
             que sigue».
```

### Por qué el turno va enorme en pantalla

La impresora térmica de 80 mm está en el mostrador y el orientador trabaja en
el celular. Si generar el ticket dependiera de que el teléfono alcance la
impresora, una impresora caída dejaría sin atender la fila.

Así que el turno **se lee en la pantalla del celular** y la impresión es un
extra: el botón «Imprimir ticket» aparece con el permiso `tickets.imprimir`, y
quien tenga el PC del mostrador siempre puede reimprimir desde **Tickets**.

## Qué reutiliza, y por qué no duplica nada

| Lo que hace | De dónde sale |
|---|---|
| Consultar en Savia, con sus avisos en español | `PacienteResource::consultarEnSavia()` |
| Los modales de «no encontrado» e «inactivo» | `MuestraAvisosDeSavia` |
| Qué manda Savia y qué manda SISPAM | `Paciente::CAMPOS_SAVIA` y `CAMPOS_CONTACTO` |
| Sugerir la prioridad por edad y discapacidad | `Ticket::prioridadSugeridaPara()` |
| Normalizar las fórmulas y separar los datos del ticket | `PacienteResource::separarOrdenesMedicas()` y `separarDatosDelTicket()` |
| Armar el ticket: cola, turno y número | `Services\Tickets\GenerarTicket` |

Lo único propio es el camino: `Services\Orientacion\RegistrarVisita`.

### La sugerencia de prioridad se mudó a `Ticket`

Antes vivía en `PacienteResource::motivoSugerido()`, atada a un `Forms\Get`.
Ahora está en `Ticket::motivoPrioridadSugerido($fechaNacimiento, $discapacidad)`
y el formulario de Pacientes la llama desde ahí.

El motivo: hay **dos pantallas que abren visitas** y las dos tienen que sugerir
lo mismo. Con la regla en el modelo, cambiar el umbral de edad se hace en un
solo sitio.

## `RegistrarVisita`: una transacción, tres cosas

```
RegistrarVisita::handle(atributos, contacto, ordenes, datosTicket, usuario)
        │
        ├── firstOrNew por tipo + número   ← crea o actualiza, nunca duplica
        │     + los CAMPOS_CONTACTO aparte
        │     + contacto_confirmado_at / _por
        │
        ├── GenerarTicket                  ← si falla, se sigue adelante
        │
        └── un soporte por hoja, con su página, colgado del ticket
```

Dos decisiones que conviene no deshacer:

**El contacto se escribe aparte de los atributos de Savia**, y solo con las
columnas de `CAMPOS_CONTACTO`. Es lo mismo que hace el asistente, y es lo que
mantiene la regla: ninguna consulta puede pisar el teléfono y la dirección que
el personal confirmó con el paciente.

**Si no se puede generar el ticket, el paciente y su fórmula igual se guardan.**
Pasa cuando la sede no tiene colas activas. Perder la orientación —la consulta,
el contacto confirmado y la foto— por un problema de configuración sería mucho
peor que quedarse sin turno. El soporte queda con `ticket_id` nulo y se avisa
en rojo; al arreglar la configuración se genera el ticket editando al paciente.

## Esta es la única pantalla que abre visitas

El asistente de Pacientes tenía un paso **Orientación** que también generaba
tickets. **Se retiró.** Ahora `/admin/pacientes` sirve solo para registrar y
corregir la ficha —los ~78 campos de Savia en 4 pasos—, y las visitas nacen
aquí.

Se retiró por tres razones:

1. **El argumento que lo sostenía era falso.** La doc decía que una fórmula
   sin ticket se recuperaba «volviendo a editar el paciente». No funcionaba: el
   campo sale vacío al editar —no es columna del paciente—, así que había que
   volver a subir el archivo, Filament le daba un ULID nuevo, y el trait
   buscaba el huérfano por la ruta exacta sin encontrarlo. El resultado era un
   **soporte nuevo con ticket y el huérfano colgando para siempre**. Generaba
   basura, no recuperaba nada. Ahora hay una salida que sí funciona: ver
   «La fórmula que quedó sin turno».
2. **Los tickets que nacían ahí eran de peor calidad**: una sola hoja en vez de
   diez, sin aviso de visita repetida y sin el turno en grande para el paciente.
3. **Eran dos filosofías para el mismo problema.** El asistente evitaba
   duplicados con idempotencia por ruta de archivo —silenciosa, técnica—; esta
   pantalla avisa y deja decidir. Mantener las dos era mantener dos reglas que
   podían divergir.

Con el paso se fueron el trait `GeneraTicketDeLaVisita`, el helper
`separarSoporte()` y las pruebas de su idempotencia, que se quedaron sin objeto.

**Registrar un paciente ya no exige fórmula.** Es deliberado: registrar a
alguien y atender su visita son dos cosas distintas, y mezclarlas era parte de
lo que hacía largo aquel formulario.

## La fórmula que quedó sin turno

Cuando la sede no tiene colas activas, la fórmula se guarda igual —perderla
sería peor— pero queda sin ticket. Al consultar a ese paciente, la pantalla lo
avisa en rojo y ofrece **«Generar el turno de esa fórmula»**.

Las fotos ya están en el disco, así que **no hay que volver a tomarlas**, y
tampoco se pide confirmar el contacto otra vez: eso se hizo el día que se
cargaron. Solo se usa la prioridad que esté en pantalla, leída del estado crudo
del formulario para no disparar su validación.

**Solo recupera las hojas de la carga más reciente.** Si una sede estuvo mal
configurada dos días distintos, son dos fórmulas distintas y juntarlas en un
ticket mezclaría dos atenciones; las anteriores se vuelven a ofrecer la próxima
vez. Al adoptarlas se renumeran desde 1, por si venían de varias cargas del
mismo día.

Si la sede **sigue** sin colas, no se genera nada y se avisa: la fórmula no se
toca y sigue esperando.

## Varias fotos por visita

Una fórmula rara vez cabe en una hoja: trae anexos, va por ambas caras o son
dos páginas. La sección **Fórmula médica** toma de 1 a 10 archivos de hasta
10 MB: JPG, PNG, WEBP o PDF.

**Un soporte por archivo**, todos colgados del mismo ticket, cada uno con su
`pagina`. Migración: `2026_10_07_100000_varias_formulas_por_visita`.

### Por qué un soporte por archivo

`soportes.ticket_id` ya existía y la agrupación por visita ya estaba hecha; lo
que faltaba era el orden. Y lo decisivo: **la ruta protegida y la auditoría
trabajan por soporte**. Un soporte por archivo deja el controlador y el rastro
intactos, y hace que quede registrado quién abrió *cuál* hoja. Un JSON con
varias rutas obligaría a indexar la ruta y a perder esa precisión.

El **nombre original del archivo no se guarda**: los celulares suben cosas como
`formula_JUAN_PEREZ.pdf`, y eso metería identificación del paciente en una
columna que hoy no la tiene.

### El orden de la cuadrícula es el orden de las hojas

El campo usa el `FileUpload` de Filament, no un componente propio: lo que
hacía falta ya venía, y escribirlo a mano habrían sido unas 400 líneas de JS.

| Lo que hace | Cómo |
|---|---|
| Miniaturas en cuadrícula | `panelLayout('grid')` |
| Agregar más sin perder las anteriores | `appendFiles()` |
| Reordenar arrastrando | `reorderable()` |
| Ver en grande y rotar | `imageEditor()` |
| Reducir a 2000 px **en el navegador** | `imageResizeMode('contain')` + `imageResizeTargetWidth` |
| Cámara o galería en el mismo botón | sin `capture` |

Al reordenar en pantalla cambia el arreglo que llega al servidor, y
`RegistrarVisita` escribe esa posición como `pagina`. `Ticket::soportes()`
devuelve las hojas ya ordenadas, para que ninguna pantalla tenga que acordarse.

**El redimensionado pasa en el navegador**, antes de subir: una foto de celular
de 4 MB sale en unos 400 KB. Al redibujarse en el lienzo la imagen **se
endereza sola** según el EXIF y **pierde los metadatos**, el GPS incluido.

### Por qué no hay botón de «abrir»

`openable()` quedaría muerto aquí: mientras no se envía el formulario los
archivos son temporales y `getUploadedFiles()` devuelve `null` para ellos, así
que no hay nada que abrir. Y para los ya guardados caería a `Storage::url()`,
que en el disco privado sería una URL pública a un dato de salud.

Ver una hoja en grande antes de guardar es el **editor de imagen**, que además
permite enderezarla ahí mismo. Verlas después de guardar es la galería, por la
ruta protegida.

### HEIC (iPhone)

**HEIC no está entre los tipos aceptados, y es a propósito.** Pedir
`image/jpeg` es justo lo que hace que iOS convierta la foto al elegirla de la
galería o tomarla; aceptar `image/heic` haría que el iPhone entregara el
original, que es lo que no se puede procesar (GD no lo lee e `imagick` no está
instalado).

El HEIC que llegue igual —compartido desde la app Archivos— se rechaza con un
mensaje que dice qué hacer: volver a tomar la foto desde la cámara o cambiar
**Ajustes → Cámara → Formatos → Más compatible**.

## El paciente que vuelve al mostrador

Casi siempre es porque perdió el papel o trae otra hoja de la misma fórmula, y
en ninguno de los dos casos necesita otro turno. Al consultar, si ya tiene una
visita viva **en la sede de quien atiende**, aparece un aviso ámbar con el
turno, el número y el estado, y tres salidas:

| Salida | Cuándo | Qué hace |
|---|---|---|
| **Reimprimir ese ticket** | Perdió el papel | Abre la impresión del que ya tiene (`tickets.imprimir`) |
| **Sumar estas fotos a esa visita** | Trae otra hoja de la misma fórmula | Cuelga las hojas de ese ticket, **sin consumir otro turno**, siguiendo la numeración donde iba |
| **Generar otro ticket de todos modos** | Trae una fórmula distinta | El flujo normal. El botón grande lo dice, y la decisión queda en la auditoría |

Dos casos, con mensajes distintos (`Services\Orientacion\VisitaAbierta`):

- **Turno de hoy sin cerrar** (`generado`, `en_alistamiento` o `listo`).
- **Parcial con pendientes**, de cualquier fecha: los parciales **no vencen**,
  y entrega los atiende por su número. Ahí el mensaje dice justamente eso.

Si hay los dos, **manda el de hoy**: es la visita en curso y es la que explica
por qué el paciente está otra vez en el mostrador.

**Solo mira la sede de quien atiende.** Las tres salidas exigen misma sede
—imprimir y entregar ya lo exigen—, así que avisar de un ticket ajeno sería
ruido sobre el que nadie puede hacer nada.

El aviso **se cae solo** si la visita se cierra mientras el orientador llena el
formulario: el accesor vuelve a buscar en vez de confiar en el id que viaja en
el estado. Y `RegistrarVisita` comprueba que la visita sea del paciente y de la
sede antes de colgarle nada: ese id viaja por Livewire, y cambiarlo a mano
mezclaría fórmulas de dos personas.

## Ver las fórmulas después

En la ficha del paciente, la sección **Fórmulas médicas** las muestra agrupadas
por visita —de la más reciente a la más vieja— con su turno, su sede, su fecha
y el estado del ticket. Las que quedaron sin ticket van al final, en su grupo.

Tocar una miniatura abre el **visor**: zoom, giro y abrir en otra pestaña. Es
Alpine, que ya viene con Filament, sin librerías nuevas. Los PDF se abren en el
visor del navegador, porque no hay nada que ampliar.

### Las miniaturas, y por qué no auditan

Diez hojas de 2000 px son unos 4 MB. En el 4G del mostrador eso es la
diferencia entre abrir la ficha y quedarse mirando el cargador, así que la
galería pide miniaturas de 400 px (~40 KB) por `soportes/{soporte}/miniatura`.

**Se generan la primera vez que alguien las pide**, no al guardar la visita.
Reducir una imagen toma del orden de 100 ms: hacerlo al registrar metería ese
tiempo en la transacción, justo cuando el paciente espera en el mostrador, y
por una imagen que tal vez nadie mire. Hacerlo al pedirla sale gratis para el
orientador y **cubre los soportes que ya existían sin un comando de relleno**.
Con GD, que ya viene con PHP. Migración:
`2026_10_07_110000_miniaturas_de_las_formulas`.

La ruta de la miniatura **exige el mismo permiso** que el original
(`orientacion.ver_orden`) y sale del mismo disco privado. Lo que no hace es
dejar una línea de auditoría por imagen:

> Si las miniaturas auditaran, abrir la ficha de un paciente con diez hojas
> dejaría diez líneas de «abrió la orden médica» sin que nadie haya leído
> nada, y el rastro de quién sí la leyó quedaría enterrado entre el ruido.
> Una miniatura de 400 px no se lee.

En su lugar queda **una línea por entrada a la galería** (`vio_galeria`, desde
`ViewPaciente::mount()`), y leer una hoja concreta se sigue registrando una por
una en `OrdenMedicaController`. Del rastro, como siempre, solo el documento del
paciente: ni su nombre, ni la ruta del archivo.

## A dónde va la fórmula después

Generar el ticket no termina el recorrido: cada hoja entra sola a la cola del
**módulo de transcripción**, que la lee con IA y propone los medicamentos.

```
Orientación genera el ticket
        │
        ├── un soporte por hoja  ──►  Soporte::created  (AppServiceProvider)
        │                                    │
        │                                    ├── crea la fila en `transcripciones`
        │                                    └── despacha LeerFormula (afterCommit)
        │
        └── el ticket, con su turno          │
                                             ▼
                              la transcriptora revisa y confirma
                                             │
                                             ▼
                                   orden de entrega  ──►  farmacia alista
```

**Orientación no llama a transcripción.** El puente escucha el modelo
`Soporte`, así que esta pantalla no sabe que transcripción existe y
transcripción no sabe de esta pantalla. Lo único que las une es que una crea
soportes y la otra los escucha. Ver `docs/transcripcion.md`.

Los soportes se crean **dentro de la transacción** de `RegistrarVisita` y el
trabajo se despacha con `afterCommit()`, así que si el registro se revierte no
queda ninguna lectura encolada a medias.

### El soporte que adopta un ticket después

Cuando se completa una fórmula que había quedado sin turno, el soporte cambia
de dueño con un `update`, no con un `create`: no vuelve a pasar por el
enganche, y su transcripción se quedaría apuntando a ningún ticket.

Por eso hay un segundo enganche, `Soporte::updated`, que le pasa el ticket a la
transcripción cuando el soporte adopta uno. Transcripción ya era defensiva en
los dos sitios donde más dolería —la orden de entrega y la pantalla de revisión
caen de vuelta al ticket del soporte—, así que la fórmula llegaba igual a
farmacia; lo que se arreglaba mal era su bandeja, que mostraba la columna del
turno vacía y dejaba a quien revisa sin saber de qué paciente de la fila se
trata.

Se escribe en masa a propósito: `ticket_id` no está en
`Transcripcion::CAMPOS_AUDITADOS`, y esto corrige un enlace, no registra la
decisión de nadie.
## Lo que todavía no hace

- **Las fórmulas no se ven en el modal de Alistar.** Quedó fuera de alcance:
  ese módulo no es de quien construyó esto. Con ello queda pendiente el segundo
  camino de permiso en `OrdenMedicaController` (`tickets.alistar` + misma
  sede), que farmacia necesitará para leer la fórmula mientras captura los
  medicamentos.

## Pruebas

```bash
php artisan test tests/Feature/Orientacion/
```

16 pruebas: la visita completa, el sello del contacto confirmado, que un
paciente existente se actualice sin duplicarse, que la consulta no pise el
contacto, las dos sugerencias de prioridad y que el orientador pueda cambiarlas,
lo que no deja pasar (sin confirmar el contacto, sin fórmula, sin consulta
previa, afiliado que Savia no encuentra), la sede sin colas, los permisos, que
la auditoría guarde el documento y nada más, y el turno tras generar.

Y 15 en `FormulasDeLaVisitaTest`: que tres fotos dejen tres soportes en
la misma visita con un solo turno, la numeración de las páginas, que la
relación las devuelva ordenadas, el PDF del escáner, el tipo deducido de la
extensión cuando no hay `mime`, los soportes anteriores en página 1, el tope
de 10, el HEIC rechazado, las hojas guardadas sin ticket cuando no hay colas,
y que el asistente siga tomando una sola fórmula.

17 en `VisitaRepetidaTest`: cuándo se avisa y cuándo no (entregado, anulado, de
ayer, de otra sede, paciente nuevo), que sumar hojas no consuma turno y siga la
numeración, que no se puedan sumar a la visita de otro paciente, la segunda
visita en la auditoría y que el aviso se caiga solo.

Y 21 en `GaleriaDeFormulasTest`, con el acento en lo que más importa: **ninguna
fórmula se abre sin permiso y ninguna sin dejar rastro**, miniaturas incluidas;
más el agrupado por visita, la generación perezosa, el PDF sin miniatura y que
la ruta interna del archivo nunca llegue al HTML.

Y 11 en `FormulaSinTurnoTest`: el aviso, que solo ofrezca la carga más
reciente, la recuperación sin volver a tomar las fotos, la renumeración, la
prioridad de la pantalla, y que si la sede sigue sin colas no se pierda nada.

Y 5 en `PuenteConTranscripcionTest`: que cada hoja entre a la cola con su
ticket, que tres hojas den tres lecturas del mismo ticket, que sumar a una
visita abierta también las mande, que sin colas se lea igual, y que al
completar el turno la transcripción quede colgada de él.

Ninguna toca el servicio real: `Http::fake()` responde en lugar de Savia, y
`Bus::fake()` en lugar de la lectura con IA.
