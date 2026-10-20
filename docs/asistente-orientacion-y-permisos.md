# Asistente de pacientes, orientación y matriz de permisos

Este documento resume los cambios que llegan en este commit. Se escribió
para el equipo de desarrollo de SISPAM y para quien configure Authentik.

## Resumen

| Cambio | Qué resuelve |
|---|---|
| Formulario de pacientes por pasos (wizard) | El formulario largo de Savia queda en 5 pasos que se validan uno a uno. |
| Paso **Orientación** | El orientador carga la orden médica (foto o archivo) y marca la prioridad. El alto costo lo marca farmacia al alistar. |
| Tabla `soportes` | Cada orden médica queda ligada al paciente. Más adelante se le ligará el ticket. |
| Matriz de permisos por módulo | Cada rol (grupo de Authentik) tiene acciones por módulo. |
| Administrador en SISPAM | El administrador ve todo y es el único que edita la matriz. |

## Puesta en marcha

```bash
php artisan migrate
php artisan db:seed --class=AdminUserSeeder   # marca AdminSispam como administrador
php artisan db:seed --class=RolSeeder         # crea el rol ORIENTADOR si no existe
```

Las dos migraciones nuevas son:

- `2026_09_30_200000_crear_soportes_y_roles`: agrega `users.roles` (JSON) y crea la tabla `soportes`.
- `2026_09_30_300000_crear_matriz_de_permisos`: agrega `users.es_administrador` y crea la tabla `roles`.

Los dos seeders se pueden volver a correr sin problema: `AdminUserSeeder` usa
`updateOrCreate` y `RolSeeder` usa `firstOrCreate`. `RolSeeder` no sobrescribe
los permisos que el administrador ya haya cambiado.

## 1. Formulario de pacientes por pasos

El formulario usa el componente `Wizard` de Filament: no hay JS propio ni
librerías nuevas, y respeta la paleta institucional del panel.

- **Arriba, sin cambios:** la consulta en Savia (tipo y número de documento)
  y el recuadro de cambios frente a SISPAM.
- **El asistente aparece después de una consulta exitosa.** Al editar, se ve
  siempre.

| Paso | Contenido |
|---|---|
| 1. Identificación | Nombres, apellidos, fecha de nacimiento, sexo, género, estado civil. |
| 2. Ubicación y contacto | Teléfonos, ciudad, dirección, barrio, indicaciones de entrega y la casilla de confirmación. También la residencia según Savia. |
| 3. Afiliación | Estado de la afiliación, IPS y portabilidad, núcleo familiar. |
| 4. Caracterización | Condición de salud, Sisbén, programas y RIAS, otros datos de Savia, observación. |
| ~~5. Orientación~~ | **Retirado.** La fórmula y la prioridad se toman en `/admin/orientacion`. |

Comportamiento:

- **"Siguiente" valida en el servidor los obligatorios del paso actual.** Por
  ejemplo, el paso 2 no deja avanzar sin teléfono, dirección, ciudad y la
  confirmación del contacto.
- **Los botones de guardar solo aparecen en el último paso.** Son "Crear
  paciente", "Actualizar paciente" y "Crear y crear otro". Abajo del
  formulario queda solo "Cancelar", que funciona incluso antes de consultar.
- **Al editar se puede saltar a cualquier paso** haciendo clic en su nombre.

Cómo funciona por dentro: los botones del último paso se pintan con la vista
`resources/views/filament/pacientes/acciones-guardado.blade.php`, que llama a
`accionesDeGuardado()` de la página (`CreatePaciente` o `EditPaciente`). La
vista se evalúa al renderizar. Por eso las reglas que ocultan los botones se
aplican con el resultado de la consulta a Savia ya cargado.

## 2. Orientación: orden médica y prioridad

> **Esta sección describe un paso que ya no existe.** Se retiró del asistente:
> la fórmula y la prioridad se toman en la pantalla **Orientación**
> (`/admin/orientacion`), que es la única que abre visitas. Con el paso se
> fueron el trait `GeneraTicketDeLaVisita` y
> `PacienteResource::separarSoporte()`, y registrar un paciente aquí **ya no
> exige fórmula**. Se conserva como historia de por qué las reglas son como
> son; lo vigente está en `docs/orientacion.md`.

Este paso solo lo veía quien tuviera el permiso `orientacion.usar`, además de
los administradores.

| Campo | Regla |
|---|---|
| Orden médica | JPG, PNG, WEBP o PDF de máximo 10 MB. Es obligatoria al **registrar** un paciente y opcional al **editar**. |
| Prioridad en la fila | Normal o preferencial; se **sugiere** con los datos de Savia pero el orientador decide. |

**Aquí no se pregunta por alto costo.** Se preguntaba, y se quitó: el
orientador no conoce los medicamentos —por eso el ticket nace sin ellos— así
que tampoco puede clasificarlos. Lo marca **farmacia al alistar**, con la
fórmula a la vista, y sirve solo para identificar el ticket. Ver
`docs/tickets.md`.

Por eso `soportes.alto_costo_oncologico` quedó **nullable**: en los soportes
nuevos vale `null`, que quiere decir «no se preguntó». Lo que respondieron los
orientadores hasta ahora se conserva.

- **Cámara o archivo:** en el celular, el mismo botón ofrece la cámara o los
  archivos del dispositivo. No se usa el atributo `capture`, porque obligaría
  a usar solo la cámara.
- **Almacenamiento:** los archivos van al disco privado `local`, en
  `storage/app/private/soportes/ordenes-medicas`. Nunca se guardan en `public`,
  porque son datos de salud.
- **Registro:** cada carga crea una fila en `soportes` con `paciente_id`,
  `orden_medica`, `cargado_por` y fechas. Esto lo hace
  `PacienteResource::separarSoporte()`.
- **Pacientes que ya existían:** si el documento ya estaba registrado, el
  flujo de registro actualiza ese paciente y le suma el soporte.

**Ruta protegida de la orden médica: hecha.** Desde la ficha del paciente, la
sección **Órdenes médicas** lleva a `soportes/{soporte}/orden-medica`. Exige
sesión y el permiso `orientacion.ver_orden`, sirve el archivo desde el disco
privado y **cada acceso queda en la auditoría**. Ver `docs/auditoria.md`.

La generación del ticket se hace desde aquí: cargar una orden médica abre una
visita. Detalle en `docs/tickets.md`.


## 3. Roles y matriz de permisos

### Administrador

- Se marca con el campo `users.es_administrador`, desde **Usuarios →
  Administrador**. Solo otro administrador ve ese interruptor.
- **Ve todos los módulos, sin importar sus roles.** Eso incluye el paso
  Orientación, así que también debe cargar la orden médica al registrar un
  paciente.
- **Es el único que entra a Administración → Roles y permisos.**
- **Vive en SISPAM y no en Authentik** para que el sistema nunca quede sin
  quien lo administre.

### Roles desde Authentik

- **Los roles son los grupos del usuario en Authentik** (claim `groups`, que
  ya viene en el scope `profile`).
- **Se copian a `users.roles` en cada inicio de sesión**, en
  `AuthentikController`. Si se quita a alguien de un grupo, pierde el rol la
  próxima vez que entre.
- **El nombre del rol en SISPAM debe ser igual al del grupo.** Al guardar un
  rol, SISPAM lo convierte a mayúsculas, así que conviene nombrar los grupos
  de Authentik en mayúsculas (por ejemplo, `ORIENTADOR`).
- **Un administrador también los asigna desde SISPAM**, en el formulario de
  Usuarios: al guardar, SISPAM se los pone como grupos en Authentik (ver la
  sección siguiente).

### Cuentas en Authentik desde Usuarios

Crear a alguien en **Administración → Usuarios** le crea también la cuenta en
Authentik, para que pueda entrar sin que nadie más tenga que tocar Authentik
(`App\Services\Authentik\AprovisionarEnAuthentik`, con `AUTHENTIK_API_TOKEN`).

- **El username es el documento**, y los **roles** que se eligen en el
  formulario son sus grupos allá. Solo se tocan los grupos que son roles de
  SISPAM: los de otras aplicaciones del mismo Authentik quedan como estaban.
  Si el grupo de un rol no existe, se crea. **Nunca se asigna un grupo de
  superusuario de Authentik**, aunque se llame como un rol.
- **La contraseña la escribe quien lo crea**, en el formulario (mínimo 8
  caracteres). SISPAM se la pasa a Authentik y no la guarda, no la muestra en
  ningún aviso y no la registra (`clave_authentik` no es una columna de
  `users`). Para cambiarla después: acción **Cambiar contraseña**, en la
  lista y en la edición; solo para cuentas que creó SISPAM (atributo
  `creado_desde: SISPAM` en Authentik).
- **Si ya tenía cuenta** (la usa en otra aplicación), se usa esa: no se
  duplica, y los roles elegidos **se suman** a los que ya tenía allá (al crear
  nadie le quita roles que no está viendo). Si esa cuenta la creó SISPAM, se
  le pone la contraseña escrita; si no, **conserva la suya**, que puede usar en
  otras aplicaciones, y el aviso lo dice.
- **Si Authentik no responde o no acepta**, no se guarda nada en SISPAM y el
  aviso dice por qué.
- **Editar** solo va a Authentik si cambiaron los roles o el documento. Si
  cambiaron los roles, allá quedan **exactamente** los de SISPAM elegidos. Si
  solo cambió el documento, se renombra su cuenta (en vez de crear otra) y sus
  grupos no se tocan. Un rol que le den directamente en Authentik se ve en
  SISPAM cuando vuelva a entrar. Si al editar se le crea la cuenta (no tenía),
  queda sin contraseña y el aviso pide ponérsela con «Cambiar contraseña».
- **Desactivar o eliminar en SISPAM no toca Authentik.** El ingreso ya lo
  niega SISPAM, y la cuenta puede servirle en otras aplicaciones. Bloquear a
  alguien tampoco depende de que Authentik responda.
- **Solo un administrador** asigna roles y cambia contraseñas: un rol da
  permisos, así que quien tenga el módulo Usuarios podría darse los que
  quisiera.
- Todo queda en la auditoría como «Cuenta en Authentik», sin la contraseña.
- Sin `AUTHENTIK_API_TOKEN`, el usuario se guarda solo en SISPAM y se avisa.
- La cuenta de servicio y el token se crean como dice `docs/despliegue.md`
  (sección 10, Authentik).

### Matriz

En el menú **Administración → Roles y permisos**, la tabla es la matriz: tiene
una fila por rol y una columna por módulo. Al editar un rol se marcan las
acciones de cada módulo.

| Módulo (clave) | Acciones |
|---|---|
| Pacientes (`pacientes`) | ver, crear, editar, eliminar |
| Consultar paciente (`consultar_paciente`) | ver (lo básico), ver_programas (programas especiales y RIAS), ver_contacto (teléfono y correo), ver_completo (todo lo que trae Savia) |
| Orientación (`orientacion`) | usar, ver_orden |
| Sedes (`sedes`) | ver, crear, editar, eliminar |
| Usuarios (`usuarios`) | ver, crear, editar, eliminar |

Reglas:

- **Sin "ver", el módulo no sale en el menú** y su URL responde 403.
- **Los permisos de varios roles se suman.**
- **Un módulo apagado en la instalación no existe para nadie**, ni para el
  administrador: `SISPAM_MODULOS` es una lista blanca que `User::puede()`
  revisa antes que todo lo demás. En la matriz siguen visibles, marcados
  «apagado en esta instalación», y lo que se marque ahí vale cuando se
  enciendan. Ver `docs/despliegue.md`.
- **Roles que se siembran** (`RolSeeder`):
  - `ORIENTADOR`: Pacientes (ver, crear, editar), Consultar paciente (ver y
    ver todo), Orientación (usar, ver la orden médica) y Tickets (imprimir).
  - `DISPENSADOR`: Entrega, Pacientes (ver), Tickets (ver) y Turnos.
  - `CONSULTA`: solo Consultar paciente (ver lo básico, los programas
    especiales y RIAS, y el teléfono y el correo). Ver la sección 5.
- **Ojo con los roles que ya existen:** `RolSeeder` usa `firstOrCreate`, así que
  no agrega permisos nuevos a un rol ya creado. Los permisos nuevos de un rol
  que ya está en la base llegan por migración, que **solo agrega** (por
  ejemplo, `2026_10_06_110000_dar_permiso_de_imprimir_al_orientador`,
  `2026_10_13_110000_ver_todo_lo_de_savia`,
  `2026_10_16_100000_ver_programas_para_consulta` y
  `2026_10_20_100000_ver_contacto_para_consulta`).
- **Consultar paciente tiene cuatro acciones.** `ver` muestra lo básico
  (identificación, estado de la afiliación, régimen —con la categoría si es
  contributivo—, municipio de afiliación e IPS primaria);
  `ver_programas` le suma solo los programas especiales y RIAS;
  `ver_contacto`, solo el celular, el teléfono fijo y el correo según Savia;
  `ver_completo` muestra además programas especiales, discapacidad, Sisbén,
  contacto, dirección y lo demás. La migración `2026_10_13_110000_ver_todo_lo_de_savia`
  le dio `ver_completo` a todo rol que ya tenía `ver` —menos a CONSULTA—, así
  que nadie que ya consultaba perdió nada. Desde la v1.1.2,
  `2026_10_16_100000_ver_programas_para_consulta` le da `ver_programas` a
  CONSULTA, y desde la v1.1.3 `2026_10_20_100000_ver_contacto_para_consulta`
  le da `ver_contacto` (las dos solo agregan; se le pueden quitar en la
  matriz).
- **Registrar o actualizar desde Consultar paciente** exige `pacientes.crear`
  o `pacientes.editar`, y además `ver_completo`: lo que se guarda es la
  respuesta completa de Savia. El enlace «Ver ficha en SISPAM» exige
  `pacientes.ver`.

### Para desarrolladores

- **Verificar un permiso:** `auth()->user()->puede('modulo.accion')`.
- **Proteger un recurso de Filament:** agrega `use ControlaPermisos;` y
  `protected static string $modulo = '...';`. El trait traduce las acciones de
  Filament así:
  - `viewAny` y `view` → `ver`
  - `create` → `crear`
  - `delete*`, `forceDelete*` y `restore*` → `eliminar`
  - el resto → `editar`
- **Proteger una página personalizada:** define `canAccess()` y usa `puede()`.
  `ConsultarPaciente` es un ejemplo.
- **Proteger un método de Livewire:** ocultar el botón no basta, porque el
  método se puede llamar aunque el botón no esté. Revisa el permiso **dentro
  del método** (`ConsultarPaciente::guardar()` es el ejemplo), y marca con
  `#[Locked]` las propiedades públicas de las que depende esa decisión: si
  no, el navegador las puede cambiar.
- **`$user->soloPuedeConsultar()`:** si lo único que tiene en la matriz es
  Consultar paciente. A esa persona el Escritorio (`App\Filament\Pages\Dashboard`)
  no le sale en el menú, y `/admin` la lleva directo a Consultar paciente.
- **`RolConsultaTest::test_cualquier_otra_pantalla_le_responde_403`** recorre
  todas las rutas del panel. Si agregas una pantalla y olvidas su permiso,
  esa prueba falla.
- **Agregar un módulo nuevo** (por ejemplo, tickets): súmalo a
  `Rol::MODULOS`. Aparece solo en la matriz.
- **En las pruebas:** usa `User::factory()->administrador()` para un
  administrador. Para permisos concretos, crea un `Rol` y asígnale ese
  nombre en `roles`.

## 4. Usuario de prueba (orientador)

| Campo | Valor |
|---|---|
| Documento / username | `OrientadorPrueba` |
| Sede | Sede Principal |
| Administrador | No |

Pasos, con `AUTHENTIK_API_TOKEN` configurado:

1. En **Administración → Usuarios**, créalo con el documento
   `OrientadorPrueba`, una contraseña y el rol `ORIENTADOR`.
2. Inicia sesión en `/admin` en una ventana privada con ese documento y esa
   contraseña.

Sin token, en Authentik: crea el usuario `OrientadorPrueba` con su
contraseña, crea el grupo `ORIENTADOR` si no existe y agrega el usuario.

Lo esperado:

- En el menú solo aparecen **Pacientes** y **Consultar paciente**.
- Al registrar un paciente aparece el paso **Orientación** y la orden médica
  es obligatoria.

## 5. Rol CONSULTA y cuentas compartidas

Cuatro cuentas, una por sede, que usan varias personas de la sede a la vez
para **consultar pacientes en Savia**. No registran, no actualizan, no editan
y no entran a nada más.

### Las cuentas

| Sede | Documento en SISPAM = username en Authentik |
|---|---|
| PREMIUM PLAZA (PRP) | `ConsultaPRP` |
| EDIFICIO BIC (BIC) | `ConsultaBIC` |
| LA 30 (L30) | `ConsultaL30` |
| AVENTURA (AVT) | `ConsultaAVT` |

- Las crea `CuentasDeConsultaSeeder`, que se puede volver a correr: busca por
  documento y no duplica. De una cuenta que ya existe solo asegura lo que la
  hace cuenta compartida de su sede (la marca, que no sea administradora y su
  sede); el nombre, el correo y si está activa son del administrador.
- El seeder llama antes a `RolSeeder`, que crea el rol `CONSULTA` si falta
  (con solo `consultar_paciente.ver`) y no toca los demás.
- Se marcan con `users.cuenta_compartida`. En Usuarios lo ve y lo cambia
  **solo un administrador**, igual que el interruptor de administrador, y en
  el listado sale «Cuenta compartida» debajo de los roles.

### Siempre el rol CONSULTA, y solo ese

- **Venga o no el grupo desde Authentik.** El inicio de sesión copia los
  grupos de Authentik a `users.roles`; para una cuenta compartida,
  `User::booted()` deja siempre `['CONSULTA']`. Si alguien la mete en otro
  grupo allá, no gana permisos; si no está en el grupo CONSULTA, igual lo tiene.
- `User::rolesEfectivos()` lo vuelve a imponer al resolver los permisos, por
  si alguien tocó la fila directo en la base.
- **Lo que pueden hacer se ajusta en la matriz, en el rol CONSULTA**: lo que se
  le dé a ese rol lo tienen las cuatro cuentas.
- **En Usuarios, el campo Roles queda fijo en CONSULTA** y no se puede cambiar.
  Al guardar, SISPAM les asigna ese grupo en Authentik, como con cualquier rol.
  Marcar a alguien como cuenta compartida le deja solo CONSULTA.
- Antes de la v1.0.1 dependía del grupo en Authentik, y en producción las
  cuentas entraron sin rol: llegaban al Escritorio vacío. La migración
  `2026_10_14_100000_cuentas_compartidas_con_rol_consulta` arregló las que ya
  existían.

### Qué ve

- **Al iniciar sesión, lo primero y lo único es «Consultar paciente»**, aunque
  antes hubiera abierto otra dirección. El Escritorio no le sale, ni siquiera
  si la matriz le da más al rol CONSULTA, y `/admin` la devuelve a Consultar
  paciente.
- **Lo básico de Savia:** nombre, documento, fecha de nacimiento, estado de la
  afiliación con su causa, régimen, categoría (A, B o C, solo si es contributivo),
  municipio de afiliación e IPS primaria (`ConsultarPaciente::CAMPOS_BASICOS`,
  lista blanca). Municipio y categoría salen en el encabezado del resultado, sin
  bajar en la pantalla; si Savia no los manda, dice «sin dato en Savia». Lo
  demás se quita **en el servidor**: lo que está en una propiedad pública de
  Livewire viaja al navegador aunque la vista no lo pinte.
- **Los programas especiales y RIAS también** (`ver_programas`, desde la
  v1.1.2), en su sección justo debajo del encabezado. Si Savia no reporta
  ninguno, la sección dice «Savia no reporta programas ni RIAS para este
  afiliado». Si el administrador le quita la acción al rol, los programas
  dejan de salir del servidor.
- **El teléfono y el correo según Savia también** (`ver_contacto`, desde la
  v1.1.3): en el encabezado, debajo del municipio, el bloque «Contacto según
  Savia» con Celular, Teléfono fijo y Correo (`telefonoMovil`, `telefono` y
  `email`). El que Savia no mande dice «sin dato en Savia». La dirección no
  entra (sigue siendo de `ver_completo`), y nada de esto se guarda: el
  contacto del paciente en SISPAM es el que confirma el personal. Sin la
  acción, esos tres campos no salen del servidor.
- **Si el paciente ya está en SISPAM** (con la fecha), pero sin botones: ni
  «Registrar», ni «Actualizar», ni «Ver ficha».
- **Cualquier otra pantalla, recurso o archivo le responde 403**. La sala de
  espera (`/sala/{codigo}`) es pública para todos a propósito.

### Fija en su sede

Una cuenta compartida es la de una sede. `User::sedesDondePuedeTrabajar()`
le devuelve solo la suya, así que **no tiene selector** aunque un
administrador le asigne otras, y `cambiarDeSede()` la rechaza aunque el id
llegue por la petición. Si una persona la moviera, las demás quedarían de
pronto trabajando en otra sede.

**Nunca es administradora:** `User::booted()` lo garantiza al guardar.

### Qué queda en la auditoría

**Cada consulta queda con la cuenta y la sede.** En Administración →
Auditoría, debajo de la cuenta sale «ConsultaPRP · cuenta compartida», y el
filtro **«Solo cuentas compartidas»** deja solo esas cuentas.

**Desde la v1.0.1 ya no se pide nombre ni documento** a quien usa la cuenta:
se quitaron la pantalla «¿Quién está usando esta cuenta?», su middleware y el
servicio que lo guardaba en la sesión. Las líneas de hasta la v1.0.0 traen
esa persona (`auditorias.persona_nombre` y `persona_documento`) y **se siguen
viendo**: «Persona: nombre · documento» debajo de la cuenta, la búsqueda las
encuentra y su ficha tiene la sección «Persona que usaba la cuenta». Las
columnas no se borran; simplemente ya no se llenan.

### Se cierra sola a los 30 minutos sin uso

Solo las cuentas compartidas. Cuenta como uso cada petición: abrir una
pantalla o consultar.

- **En el navegador** (`resources/views/filament/cuenta-compartida/cierre-por-inactividad.blade.php`):
  se cumple el plazo y la pantalla cierra sesión sola. El último paciente
  consultado deja de estar a la vista y se cierra también la sesión de
  Authentik (`LogoutResponse`). La hora del último uso se comparte entre
  pestañas: una pestaña quieta no cierra la sesión de otra que sí se usa.
- **En el servidor** (`CerrarCuentaCompartidaInactiva`): es la garantía, por
  si el navegador no corrió el reloj (equipo suspendido). Una página que
  llega tarde cierra todo y va a Authentik. A una petición de Livewire se le
  responde 419; el navegador recarga, y esa recarga cierra.
- En la auditoría queda «Sesión cerrada por inactividad», distinto de «Cerró
  sesión».
- El plazo se cambia con `CUENTA_COMPARTIDA_MINUTOS_SIN_USO` (comentada en
  `.env.example`; sin ella son 30).

### Varias personas a la vez, en distintos equipos

**En SISPAM no hay nada que lo impida.** Las sesiones van en la tabla
`sessions`, una por navegador. `AuthenticateSession` solo cierra las demás si
cambia la contraseña, y aquí es nula. No se usa «recordarme» y nada llama a
`logoutOtherDevices()`. Cerrar sesión en un equipo invalida solo esa sesión.

**En Authentik, revisa:**

- **El stage *User Login* del flujo de autenticación:** si tu versión trae la
  opción **«Terminate other sessions»**, tiene que estar apagada. Si no,
  cada ingreso saca a los demás de la sede.
- **MFA:** si el flujo exige TOTP o WebAuthn a todos, una cuenta compartida
  necesitaría un dispositivo compartido. Decide si se exime a estas cuentas
  con una política.
- **Bloqueo por intentos fallidos** (política de reputación): varias personas
  equivocándose con la misma contraseña pueden bloquear la cuenta para toda
  la sede.
- **Vencimiento de contraseña:** cuando vence, todos en la sede necesitan la
  nueva.
- **El *invalidation flow* del proveedor de SISPAM:** para que cerrar sesión
  (a mano o por inactividad) cierre también Authentik en ese equipo, el flujo
  tiene que incluir el stage *User Logout*. Si solo cierra la sesión de la
  aplicación, el siguiente en ese equipo entra con un clic y sin contraseña.

### Crearlas en Authentik

**Con `AUTHENTIK_API_TOKEN` configurado**, SISPAM lo hace: abre cada cuenta en
Usuarios y guárdala (le crea la cuenta en Authentik si no la tiene, con el
grupo `CONSULTA`), y ponle la contraseña con **«Cambiar contraseña»**. Una
distinta por sede.

**Sin token**, a mano en Authentik:

1. **Directory → Users → Create**, una por sede: username `ConsultaPRP`
   (y `ConsultaBIC`, `ConsultaL30`, `ConsultaAVT`), nombre «Consulta PREMIUM
   PLAZA», tipo *Internal*. Luego **Set password** con la contraseña que se
   le va a dar a la sede.
2. El grupo `CONSULTA` ya no hace falta para entrar: en SISPAM lo tienen
   siempre. Si quieres que Authentik también lo muestre, agrégalas a ese grupo.
3. Si la aplicación de SISPAM en Authentik limita quién entra por grupo,
   agrega `CONSULTA` y mete ahí las cuatro cuentas.
4. Revisa la lista de arriba («En Authentik, revisa»).

### Puesta en marcha

```bash
php artisan migrate                                  # cuenta_compartida, ver_completo y el rol de las que ya existían
php artisan db:seed --class=CuentasDeConsultaSeeder  # rol CONSULTA y las 4 cuentas
```

### Probarlo

1. En una ventana privada, entra a `/admin` con `ConsultaPRP`. Llegas directo
   a Consultar paciente, sin preguntas y sin Escritorio.
2. En el menú no hay nada más, y arriba no está el selector de sede. Consulta
   una cédula: ves lo básico, el contacto según Savia (celular, teléfono fijo
   y correo), los programas especiales y RIAS, el estado en SISPAM y ningún
   botón.
3. Escribe a mano `/admin/pacientes` o `/admin`: la primera responde 403 y la
   segunda vuelve a Consultar paciente.
4. Como administrador, en **Usuarios** abre `ConsultaPRP`: el campo Roles dice
   CONSULTA y no se puede cambiar. En **Administración → Auditoría** la
   consulta del paso 2 sale con la cuenta y la sede.
5. Para probar el cierre sin esperar media hora, pon
   `CUENTA_COMPARTIDA_MINUTOS_SIN_USO=1` en el `.env`, corre
   `php artisan config:clear` y deja la pantalla quieta un minuto. Al
   terminar quita la variable.
6. Con un ORIENTADOR, todo sigue igual: ves todo lo de Savia y los botones
   Registrar, Actualizar y Ver ficha.

### Archivos

- `app/Models/User.php`: `booted()` (siempre CONSULTA, nunca administradora) y `rolesEfectivos()`.
- `app/Filament/Pages/Dashboard.php`: el Escritorio, que manda a Consultar paciente a las cuentas compartidas y a quien solo consulta.
- `app/Http/Middleware/CerrarCuentaCompartidaInactiva.php` y `resources/views/filament/cuenta-compartida/cierre-por-inactividad.blade.php`: los dos relojes de inactividad.
- `database/migrations/2026_10_13_100000_cuentas_compartidas.php`, `2026_10_13_110000_ver_todo_lo_de_savia.php` y `2026_10_14_100000_cuentas_compartidas_con_rol_consulta.php`.
- `database/seeders/CuentasDeConsultaSeeder.php`.
- `tests/Feature/CuentasCompartidas/`.

## 6. Archivos

**Nuevos**

- `app/Filament/Concerns/ControlaPermisos.php`
- `app/Filament/Resources/RolResource.php` y `RolResource/Pages/ManageRoles.php`
- `app/Models/Rol.php`, `app/Models/Soporte.php`
- `database/migrations/2026_09_30_200000_crear_soportes_y_roles.php`
- `database/migrations/2026_09_30_300000_crear_matriz_de_permisos.php`
- `database/seeders/RolSeeder.php`
- `resources/views/filament/pacientes/acciones-guardado.blade.php`
- `tests/Feature/Filament/PermisosTest.php`

**Modificados**

- `PacienteResource.php`: el asistente, el paso Orientación y `separarSoporte()`.
- `CreatePaciente.php` y `EditPaciente.php`: los botones en el último paso y el
  guardado del soporte.
- `SedeResource.php` y `UserResource.php`: la matriz de permisos. En
  `UserResource.php`, además, el interruptor de administrador y la columna de
  roles.
- `ConsultarPaciente.php`: `canAccess()`.
- `AuthentikController.php`: la copia de los grupos de Authentik a `users.roles`.
- `User.php`: `puede()`, más los campos `roles` y `es_administrador`.
- `Paciente.php`: la relación `soportes()`.
- `UserFactory.php`: el estado `administrador()`.
- `AdminUserSeeder.php`: marca a `AdminSispam` como administrador.
- `DatabaseSeeder.php`: llama a `RolSeeder`.
- Las pruebas existentes: actúan como administrador o con un rol de la matriz.

## 7. Pruebas

```bash
php artisan test
php artisan test tests/Feature/CuentasCompartidas   # rol CONSULTA y cuentas compartidas
```
