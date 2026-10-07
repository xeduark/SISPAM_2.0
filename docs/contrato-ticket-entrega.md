# Contrato ticket → entrega

El módulo de **ticket/fórmula** lo desarrolla otro compañero. `modulo/entrega` solo **consulta** tickets; no los crea ni edita.

## Interfaz

`App\Contracts\Ticket\TicketConsultaInterface`

```php
public function buscarPorNumero(string $numero): ?TicketDto;
```

Binding actual en `AppServiceProvider`: **`TicketConsultaDb`** — los tickets reales.
`TicketConsultaMock` sigue en el proyecto como doble de prueba.

### Buscar por turno

Además del `numero`, `buscarPorNumero()` acepta el **turno corto** (`0060`):
en el mostrador el paciente muestra eso, no el número largo. El turno se
busca solo **del día de hoy y en la sede de quien atiende**, porque fuera de
ahí se repite.

## DTO mínimo (`TicketDto`)

| Campo | Tipo | Uso en entrega |
|---|---|---|
| `numero` | string | Búsqueda en mostrador |
| `turno` | string\|null | Mostrar turno al paciente |
| `estado` | string | Solo se atienden `listo` o `parcial` (`listoParaEntrega()`) |
| `sedeId` | int | Validar sede del dispensador |
| `paciente` | `PacienteResumenDto` | Identidad y domicilio |
| `items` | `TicketItemDto[]` | Medicamentos a entregar |
| `altoCosto` | bool | Aviso en pantalla |

### `PacienteResumenDto`

`id` (SISPAM, nullable), `tipoDocumento`, `numeroDocumento`, `nombreCompleto`, `telefonoMovil`, `direccion`, `barrio`, `ciudad`, `indicacionesEntrega`, `contactoConfirmado`.

### `TicketItemDto`

`id` (id de línea en el ticket), `codigo`, `nombre`, `cantidad`, `unidad`.

## Cierre del ticket: puerto aparte

Cuando entrega registra una atención, el ticket se pone al día: queda
`entregado` si se llevó todo, o `parcial` si quedaron faltantes.

Eso **no toca el código del módulo de entrega**. Se hace con un puerto nuevo,
`App\Contracts\Ticket\TicketCierreInterface`, que el núcleo dispara
escuchando el evento `saved` del modelo `Entrega`
(`App\Listeners\SincronizarEstadoDelTicket`).

`TicketConsultaInterface` quedó **igual que siempre**: entrega sigue
consultando como antes y no se entera de nada de esto.

## Validación de sede

`AtenderEntrega` ahora compara `$dto->sedeId` con la sede del dispensador y
rechaza los tickets de otra sede. Antes no se comparaba: no se notaba porque
el mock devolvía siempre la sede del usuario, pero con tickets reales un
dispensador de una sede podía atender el ticket de otra.

El administrador no tiene esa restricción.

## El llamado del turno no toca este contrato

Llamar al paciente a una ventanilla (fase 6) **no cambia `estado`**: mueve un
campo aparte, `tickets.estado_sala`. Así un ticket llamado le sigue llegando a
entrega como `listo` o `parcial`, que es justo lo que esta interfaz promete.
Ver `docs/llamado-de-turnos.md`.

## Estados de ticket esperados por entrega

- `listo` — primera atención
- `parcial` — quedan faltantes/pendientes de una entrega anterior
- Otros estados → no se atiende; el mock siempre devuelve `listo`

## Pruebas

`EntregaModuloTest` fija el mock en su `setUp()` a propósito: esas pruebas son
del módulo de entrega, no de dónde salen los tickets, y así quedan
independientes del módulo de ticket.

Con el mock, cualquier número no vacío responde con 3 medicamentos de demo. Si
el número termina en `AC` (p. ej. `T-100AC`), marca `altoCosto = true`.

La integración real se prueba en `tests/Feature/Tickets/IntegracionEntregaTest.php`.
