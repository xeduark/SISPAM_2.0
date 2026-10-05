# Colas, ventanillas y turnos

Fase 3 del núcleo. Es el cimiento de los tickets: un ticket nace en una cola y
de ahí saca su turno.

## Puesta en marcha

```bash
php artisan migrate
php artisan db:seed --class=ColaSeeder
```

La migración nueva es `2026_10_02_200000_crear_colas_y_ventanillas`.
`ColaSeeder` usa `firstOrCreate`: se puede volver a correr sin duplicar ni
pisar lo que el administrador haya ajustado.

Deja en **cada sede**:

| Cola | Prefijo | Para qué |
|---|---|---|
| Dispensación general | `A` | Fórmulas corrientes |
| Alto costo y oncológicos | `B` | **Desactivada.** El alto costo dejó de separar filas: hoy es una etiqueta que pone farmacia al alistar |

Más dos ventanillas por sede: «Ventanilla 1» y «Ventanilla 2».

## Todo separado por sede

Es la regla del módulo. El trait `App\Filament\Concerns\FiltraPorSede` la
aplica en dos sitios a la vez:

- **El listado** solo trae lo de `auth()->user()->sede_id`.
- **El campo Sede del formulario** viene fijo en la del usuario y deshabilitado.

**El administrador es la excepción:** ve y escoge todas las sedes, y solo a él
le aparece el filtro por sede en el listado.

Un usuario pertenece a **una sola** sede (`users.sede_id`). Quien trabaje en
dos necesita dos usuarios. Si eso se vuelve común, toca una tabla pivote y una
«sede activa» en sesión; por ahora no.

Este trait es el patrón que reutilizan tickets y el llamado de turnos.

## El turno

El paciente ve un turno corto: **`0060`**. Cuatro cifras y nada más. Pasado el
9999 sigue creciendo (`10000`), no se corta: mejor un turno de cinco cifras que
dos pacientes con el mismo número.

El consecutivo es **por sede y por día**: cada mañana vuelve a 1, y todas las
colas de una sede comparten la numeración.

### Por qué numera la sede y no la cola

Antes el turno llevaba el prefijo (`A-0060`) y que cada cola contara aparte
estaba bien: `A-0060` y `B-0060` eran distintos. **Sin prefijo dejan de serlo.**
Dos colas de la misma sede sacarían las dos un `0060` el mismo día, y
`TicketConsultaDb::porTurnoDeHoy()` busca el turno dentro de la sede: se
encontraría con dos tickets y no sabría cuál es.

La cola ya no numera, y desde que el alto costo dejó de enrutar, todos los
tickets nacen en la cola general de su sede.
Su `prefijo` quedó como **etiqueta corta** para distinguirla en pantalla
(«Dispensación general (A)»), no como parte del turno.

### Por qué hay una tabla de contadores

Varios orientadores piden turno al mismo tiempo. Un `max(consecutivo) + 1` dos
personas lo pueden leer a la vez y entregar el mismo número. Por eso:

```
contadores_turno:  sede_id + fecha  →  ultimo
                   unique(sede_id, fecha)
```

`GeneradorDeTurnos` hace tres cosas dentro de una transacción:

1. `insertOrIgnore` deja la fila del día lista sin reventar si otro proceso se
   adelantó: el índice único decide quién gana.
2. `lockForUpdate` bloquea esa fila mientras dure la transacción.
3. `increment` y devuelve.

```php
$turno = app(GeneradorDeTurnos::class)->siguiente($sede);   // «0060»
```

También hay `entregadosHoy($sede)`, que mira el contador **sin consumir** un
turno.

## Reglas de negocio

- **El prefijo no se repite dentro de una sede** (`unique(sede_id, prefijo)`).
  En sedes distintas sí: La 30 y BIC pueden tener las dos su cola `A`.
- **El prefijo se guarda en mayúsculas**, se escriba como se escriba.
- **Una cola que ya entregó turnos no se elimina**, se desactiva. Borrarla se
  llevaría el historial del día por delante. Lo dicen **sus tickets**, no el
  contador: ese es de la sede, así que una cola recién creada donde ya se
  atendió hoy parecería usada sin haber entregado nada.
- **Una cola inactiva no entrega turnos nuevos**, pero conserva los que dio.
- **Una sede en uso no se elimina**: con usuarios, colas o ventanillas,
  `SedeResource` lo bloquea con un aviso en vez de dejar que reviente la FK.
- **Una ventanilla no se amarra a una cola.** Atiende cualquiera de las de su
  sede y quien llama elige de cuál, así no queda ociosa cuando su cola está
  vacía. Quien atiende escoge la suya en **Llamar turnos**.

## Permisos

Módulo **`colas`** de la matriz, con ver, crear, editar y eliminar. Cubre las
dos pantallas: **Administración → Colas** y **Administración → Ventanillas**.

Los cambios en colas y ventanillas quedan en la auditoría.

## Qué pasa con los turnos que nadie atendió

Vencen al cerrar el día (`php artisan tickets:cerrar-dia`). El consecutivo no
hay que limpiarlo: `contadores_turno` lleva la fecha en la llave, así que cada
mañana arranca en uno solo. Ver `docs/cierre-del-dia.md`.

Las **prioridades** ya están: los preferenciales salen de primeras, y eso se
resuelve al llamar el turno y no al entregarlo. Ver `docs/llamado-de-turnos.md`.

## Pruebas

```bash
php artisan test --filter=ColasYVentanillasTest
```

20 pruebas: el formato del turno, el consecutivo por sede y por día,
que 50 turnos seguidos no repitan ninguno, el filtrado por sede en listado y
formulario, que el administrador vea todo, las reglas de eliminación, los
permisos, la auditoría y el seeder.
