# Cierre del día

Lo que nadie alcanzó a atender queda **vencido**. Es el pendiente que arrastraban
`docs/tickets.md`, `docs/colas-y-turnos.md` y `docs/llamado-de-turnos.md`.

## El problema que resuelve

Sin cierre, el ticket que el paciente no reclamó el martes sigue «listo» el
viernes. La pantalla de la sala no lo muestra —solo publica lo de hoy— pero
**Tickets → Por alistar y Tickets → Listos sí**, porque esas pestañas filtran
por estado y no por fecha. Semana tras semana la lista se llena de pacientes
que ya se fueron, y nadie tiene forma de sacarlos de ahí.

## Cómo se corre

```bash
php artisan tickets:cerrar-dia                       # cierra todo lo anterior a hoy
php artisan tickets:cerrar-dia --simular             # dice qué vencería, sin tocar nada
php artisan tickets:cerrar-dia --sede=L30            # solo una sede
php artisan tickets:cerrar-dia --fecha=2026-10-04    # hasta ese día inclusive
```

Está programado en `routes/console.php` a las **2:00 a.m.**, cuando ya no hay
nadie en la sala. Eso necesita que algo ejecute `php artisan schedule:run` cada
minuto: cron en Linux, Programador de tareas en Windows. **Si eso no está
montado, correrlo a mano hace exactamente lo mismo** — no hay ninguna lógica en
el programador que no esté en el comando.

## Qué vence y qué no

| Estado | ¿Vence? | Por qué |
|---|---|---|
| `generado` | Sí | Farmacia no alcanzó a tomarlo |
| `en_alistamiento` | Sí | Lo tomó y no terminó |
| `listo` | Sí | Quedó listo y el paciente no volvió |
| **`parcial`** | **No** | **Ese paciente sí fue atendido** |
| `entregado` / `anulado` / `vencido` | No | Ya están cerrados |

**El parcial es el caso que hay que entender.** Ese paciente se llevó parte de
su fórmula y le quedaron faltantes, que son justamente los que vuelve a
reclamar otro día. `TicketConsultaDb::buscarPorNumero()` encuentra el ticket
por su número sin importar la fecha, así que el flujo de pendientes del módulo
de entrega **depende de que ese ticket siga vivo**. Vencerlo le quitaría al
paciente un medicamento que ya tiene ganado.

Si algún día hay que ponerle un plazo a los pendientes, es una regla aparte
—con su propio estado y su propio aviso al paciente—, no un efecto colateral
del cierre del día.

## Por qué cierra «hasta ayer» y no «hoy a las 11 p.m.»

Cerrar hacia atrás es **idempotente**: se puede correr a cualquier hora, dos
veces seguidas o tres días después de un servidor apagado, y el resultado es el
mismo. Una tarea atada a las 11 p.m. que no corrió deja ese día abierto para
siempre y nadie se entera.

Y como nunca toca la fecha en curso, **no hay forma de que venza un ticket que
alguien está a punto de atender**. Por eso el comando rechaza `--fecha` con el
día de hoy: no es una validación de formato, es la regla.

## El rastro

Una sola línea en `auditorias` **por sede y por corrida**, con la acción
«Cerró el día»:

> Cerró el día hasta 2026-10-04 en La 30: 7 tickets vencidos
>
> | Campo | Antes → Ahora |
> |---|---|
> | Generado | 2 tickets → vencidos |
> | Listo para entrega | 5 tickets → vencidos |

No hay una línea por ticket. El cambio se hace con un `update` masivo, que **no
dispara los eventos del modelo**, y es a propósito: cada ticket ya cuenta su
propia historia con `estado = vencido` y `cerrado_en`. Doscientas líneas
iguales llenarían la auditoría de ruido sin agregar un dato. Es el mismo
criterio que con los llamados (`docs/llamado-de-turnos.md`).

Como lo corre una tarea programada sin usuario, la línea queda a nombre de
«Sistema». La sede sí queda: `Auditoria::registrar()` recibe un `sedeId`
aparte, porque **la sede del hecho no siempre es la del usuario**.

## `estado_sala` no se toca

El ticket vencido conserva su estado en la sala: el que nadie llamó queda
`en_espera`, el que se llamó y no apareció queda `ausente`. Así se puede
distinguir «nunca lo llamamos» de «lo llamamos y no vino», que son dos
problemas distintos.

No hace falta limpiarlo: todas las consultas de la sala piden `estado` dentro
de `ESTADOS_ENTREGABLES` **y** la fecha de hoy, así que un ticket vencido sale
de la espera por los dos lados.

## Pendiente

- **Avisarle al paciente** que su fórmula venció. Hoy se entera cuando vuelve.
- **Un plazo para los pendientes** de las entregas parciales, si el negocio lo
  pide. Hoy no caducan nunca.

## Pruebas

```bash
php artisan test --filter=CierreDelDiaTest
```

20 pruebas: qué vence y qué no, que el día en curso no se toca, que el parcial
sobrevive, que lo ya cerrado se queda como estaba, que correrlo dos veces no
hace daño ni audita de más, el filtro por sede, que deja **una** línea de
auditoría y no una por ticket, que el vencido sale de la espera, y el comando
con sus opciones.
