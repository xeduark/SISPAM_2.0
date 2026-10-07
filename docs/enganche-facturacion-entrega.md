# Enganche de facturación desde entrega

El módulo de entrega **no factura**. Solo deja datos listos para un módulo futuro.

## Qué expone entrega

Tabla `entregas`:

| Campo | Uso para facturación |
|---|---|
| `id` | Identificador estable de la atención (`entrega_id`) |
| `ticket_numero` | Cruce con el módulo de ticket |
| `paciente_id` | Paciente en SISPAM |
| `sede_id` | Sede que dispensó |
| `usuario_id` | Quién atendió |
| `tipo` | `presencial` \| `domicilio` |
| `estado` | Preferible facturar cuando esté `completada` (o política del negocio) |
| `facturacion_estado` | `no_aplica` \| `pendiente` \| `marcada` |
| `factura_referencia` | Código/número que escriba el módulo de facturación |
| `created_at` | Fecha de la atención |

Detalle en `entrega_items` (por medicamento): código, nombre, cantidades solicitada/entregada/pendiente, resultado, motivo.

## Identificador recomendado

Usar **`entregas.id`** como llave foránea desde facturación. Opcionalmente guardar también `ticket_numero` para auditoría.

## Qué depende del módulo externo

- Numeración y reglas de factura.
- Momento en que se factura (al completar, por ítem, por EPS, etc.).
- Marcar `facturacion_estado = marcada` y llenar `factura_referencia`.

Hasta entonces, cada entrega nueva queda en `facturacion_estado = pendiente`.
