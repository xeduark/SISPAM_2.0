# SISPAM_2 - Sistema de Dispensación de Medicamentos

## Stack
- Laravel 12 + Livewire 3 + Filament 3 + MySQL

## Convenciones
- Código de negocio en español, estructura Laravel en inglés
- Commits en español
- Mensajes de error y UI en español (usuarios colombianos)

## Estilos visuales
- Toda la UI sigue SIEMPRE `estilos_generador_consolidado.md` (raíz del proyecto):
  paleta institucional, Arial, tema claro/oscuro, radios, sombras, encabezado institucional.
- Implementación en Filament (sin build de Tailwind):
  - Paletas `primary` (azul institucional) y `gray` en `AdminPanelProvider` (tono 600 = claro, 500 = oscuro)
  - Sombras, radios, encabezados de tabla y marca en `public/css/sispam-tema.css`
  - Encabezado institucional en `resources/views/filament/marca.blade.php`
- Páginas o componentes nuevos reutilizan esas variables; no introducir colores ni fuentes fuera de la guía.

## Cómo correr el proyecto localmente
1. Tener XAMPP corriendo (solo MySQL, Apache no es necesario)
2. Crear la base de datos sispam_2 en phpMyAdmin
3. Copiar .env.example a .env y configurar credenciales
4. Ejecutar:
```bash
composer install
php artisan key:generate
php artisan migrate --seed
php artisan serve
```
5. Abrir http://localhost:8000/admin

## Autenticación (Authentik / OIDC)
- No hay login local ni contraseñas: `/admin/login` solo tiene el botón "Iniciar sesión", que redirige a Authentik.
- Flujo: `routes/web.php` → `App\Http\Controllers\Auth\AuthentikController` (Socialite + `socialiteproviders/authentik`).
- El `preferred_username` (username) de Authentik ES el documento: se busca en `users.documento`.
  Solo entran usuarios creados antes en el recurso Usuarios y con `activo = true`; los demás se rechazan.
- Cerrar sesión redirige al end-session de Authentik (`App\Http\Responses\LogoutResponse`).
- Variables `.env`: `AUTHENTIK_BASE_URL`, `AUTHENTIK_CLIENT_ID`, `AUTHENTIK_CLIENT_SECRET`,
  `AUTHENTIK_REDIRECT_URI="${APP_URL}/auth/authentik/callback"`, `AUTHENTIK_APP_SLUG`.

## Módulo Pacientes (Consulta Afiliados — Savia Salud EPS)
- Los pacientes no se escriben a mano: se traen del servicio REST **Consulta Afiliados**
  de Savia Salud EPS (especificación V3), portado desde el proyecto `savia-afiliados`.
- Dos pantallas, con propósitos distintos:
  - **Consultar paciente** (`/admin/consultar-paciente`) — la puerta de entrada. Se escribe
    la cédula, se pulsa Enter y aparecen todos los datos que devuelve el servicio, si está
    activo en Savia y si ya está registrado en SISPAM. Desde ahí se registra o se actualiza.
  - **Pacientes** (`/admin/pacientes`) — el registro local: listado, ficha, edición.
- Archivos:
  - `config/savia.php` — endpoints, tipos de documento, tabla de códigos y los ~78
    campos de la respuesta agrupados y etiquetados en español.
  - `app/Services/Savia/SaviaClient.php` — genera el token, lo cachea, lo renueva tras
    un 401 y consulta. Clave de caché: `savia:token`.
  - `app/Services/Savia/RespuestaConsulta.php` — interpreta la respuesta: diagnóstico
    en español, afiliados y normalizaciones.
  - `app/Models/Paciente.php` — `CAMPOS_SAVIA` traduce los atributos del servicio a
    columnas; lo que no tiene columna propia va a `datos_adicionales`.
  - `app/Filament/Pages/ConsultarPaciente.php` + `resources/views/filament/pages/consultar-paciente.blade.php`
    — la pantalla de consulta. Agrupa los campos usando `config('savia.grupos')`, así que
    muestra todo lo que responda el servicio sin tener que listar campo por campo.
  - `app/Filament/Resources/PacienteResource.php` — el registro local: listado con badge
    de estado, ficha completa y acción «Actualizar desde Savia».
- Variables `.env`: `SAVIA_USERNAME`, `SAVIA_PASSWORD`, `SAVIA_VERIFY_SSL`, `SAVIA_AUDITAR`.
  Los opcionales (`SAVIA_ENDPOINT`, `SAVIA_ENDPOINT_TOKEN`, `SAVIA_GRANT_TYPE`,
  `SAVIA_TIMEOUT`, `SAVIA_TOKEN_MINUTOS`) van **comentados** en `.env.example`: dejarlos
  vacíos no toma el valor por defecto, `env()` devuelve cadena vacía y el endpoint o el
  timeout quedarían en nada.
- Auditoría: cada consulta se registra en el log con usuario, tipo y número de documento,
  HTTP, código y duración. **Nunca la respuesta completa: son datos de salud.**
  Se apaga con `SAVIA_AUDITAR=false`.

### Quién manda sobre cada dato
- **Savia manda** sobre la afiliación: estado, régimen, IPS, Sisbén, programas, nombres.
  Están en `Paciente::CAMPOS_SAVIA` y se reemplazan en cada consulta.
- **SISPAM manda** sobre el contacto: teléfono, dirección, barrio, ciudad e indicaciones
  de entrega (`Paciente::CAMPOS_CONTACTO`). El personal los confirma con el paciente en
  cada registro, porque si el medicamento no está en la sede hay que enviarlo a domicilio.
  Se guarda quién confirmó y cuándo (`contacto_confirmado_at`, `contacto_confirmado_por`).
- Esos campos **a propósito no están en `CAMPOS_SAVIA`**. Como `atributosDesdeSavia()`
  solo devuelve lo que está en ese mapa, ninguna consulta puede sobrescribirlos: la
  protección está en la estructura, no en un `if` que alguien pueda olvidar.
  Lo que Savia reporta como contacto queda en `datos_adicionales`, de referencia.

### Registrar un paciente (`/admin/pacientes/create`)
Todo paciente entra por la consulta a Savia. Según lo que responda:

| Caso | Qué pasa |
|---|---|
| No lo encuentra | Modal rojo. Si el documento ya está en SISPAM, el modal lo dice y no se toca el registro |
| Lo encuentra, es nuevo | Formulario lleno; el contacto se precarga con lo de Savia. Botón «Crear paciente» |
| Lo encuentra, ya existe | No duplica: actualiza. Botón «Actualizar paciente» y lista de «antes → ahora». El contacto se precarga con el de SISPAM, y el de Savia sale como referencia |
| Lo encuentra, inactivo | Se puede guardar, con el modal de advertencia |

La casilla «Confirmé teléfono y dirección con el paciente» es obligatoria al crear y al
editar. `CreatePaciente::handleRecordCreation()` hace `firstOrNew` por tipo + número,
que es lo que convierte el mismo flujo en crear o actualizar.

Los cambios de **estado de afiliación** y **régimen** quedan en el log desde
`Paciente::booted()`, así que cubren todas las rutas (formulario, edición y la acción del
listado): documento, valor anterior, valor nuevo y usuario. Nunca la respuesta completa.
### Tres cosas del servicio que conviene saber
1. **Responde `programas`, no `programasEspeciales`** como dice la V3.
   `RespuestaConsulta::afiliados()` acepta los dos y unifica bajo un solo nombre.
2. **Las descripciones de programas llegan con UTF-8 doblemente codificado**
   («AtenciÃ³n domiciliaria»), mientras el resto de la respuesta viene bien.
   `RespuestaConsulta::repararTexto()` deshace esa capa de más, solo cuando detecta
   la firma del doble encoding, para no dañar el texto correcto.
3. **Las fechas no vienen en un formato único**: unas como `yyyy-MM-dd` y otras como
   `dd/MM/yyyy`. `Paciente::normalizarFecha()` las unifica antes de guardarlas.

### Pruebas
```bash
php artisan test --filter=SaviaClientTest        # el cliente, con Http::fake()
php artisan test --filter=ConsultarPacienteTest  # la pantalla de consulta
php artisan test --filter=PacienteResourceTest   # el registro local
```
Ninguna toca el servicio real. Para una consulta real contra el ambiente de pruebas
hace falta que la IP del equipo esté autorizada por Savia.

## Roles y permisos
- Roles = grupos de Authentik (claim `groups`), copiados a `users.roles` en cada login.
- Matriz por módulo en la tabla `roles` (Administración → Roles y permisos); módulos en `Rol::MODULOS`.
- `users.es_administrador` (se marca en SISPAM) ve todo y es el único que edita la matriz.
- Verificar: `auth()->user()->puede('modulo.accion')`; recursos usan el trait `ControlaPermisos` + `$modulo`.
- Detalle: `docs/asistente-orientacion-y-permisos.md`.

## Auditoría
- Rastro de quién hizo qué **en base de datos** (tabla `auditorias`), no solo en los logs.
  Se ve en **Administración → Auditoría**; módulo `auditoria` de la matriz (solo `ver`).
- **Lista blanca por modelo:** cada modelo auditado declara `CAMPOS_AUDITADOS`.
  **Lo que no esté ahí no se registra**, para que un campo clínico nuevo no entre por olvido.
  Mismo principio que `Paciente::CAMPOS_SAVIA`.
- Del paciente solo queda **su documento**, nunca su nombre ni nada clínico.
  De la orden médica solo queda que se cargó: ni la ruta del archivo ni la marca de alto costo.
- Auditar un modelo: `use Auditable;` + `CAMPOS_AUDITADOS` + `ETIQUETA_AUDITORIA`.
  Registrar algo que no es un cambio de modelo: `Auditoria::registrar(...)`.
- Nadie crea, edita ni borra auditorías desde la pantalla, ni el administrador.
- **Orden médica:** `soportes/{soporte}/orden-medica` sirve el archivo desde el disco
  privado. Exige sesión y el permiso `orientacion.ver_orden`; cada acceso se audita.
  Se llega desde la sección «Órdenes médicas» de la ficha del paciente.
  Nunca hay URL pública para un dato de salud.
- Detalle: `docs/auditoria.md`.

## Sedes
- Sedes reales y sus códigos: **LA 30 (L30), PREMIUM PLAZA (PRP), EDIFICIO BIC (BIC),
  AVENTURA (AVT)**. La 7 entra después.
- «Sede Principal» (SPR) se conserva como sede administrativa.
- **El código es de 3 caracteres**, obligatorio y único: es lo que cabe en el ticket
  impreso de 80 mm y va dentro del número (`TK-PRP-261005-0060`).
- Se siembran con `php artisan db:seed --class=SedeSeeder`. **Busca por código, no por
  nombre**: los nombres cambiaron, así que buscar por nombre duplicaría sedes y chocaría
  con el índice único del código.
- La migración `2026_10_06_100000_renombrar_sedes_y_codigos` renombró las que ya existían
  con un `update` (no borra ni recrea: tienen usuarios, colas, ventanillas y tickets).
  **Los tickets ya emitidos conservan su número viejo**, porque entrega los busca por él.
- **La sede siempre se nombra con su código**: `Sede::etiqueta` → «PREMIUM PLAZA (PRP)»,
  y `Sede::opciones()` para selectores y filtros. Armarla en un solo sitio es lo que
  garantiza que todas las pantallas y el ticket impreso digan lo mismo.
- Un usuario pertenece a **una sola** sede (`users.sede_id`).
## Tickets
- El ticket **es la visita del paciente**. Nace cuando el orientador lo registra con su
  orden médica (`GeneraTicketDeLaVisita` en Create/EditPaciente) y lleva dos identificadores:
  - `numero` (`TK-PRP-261005-0060`) **único en todo el sistema** — es lo que busca entrega.
  - `turno` (`0060`) corto, por sede y día — es lo que ve el paciente.
  El número lleva sede y fecha, así que el mismo turno puede existir en dos sedes
  el mismo día. Por eso **el contrato con entrega no cambia**.
  Los emitidos antes del 2026-10-06 conservan el formato viejo (`SP-LA30-20261003-A023`):
  entrega los busca por número exacto, así que reescribirlos sería perderlos.
- **El turno no lleva prefijo de cola**: es solo el consecutivo de la sede. Por eso
  quien numera es la **sede** y no la cola (`unique(sede_id, fecha)` en
  `contadores_turno`): con cada cola contando aparte, la general y la de alto costo
  sacarían el mismo `0060` el mismo día y `TicketConsultaDb` encontraría dos.
  Migración: `2026_10_06_120000_turno_por_sede_sin_prefijo`.
- **Nace sin medicamentos**, en estado `generado`: los captura farmacia al alistar.
  `listo` y `parcial` son los dos estados que el módulo de entrega atiende.
- **El alto costo lo marca farmacia al alistar, no el orientador**: el orientador no
  conoce los medicamentos, así que no puede clasificarlos. Es **solo una etiqueta**: no
  decide cola ni turno, que para entonces ya están dados. Queda en la auditoría.
  Las colas «Alto costo y oncológicos» quedaron desactivadas en todas las sedes
  (`2026_10_06_130000_alto_costo_lo_marca_farmacia`); `colas.atiende_alto_costo` sigue
  en la tabla pero ya no enruta nada. Todos los tickets nacen en la cola general.
- `soportes.alto_costo_oncologico` quedó nullable: `null` = «no se preguntó».
- **Si la sede no tiene colas, el paciente igual queda registrado** con su orden médica
  y se avisa: perder la orientación por un problema de configuración es peor.
- Prioridad `normal` o `preferencial`; se **sugiere** por edad (≥60) y discapacidad
  según Savia, pero el orientador decide. Los preferenciales se llaman de primeras.
- Pantalla **Tickets** con pestañas Por alistar / Listos / Todos, filtrada por sede.
  No se crea ni se edita desde ahí. Módulo `tickets` (ver, alistar, anular, imprimir).
- **Conexión con entrega:** `TicketConsultaDb` reemplazó al mock, así que Atender entrega
  lee tickets reales. Se puede buscar por número o por el turno corto del día en la sede.
  Al registrar la atención, el ticket queda `entregado` o `parcial` por el puerto
  `TicketCierreInterface`, disparado desde `SincronizarEstadoDelTicket` al guardarse una
  `Entrega`: **no se tocó el código del módulo de entrega**, solo se le agregó la
  validación de sede que el contrato ya pedía.
- Detalle: `docs/tickets.md`, `docs/contrato-ticket-entrega.md` y `docs/impresion-del-ticket.md`.
## Impresión del ticket
- `GET /tickets/{ticket}/imprimir` → `TicketImpresionController` + la vista
  `resources/views/tickets/impresion.blade.php`. Térmica de 80 mm, CSS embebido
  (el panel no compila Tailwind), monospace en mayúsculas y `window.print()` al cargar.
- Lleva, en este orden: `SEDE: <etiqueta>`, el turno en grande, el número, `PACIENTE`
  con su nombre, fecha y hora, `PRIORIDAD: NORMAL|PREFERENCIAL` y la caja
  `ALERTA DE INGRESO` con el nombre de la sede.
- **El nombre del paciente sí va** —tiene que reconocer su papel—; **lo clínico no**:
  ni su documento, ni los medicamentos, ni el nombre de la cola (delataría alto costo),
  ni el motivo de la prioridad. La línea: un ticket se queda en un mostrador y lo recoge
  cualquiera; a nombre de quién es no cuenta nada de su salud, a qué cola va sí. Hay una
  prueba que falla si algo clínico se asoma.
- **El código de barras todavía no está**: el sitio queda marcado con un comentario en
  la vista, para cuando se confirme que hay escáner en el ingreso.
- Botón «Imprimir ticket» en la notificación al generarlo; acción «Imprimir» en el
  listado y en la ficha de Tickets.
- Permiso `tickets.imprimir`, **aparte de `tickets.ver`**: el ORIENTADOR genera el ticket
  y tiene que poder imprimírselo al paciente, pero no entra al listado. DISPENSADOR no lo
  lleva. Cada quien imprime solo lo de su sede, salvo el administrador.
- Como `RolSeeder` usa `firstOrCreate` y no pisa roles existentes, la migración
  `2026_10_06_110000_dar_permiso_de_imprimir_al_orientador` se lo agrega al rol que ya
  esté en la base. **Solo agrega**, nunca quita lo que el administrador haya ajustado.
## Cierre del día
- `php artisan tickets:cerrar-dia` vence lo que nadie alcanzó a atender en días pasados.
  Programado a las 2:00 a.m. en `routes/console.php`; correrlo a mano hace lo mismo.
- Vencen `generado`, `en_alistamiento` y `listo`. **`parcial` no vence**: ese paciente
  sí fue atendido y los faltantes son los que vuelve a reclamar otro día, y entrega los
  encuentra por número sin importar la fecha.
- **Cierra hasta ayer, nunca el día en curso.** Mirar solo hacia atrás lo hace
  idempotente: si un día no corre, al siguiente recoge lo que quedó.
- Deja **una línea de auditoría por sede y por corrida** (`cerro_dia`), no una por
  ticket: el `update` masivo no dispara el trait a propósito.
- `estado_sala` no se toca: así se distingue «nadie lo llamó» de «no se presentó».
- Detalle: `docs/cierre-del-dia.md`.
## Colas, ventanillas y turnos
- **Colas por sede.** Su `prefijo` (`A`, `B`) **ya no arma el turno**: quedó como
  etiqueta corta para distinguirlas en pantalla («Dispensación general (A)»).
  Ventanillas por sede, sin amarrarse a una cola: quien llama elige de cuál.
- **El consecutivo es por sede y por día** y se reinicia cada mañana. Vive en
  `contadores_turno` con `unique(sede_id, fecha)`, y `GeneradorDeTurnos` lo toma con
  `insertOrIgnore` + `lockForUpdate` dentro de una transacción: varios orientadores
  pidiendo turno a la vez nunca sacan el mismo número.
- Pedir un turno: `app(GeneradorDeTurnos::class)->siguiente($sede)`.
- Una cola «ya entregó turnos» lo dicen **sus tickets**, no el contador: el contador es
  de la sede, así que una cola nueva en una sede que ya atendió hoy parecería usada.
- **Todo separado por sede:** el trait `FiltraPorSede` filtra el listado por
  `users.sede_id` y fija el campo Sede del formulario. El administrador ve todas.
  Es el patrón que reutilizan los módulos nuevos.
- Una cola que ya entregó turnos **se desactiva, no se borra**. Una sede con usuarios,
  colas o ventanillas no se elimina.
- Módulo `colas` de la matriz. Siembra: `php artisan db:seed --class=ColaSeeder`.
- Detalle: `docs/colas-y-turnos.md`.
## Llamado de turnos y pantalla de sala
- **El ticket lleva dos ciclos:** `estado` es el de la fórmula y `estado_sala`
  el del turno (`en_espera` → `llamado` → `ausente` / `atendido`). Llamar
  **no toca `estado`**: entrega solo atiende `listo` y `parcial`, así que si el
  llamado lo moviera, el paciente que acaban de llamar sería justo el único que
  no se podría atender. El contrato con entrega no cambió.
- **Llamar turnos** (`/admin/llamar-turnos`): quien atiende escoge su ventanilla
  (queda guardada en la sesión) y, si quiere, de cuáles colas llama. Llama el
  siguiente, vuelve a llamar y marca a los que no se presentaron. Se refresca
  sola cada 15 segundos.
- **Los preferenciales de primeras** y, dentro de cada prioridad, por orden de
  llegada. `LlamadorDeTurnos::siguiente()` toma el candidato con `lockForUpdate`
  dentro de una transacción: dos ventanillas nunca se llevan el mismo turno.
- **El ausente no pierde el turno:** sale de la espera, queda en su lista y se
  vuelve a llamar con un botón. Es un permiso aparte (`turnos.ausente`).
- **Pantalla de la sala**: `/sala/{codigo}` (ej. `/sala/LA30`). **Pública a
  propósito** —un televisor no inicia sesión— y por eso solo muestra turno,
  ventanilla y hora. Qué sale ahí se decide en un solo método,
  `SalaController::turnosDeLaSede()`: **agregar un campo es publicarlo en la
  sala de espera**. Una sede inactiva responde 404.
- Cada llamado queda en la tabla `llamados` (ticket, ventanilla, quién, intento).
  No se duplica en `auditorias`: esa tabla ya es el registro.
- Módulo `turnos` de la matriz: ver, llamar, ausente.
- Detalle: `docs/llamado-de-turnos.md`.
## Acceso local
- URL: http://localhost:8000/admin
- Usuario administrador sembrado: documento `AdminSispam` (debe existir con ese username en Authentik; la contraseña se gestiona allá)

## Base de datos
- Nombre: sispam_2
- Motor: MySQL (XAMPP)
