# Asistente de pacientes, orientación y matriz de permisos

Este documento resume los cambios que llegan en este commit. Se escribió
para el equipo de desarrollo de SISPAM y para quien configure Authentik.

## Resumen

| Cambio | Qué resuelve |
|---|---|
| Formulario de pacientes por pasos (wizard) | El formulario largo de Savia queda en 5 pasos que se validan uno a uno. |
| Paso **Orientación** | El orientador carga la orden médica (foto o archivo) e indica si es de alto costo u oncológico. |
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
| 5. Orientación | Pregunta de alto costo u oncológico y la orden médica. Solo aparece con permiso `orientacion.usar`. |

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

## 2. Orientación: orden médica y alto costo

Este paso solo lo ve quien tenga el permiso `orientacion.usar`, además de los
administradores.

| Campo | Regla |
|---|---|
| ¿Paciente o medicamento de alto costo / oncológico? | Sí / No, obligatorio. |
| Orden médica | JPG, PNG, WEBP o PDF de máximo 10 MB. Es obligatoria al **registrar** un paciente y opcional al **editar**. |

- **Cámara o archivo:** en el celular, el mismo botón ofrece la cámara o los
  archivos del dispositivo. No se usa el atributo `capture`, porque obligaría
  a usar solo la cámara.
- **Almacenamiento:** los archivos van al disco privado `local`, en
  `storage/app/private/soportes/ordenes-medicas`. Nunca se guardan en `public`,
  porque son datos de salud.
- **Registro:** cada carga crea una fila en `soportes` con `paciente_id`,
  `orden_medica`, `alto_costo_oncologico`, `cargado_por` y fechas. Esto lo
  hace `PacienteResource::separarSoporte()`.
- **Pacientes que ya existían:** si el documento ya estaba registrado, el
  flujo de registro actualiza ese paciente y le suma el soporte.

**Pendiente**, se hará junto con la vista del ticket:

- La generación del ticket.
- Una ruta protegida para ver o descargar la orden desde la ficha del paciente.

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

### Matriz

En el menú **Administración → Roles y permisos**, la tabla es la matriz: tiene
una fila por rol y una columna por módulo. Al editar un rol se marcan las
acciones de cada módulo.

| Módulo (clave) | Acciones |
|---|---|
| Pacientes (`pacientes`) | ver, crear, editar, eliminar |
| Consultar paciente (`consultar_paciente`) | ver |
| Orientación (`orientacion`) | usar |
| Sedes (`sedes`) | ver, crear, editar, eliminar |
| Usuarios (`usuarios`) | ver, crear, editar, eliminar |

Reglas:

- **Sin "ver", el módulo no sale en el menú** y su URL responde 403.
- **Los permisos de varios roles se suman.**
- **Rol que se siembra:** `ORIENTADOR`, con Pacientes (ver, crear, editar),
  Consultar paciente (ver) y Orientación (usar).

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

Pasos en Authentik:

1. Crea el usuario con username `OrientadorPrueba` y asígnale una contraseña.
2. Crea el grupo `ORIENTADOR`, si no existe, y agrega el usuario.
3. Inicia sesión en `/admin` en una ventana privada.

Lo esperado:

- En el menú solo aparecen **Pacientes** y **Consultar paciente**.
- Al registrar un paciente aparece el paso **Orientación** y la orden médica
  es obligatoria.

## 5. Archivos

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

## 6. Pruebas

```bash
php artisan test
```

Pasan las 100 pruebas.
