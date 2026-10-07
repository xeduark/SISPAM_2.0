# Módulo Turnero — vista de sala

Estado: **especificación**. **El módulo de tickets no se toca** (decisión 2026-10-05).

## Lo que ya existe
| Pieza | Dónde | Doc |
|---|---|---|
| Pantalla de sala pública `/sala/{codigo}` (ej. `/sala/PRIN`) | `SalaController`, `resources/views/sala/turnos.blade.php` | `llamado-de-turnos.md` |
| Refresco cada 5 s, pito al cambiar turno, preferencial en amarillo, últimos 5 llamados | misma vista | |
| Llamar turnos, ausentes, preferenciales primero | `/admin/llamar-turnos`, `LlamadorDeTurnos` | `llamado-de-turnos.md` |

Códigos de sede: `PRIN` Sede Principal, `LA30`, `PPLZ` Premium Plaza, `BIC`, `AVEN` Aventura.

## Menú
Grupo **Turnero**: «Llamar turnos» y «Pantalla de sala» (abre en otra pestaña `/sala/{codigo}` de la
sede del usuario; visible con `turnos.ver`). El enlace está en `AdminPanelProvider::navigationItems()`.

## Regla de la sala
Pública a propósito (un televisor no inicia sesión): solo turno, ventanilla, hora y si es
preferencial. Lo que se agrega en `SalaController::turnosDeLaSede()` se publica a toda la sala:
nunca nombre, documento ni medicamentos.

## Logo institucional
`https://res.cloudinary.com/dbhbuhjum/image/upload/v1775485317/descarga_moi2yv.png`
(se usa en la sala y en la orden de entrega).

## Responsable: apertura y cierre
Los abre y cierra **farmacia**: llama el turno (`/admin/llamar-turnos`) y cierra al entregar
(módulo entrega → ticket `entregado`/`parcial`). Ya funciona así; el acceso se da desde la
matriz de permisos, módulos `turnos` (ver, llamar, ausente) y `entregas`.
Rol sugerido: `farmacia` con esos dos módulos.

## Impresión térmica (JALTECH POS)
El tiquete de turno (`public/img/Tiquete 0060.pdf`) es lo que se le entrega al paciente al llegar.
Sale de tickets, que no se toca ahora → **fuera de alcance** hasta que se decida.
