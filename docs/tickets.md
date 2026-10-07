# Tickets

Fase 4 del núcleo. El ticket es **la visita del paciente**: nace cuando el
orientador lo registra con su orden médica y se cierra cuando se le entregan
los medicamentos.

## Puesta en marcha

```bash
php artisan migrate
php artisan db:seed --class=SedeSeeder   # agrega el código de cada sede
php artisan db:seed --class=ColaSeeder   # marca cuál cola es la de alto costo
```

Migraciones nuevas:

- `2026_10_03_100000_agregar_codigo_a_sedes_y_alto_costo_a_colas`
- `2026_10_03_110000_crear_tickets` — también agrega `soportes.ticket_id`
- `2026_10_03_120000_marcar_cola_de_alto_costo` — relleno para las sedes que
  ya tenían colas antes de que existiera el campo

## Dos identificadores con propósitos distintos

| | Ejemplo | Para qué |
|---|---|---|
| `numero` | `TK-PRP-261005-0060` | **Único en todo el sistema.** Es lo que busca el módulo de entrega |
| `turno` | `0060` | Corto, por sede y día. Es lo que se le dice al paciente y lo que sale en la pantalla de la sala |

El número lleva dentro la sede y la fecha, así que **el mismo turno `0060`
puede existir hoy en LA 30 y en EDIFICIO BIC sin chocar**. Por eso el contrato con
entrega (`buscarPorNumero($numero)`) sigue sirviendo sin cambios.

## Cómo nace

```
El orientador atiende al paciente en Orientación
        │
        ├── carga la orden médica  ──► soportes
        └── marca la prioridad
                 │
                 ▼
        GenerarTicket
                 │
                 ├── escoge la cola:  la general de la sede
                 ├── pide el turno:   GeneradorDeTurnos (por sede y día)
                 └── arma el número
                          │
                          ▼
    Ticket «generado», SIN medicamentos y SIN marca de alto costo
                          │
                          ▼
              Farmacia alista: captura los medicamentos
              y marca si es de alto costo u oncológico
```

**El ticket nace sin medicamentos a propósito**: los captura farmacia al
alistar, no el orientador. Así nadie tiene que descifrar letra de médico en el
mostrador.

La orden médica queda colgada del ticket (`soportes.ticket_id`), y **varias
órdenes de la misma visita pueden colgar del mismo ticket**.

## Estados

```
generado ──► en_alistamiento ──► listo ──► llamado ──► entregado
                                   │          │
                                   │          └──► parcial ──┐
                                   │                 ▲        │
                                   │                 └────────┘
                                   └──► vencido

cualquiera (menos entregado) ──► anulado
```

**`listo` y `parcial` son exactamente los dos que el módulo de entrega
atiende** (`TicketDto::listoParaEntrega()`).

## Prioridad

`normal` o `preferencial`. Los preferenciales **se llaman de primeras** en la
sala; el orden se resuelve al llamar, no al entregar el turno (fase 6).

El formulario **sugiere** la prioridad con los datos que trae Savia:

- 60 años o más (`Ticket::EDAD_ADULTO_MAYOR`) → adulto mayor
- Campo `discapacidad` distinto de «no» → discapacidad

Es solo una sugerencia: el orientador decide y puede cambiarla. Los motivos
son adulto mayor, gestante, discapacidad y otro.

> El umbral de 60 años está en `Ticket::EDAD_ADULTO_MAYOR`. Conviene
> confirmarlo con quien defina la política de atención preferencial.

## El alto costo lo marca farmacia

**Y no el orientador.** El orientador no conoce los medicamentos —por eso el
ticket nace sin ellos— así que tampoco está en condiciones de clasificarlos.
Pedirle esa respuesta era pedirle que adivinara, y encima nada la corregía
después: se escribía una sola vez, al crear el ticket.

Ahora la pone farmacia al alistar, con la fórmula a la vista, y sirve **solo
para identificar** el ticket:

- **No decide cola.** Todos nacen en la general de la sede.
- **No decide turno.** Para cuando se marca, el paciente ya tiene su número.
- Sale como etiqueta en el listado y la ficha de Tickets, y le llega al módulo
  de entrega por el DTO. De hecho entrega mejora: antes recibía la suposición
  del orientador, ahora recibe lo que farmacia verificó.
- Queda en la auditoría: `alto_costo` está en `Ticket::CAMPOS_AUDITADOS`, así
  que se sabe quién la marcó y cuándo.

Las colas «Alto costo y oncológicos» quedaron **desactivadas** en todas las
sedes (`2026_10_06_130000_alto_costo_lo_marca_farmacia`): si nada enruta hacia
ellas, estarían vacías para siempre y solo confundirían. Se desactivan, no se
borran, así que se reactivan desde la pantalla si algún día se vuelve a separar
la fila.

`colas.atiende_alto_costo` sigue en la tabla, pero ya no enruta nada: solo dice
cuál era la cola de alto costo de cada sede.

> **Pendiente conocido:** un ticket ya `listo` no se puede volver a alistar
> (`sePuedeAlistar()` solo admite `generado` y `en_alistamiento`), así que
> corregir la marca —o los medicamentos— exige devolverlo a `en_alistamiento`,
> y la pantalla todavía no ofrece cómo.

## Si la sede no está configurada

Si la sede **no tiene colas activas**, el paciente y su orden médica **igual
quedan guardados** y se avisa con un mensaje rojo. Perder la orientación —la
consulta a Savia, el contacto confirmado y la orden cargada— por un problema
de configuración sería mucho peor que quedarse sin ticket.

El soporte queda con `ticket_id` nulo, y la pantalla de **Orientación** ofrece
completarlo después sin volver a tomar las fotos. Ver `docs/orientacion.md`.

## Una sola pantalla abre visitas

**Orientación** (`/admin/orientacion`), con `Services\Orientacion\RegistrarVisita`.

El asistente de Pacientes tenía un paso que también generaba tickets; se
retiró, junto con el trait `GeneraTicketDeLaVisita`. Un solo camino significa
una sola regla para los duplicados: en vez de una idempotencia silenciosa por
la ruta del archivo, la pantalla avisa cuando el paciente ya tiene una visita
viva y deja decidir. El porqué está en `docs/orientacion.md`.

## La pantalla

**Tickets** (`/admin/tickets`), con tres pestañas: **Por alistar**, **Listos**
y **Todos**, y un aviso en el menú con cuántos están esperando hoy.

Como todo el módulo, **está filtrada por sede** (`FiltraPorSede`). El
administrador ve todas.

| Acción | Quién | Qué hace |
|---|---|---|
| **Alistar** | `tickets.alistar` | Captura los medicamentos y deja el ticket `listo`. Volver a alistar **reemplaza** lo capturado antes |
| **Anular** | `tickets.anular` | Cierra el ticket con un motivo obligatorio |
| **Ver** | `tickets.ver` | Ficha completa: paciente, medicamentos y seguimiento |

**El ticket no se crea ni se edita desde esta pantalla**: nace con el paciente.

## Conexión con el módulo de entrega

Hecha. `TicketConsultaDb` reemplazó al mock, así que **Atender entrega lee
estos tickets**. Además de por número, se puede buscar por el turno corto del
día en la sede de quien atiende.

Cuando entrega registra la atención, el ticket queda `entregado` o `parcial`
según si quedaron faltantes. Eso va por un puerto aparte
(`TicketCierreInterface`) disparado desde un listener del núcleo, sin tocar el
código del módulo de entrega. Ver `docs/contrato-ticket-entrega.md`.

## El turno en la sala

El ticket lleva **dos ciclos**: `estado` es el de la fórmula y `estado_sala` el
del turno (`en_espera` → `llamado` → `ausente` / `atendido`). Están separados
porque entrega solo atiende `listo` y `parcial`: si llamar moviera `estado`, el
paciente que acaban de llamar sería el único que no se podría atender.

Llamar, volver a llamar y marcar ausentes se hace en **Llamar turnos**, y lo
que ve el paciente es la pantalla pública de la sala. Detalle:
`docs/llamado-de-turnos.md`.

## El cierre del día

`php artisan tickets:cerrar-dia` marca como `vencido` lo que nadie alcanzó a
atender en días pasados: `generado`, `en_alistamiento` y `listo`. **`parcial`
no vence** —ese paciente sí fue atendido y le quedaron faltantes que vuelve a
reclamar—. Detalle: `docs/cierre-del-dia.md`.

## Pruebas

```bash
php artisan test --filter=TicketTest
php artisan test --filter=CierreDelDiaTest
```

El llamado del turno se prueba aparte, en `LlamadoDeTurnosTest`.

23 pruebas: cómo nace, el número único entre sedes con el mismo turno, la
elección de cola por alto costo, la sede sin colas, el alistamiento y que
volver a alistar no duplique, la anulación, el filtrado por sede, las
pestañas, los permisos, la auditoría y el flujo completo desde el formulario
de pacientes.
