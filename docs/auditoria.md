# Auditoría

Rastro de quién hizo qué en SISPAM, **en base de datos y consultable desde el
panel**, no solo en los archivos de log.

Corresponde a la fase 1 del núcleo.

## Puesta en marcha

```bash
php artisan migrate
php artisan db:seed --class=SedeSeeder   # agrega las sedes reales
```

La migración nueva es `2026_10_02_100000_crear_auditorias`.

## La regla que lo gobierna todo

**Los datos de salud nunca entran al rastro.** Para que eso no dependa de que
alguien se acuerde, cada modelo declara una **lista blanca**:

```php
class Paciente extends Model
{
    use Auditable;

    public const CAMPOS_AUDITADOS = ['estado_afiliacion', 'regimen', ...];
    public const ETIQUETA_AUDITORIA = 'paciente';
}
```

**Lo que no esté en `CAMPOS_AUDITADOS` no se registra.** Si mañana alguien
agrega un campo clínico al modelo, no entra por olvido: hay que declararlo a
propósito. Es el mismo principio de `Paciente::CAMPOS_SAVIA` con el contacto:
la protección está en la estructura, no en un `if`.

Por eso se descartó `spatie/laravel-activitylog`: usa lista negra, y un campo
nuevo quedaría registrado solo.

## Qué se audita de cada modelo

| Modelo | Campos | Qué NO entra |
|---|---|---|
| `Paciente` | estado de afiliación, régimen, tipo de afiliado, IPS primaria, teléfono, dirección, ciudad, confirmación de contacto | Nombres, fecha de nacimiento, Sisbén, programas, diagnóstico |
| `Soporte` (orden médica) | **ninguno** | La ruta del archivo y la marca de alto costo u oncológico |
| `User` | nombre, apellido, documento, correo, sede, activo, administrador, roles | — |
| `Rol` | nombre y permisos | — |
| `Sede` | nombre, dirección, teléfono, activa | — |

Del paciente, la descripción usa **solo su documento**: «Actualizó el paciente
CC 1000873458». Nunca su nombre.

## Qué acciones quedan registradas

| Acción | Cuándo |
|---|---|
| `ingreso` / `cerro_sesion` | Eventos `Login` y `Logout` de Laravel, escuchados en `AppServiceProvider`. Cubren cualquier camino de autenticación, no solo Authentik. |
| `creo` / `actualizo` / `elimino` | Automático, desde el trait `Auditable`. |
| `consulto_savia` | Al consultar un documento, desde el formulario de pacientes y desde «Consultar paciente». Guarda el documento consultado y si hubo resultado, nunca los datos del afiliado. |
| `descargo_orden` | Reservado para la fase 2 (ruta protegida de la orden médica). |
| `cerro_dia` | El cierre del día venció tickets. **Una línea por sede y por corrida**, no una por ticket: el `update` masivo no dispara el trait a propósito. A nombre de «Sistema», porque lo corre una tarea programada. Ver `docs/cierre-del-dia.md`. |

## Dónde se ve

**Administración → Auditoría** (`/admin/auditorias`). Módulo `auditoria` de la
matriz de permisos, con una sola acción: `ver`.

Filtros por acción, sede, tipo de entidad y rango de fechas. Búsqueda por
usuario, documento y detalle.

**Nadie crea, edita ni borra auditorías desde la pantalla**, ni siquiera el
administrador: `AuditoriaResource` devuelve `false` en `canCreate`, `canEdit`,
`canDelete` y `canDeleteAny`. Por eso la tabla tampoco lleva `updated_at`.

## Una arista de Eloquent que conviene conocer

El «antes» sale de `getOriginal()`, que lee lo que el modelo **tiene cargado en
memoria**. Si un registro se crea sin pasar un campo que tiene valor por
defecto en la base de datos y se actualiza en la misma petición, ese campo
nunca se cargó y el «antes» sale vacío: «— → No» en vez de «Sí → No».

No pasa en la práctica, porque los formularios siempre mandan todos los campos
y al editar el modelo viene de la base de datos. Pero si lo ves en una prueba,
es esto.

## La auditoría sobrevive al usuario

`usuario_nombre` y `usuario_documento` quedan **copiados** en cada fila. Si el
usuario se elimina, `usuario_id` queda en null pero la línea se sigue leyendo.

## Para desarrolladores

**Auditar un modelo nuevo:**

```php
use App\Models\Concerns\Auditable;

class Ticket extends Model
{
    use Auditable;

    public const CAMPOS_AUDITADOS = ['estado', 'sede_id'];
    public const ETIQUETA_AUDITORIA = 'ticket';

    public function descripcionParaAuditoria(): string
    {
        return "el ticket {$this->numero}";
    }
}
```

**Registrar una acción que no es un cambio de modelo:**

```php
Auditoria::registrar(
    accion: Auditoria::ACCION_DESCARGO_ORDEN,
    descripcion: "Abrió la orden médica del paciente {$paciente->documento_completo}",
    entidadTipo: 'orden_medica',
    entidadId: $soporte->id,
);
```

Si el registro falla, **no interrumpe la operación del usuario**: se reporta la
excepción y el flujo sigue. Una auditoría caída no puede dejar sin atender a un
paciente.

La sede sale sola de la del usuario. Cuando el hecho pasa en otra sede —o no
hay usuario, como en una tarea programada— se pasa aparte:

```php
Auditoria::registrar(
    accion: Auditoria::ACCION_CERRO_DIA,
    descripcion: "Cerró el día hasta {$hasta} en {$sede->nombre}: …",
    entidadTipo: 'ticket',
    sedeId: $sede->id,
);
```

## Pendiente

- **Purga por retención.** Falta decidir cuánto tiempo se conservan las líneas
  (propuesta: 2 años) y escribir el comando. Conviene confirmarlo con quien
  lleve el tema normativo: auditoría de acceso no es lo mismo que historia
  clínica.

## Pruebas

```bash
php artisan test --filter=AuditoriaTest
```

14 pruebas: sesiones, altas, cambios y bajas, la lista blanca (que lo no
declarado **no** se registre), que no entren datos de salud, la consulta a
Savia, la pantalla, los filtros, los permisos y que la auditoría siga legible
tras eliminar al usuario.
