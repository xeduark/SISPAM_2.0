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

## Acceso local
- URL: http://localhost:8000/admin
- Usuario administrador sembrado: documento `AdminSispam` (debe existir con ese username en Authentik; la contraseña se gestiona allá)

## Base de datos
- Nombre: sispam_2
- Motor: MySQL (XAMPP)
