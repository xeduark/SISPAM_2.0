# Contrato ticket → entrega

El módulo de **ticket/fórmula** lo desarrolla otro compañero. `modulo/entrega` solo **consulta** tickets; no los crea ni edita.

## Interfaz

`App\Contracts\Ticket\TicketConsultaInterface`

```php
public function buscarPorNumero(string $numero): ?TicketDto;
```

Binding actual en `AppServiceProvider`: `TicketConsultaMock`. Cuando el módulo de ticket esté listo, registrar su implementación en lugar del mock.

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

## Estados de ticket esperados por entrega

- `listo` — primera atención
- `parcial` — quedan faltantes/pendientes de una entrega anterior
- Otros estados → no se atiende; el mock siempre devuelve `listo`

## Pruebas locales (mock)

Cualquier número no vacío responde con 3 medicamentos de demo. Si el número termina en `AC` (p. ej. `T-100AC`), marca `altoCosto = true`.
