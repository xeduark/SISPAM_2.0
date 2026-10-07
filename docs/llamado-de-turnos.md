# Llamado de turnos y pantalla de sala

Fase 6 del núcleo. Cierra el recorrido del paciente: el orientador lo registra
y le da un turno (fase 4), farmacia lo alista, **aquí se le llama a una
ventanilla** y entrega registra la atención (módulo de entrega).

## Puesta en marcha

```bash
php artisan migrate
```

La migración es `2026_10_04_100000_crear_llamados`: agrega
`tickets.estado_sala` y la tabla `llamados`.

En una base que ya existe, el rol `DISPENSADOR` **no cambia solo** (el seeder
usa `firstOrCreate` para no pisar lo que el administrador haya ajustado): hay
que marcarle el módulo **Llamado de turnos** en Administración → Roles y
permisos. En una base nueva, `RolSeeder` ya lo deja con `ver`, `llamar` y
`ausente`.

## Lo primero: llamar no es un estado de la fórmula

El ticket tiene **dos ciclos** y conviene no confundirlos:

| Campo | De qué habla | Quién lo mueve |
|---|---|---|
| `estado` | La fórmula: `generado` → `listo` → `entregado` / `parcial` / `anulado` | Farmacia al alistar, entrega al atender |
| `estado_sala` | El turno: `en_espera` → `llamado` → `ausente` / `atendido` | El llamado |

**Por qué están separados:** el módulo de entrega solo atiende `listo` y
`parcial` (`TicketDto::listoParaEntrega()`). Si llamar al paciente moviera
`estado` a «llamado», el paciente que acabamos de llamar sería justo el que no
se puede atender. Por eso llamar toca `estado_sala` y nada más, y el contrato
con entrega sigue **igual que siempre**.

Son dos ejes, no una sola fila: un ticket puede estar `parcial` (le quedan
faltantes) y a la vez `atendido` en la sala (hoy ya pasó por la ventanilla).

## A quién le toca

`App\Services\Turnos\LlamadorDeTurnos`:

```php
$ticket = app(LlamadorDeTurnos::class)->siguiente($ventanilla, auth()->user(), $colaIds);
```

Tres reglas:

1. **Los preferenciales de primeras**, y dentro de cada prioridad el que llegó
   primero (`created_at`). La prioridad **se resuelve al llamar**, no al
   entregar el turno: si el orientador se equivocó al marcarla, se corrige y el
   orden se acomoda en la siguiente llamada.
2. **Solo se llama lo que farmacia dejó listo** (`listo` o `parcial`), de hoy y
   de la sede de la ventanilla. Un ticket sin alistar no sirve en el mostrador.
3. **Dos ventanillas no se llevan el mismo turno.** El candidato se toma con
   `lockForUpdate` dentro de una transacción, igual que el consecutivo en
   `GeneradorDeTurnos`: la segunda ventanilla espera, vuelve a leer y ya lo ve
   llamado, así que sigue al siguiente.

Prioridad estricta quiere decir que una fila larga de preferenciales retrasa a
los normales. Se asume a propósito: es lo que pide la norma. Si una mañana se
desbalancea, quien atiende puede llamar a uno puntual de la lista.

### El que no se presenta

Se marca «No se presentó» (`estado_sala = ausente`) y **sale de la espera**:
«llamar al siguiente» no lo vuelve a tomar solo. Pero el turno no se pierde —
queda en su propia lista y, si aparece, se vuelve a llamar con un botón. Cada
llamado suma un `intento`, así que en el mostrador se puede decir cuántas veces
se llamó y a qué hora.

Marcar ausente es un permiso aparte (`turnos.ausente`): saca a alguien de la
espera.

## Las dos pantallas

| Pantalla | Quién la ve | Qué muestra |
|---|---|---|
| **Llamar turnos** (`/admin/llamar-turnos`) | Quien atiende, con sesión y permiso | Turno en la mano, paciente, la espera en orden, los ausentes y los últimos llamados |
| **Sala** (`/sala/{codigo}`) | El televisor de la sala | Turno, ventanilla, hora y si es preferencial. **Nada más** |

### La pantalla de la ventanilla

Se escoge la ventanilla una vez (queda guardada en la sesión) y, si hace falta,
de cuáles colas se quiere llamar; sin marcar ninguna se llama de todas. Se
refresca sola cada 15 segundos, así que la espera está al día sin recargar.

El aviso del menú dice cuántos pacientes esperan en la sede.

### La pantalla de la sala es pública a propósito

Un televisor no inicia sesión. Por eso `/sala/{codigo}` no pide autenticación —
y por eso **solo publica turno y ventanilla**: ni el nombre del paciente, ni su
documento, ni el número del ticket, ni los medicamentos. Es lo mismo que se
grita en voz alta en la sala.

Qué sale en esa pantalla se decide en un solo lugar,
`SalaController::turnosDeLaSede()`. **Agregar un campo ahí es publicarlo en la
sala de espera**, así que es el sitio para pensarlo dos veces. Hay una prueba
que falla si el nombre o el documento del paciente se asoman.

La sede va en la URL por su `codigo` (`/sala/LA30`), y una sede inactiva
responde 404. La pantalla pide los turnos cada 5 segundos a
`/sala/{codigo}/turnos`; si la red se cae se queda con lo último que alcanzó a
mostrar. El aviso sonoro se activa con un botón, porque ningún navegador deja
sonar nada antes de que alguien toque la pantalla.

## El rastro

Cada llamado es una fila en `llamados`: ticket, sede, cola, ventanilla, quién
llamó e intento. **No se duplica en `auditorias`**, porque esta tabla ya es el
registro; meterlo en las dos llenaría la auditoría de ruido sin agregar un dato.
El último llamado se copia al ticket (`ventanilla_id`, `llamado_por`,
`llamado_en`) para no tener que consultar el historial solo para pintar la
pantalla.

En la ficha del ticket (Tickets → ver) sale el estado en la sala, desde cuál
ventanilla se llamó, a qué hora y cuántos llamados hubo.

## Permisos

Módulo **`turnos`** de la matriz:

| Acción | Para qué |
|---|---|
| `ver` | Entrar a la pantalla de llamado |
| `llamar` | Llamar el siguiente, llamar a uno puntual y volver a llamar |
| `ausente` | Marcar que el paciente no se presentó |

La pantalla pública de la sala no pide permisos: no tiene nada que proteger.

## Pendiente

- **Cierre del día:** marcar como `vencido` lo que nadie atendió y dejar la
  sala en blanco a la mañana siguiente. Hoy la pantalla solo muestra lo de hoy,
  así que el ruido no se ve, pero los tickets quedan abiertos.
- **Sonido con voz** («turno A-023, ventanilla 2»). Hoy es un pito.

## Pruebas

```bash
php artisan test --filter=LlamadoDeTurnosTest
```

25 pruebas: el preferencial de primeras, el orden de llegada dentro de cada
prioridad, que no se llame lo que no está alistado ni lo de otra sede o otro
día, el filtro por colas, que dos ventanillas no se lleven el mismo turno, el
registro del llamado y los intentos, que **llamar no saque al paciente de
entrega**, que la atención lo retire de la sala, el ausente que vuelve a ser
llamado, los permisos de la pantalla y qué publica —y qué no— la pantalla de la
sala.
