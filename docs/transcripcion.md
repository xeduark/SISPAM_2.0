# Módulo Transcripción — lectura de fórmulas (Gemini o Google Vision)

Estado: **fases 1 a 4 hechas** (2026-10-05 / 08): lectura en cola, inventario por API, pantalla de revisión
(original + previsualización del acta + formulario), orden de entrega, acta de entrega, rectificación.

## Archivos (fase 1)
- `database/migrations/2026_10_05_100000_crear_transcripciones.php`
- `app/Models/Transcripcion.php`, `TranscripcionItem.php`
- `app/Jobs/LeerFormula.php` — se despacha desde `Soporte::created` en `AppServiceProvider`
  (no se tocó pacientes ni tickets). Sin reintento automático: cada lectura de Vision cuesta.
- `app/Services/Transcripcion/GoogleVisionClient.php` — clave en el encabezado `X-Goog-Api-Key`, nunca en la URL.
- `app/Services/Transcripcion/InterpretarFormula.php` — cédula, líneas de medicamento, similitud, alertas.
- `app/Contracts/Inventario/*` + `app/Services/Inventario/CatalogoInventarioMock.php`
- `app/Filament/Resources/TranscripcionResource.php` (`/admin/transcripciones`)
- Pruebas: `php artisan test --filter=TranscripcionTest`

## Fase 2 hecha — revisar, corregir y confirmar (2026-10-06)
Copia de la pantalla «Verificación» del nativo (`views/verificacion/index.php`).
- Pantalla: `TranscripcionResource\Pages\RevisarTranscripcion` (`/admin/transcripciones/{id}`):
  izquierda el original (imagen con zoom/rotar, o PDF), derecha una tarjeta por fórmula (IPS, fechas,
  CIE-10, MIPRES, autorización, médico) y los medicamentos (fórmula #, producto de bodega con búsqueda
  en el inventario, posología, total, meses, entrega n de m, entregar hoy, próximo saldo).
- Reglas: `App\Services\Transcripcion\RevisarTranscripcion` (tomar con `lockForUpdate`, soltar, guardar
  con `editado_por`, confirmar, rechazar con motivo de `Transcripcion::MOTIVOS_RECHAZO`).
- Varias fórmulas en una imagen: como el nativo (pestañas), cada item guarda `formula` (n° dentro de
  la imagen) y `transcripciones.formula = {formulas: [...]}`. Nunca se mezclan.
- Confirmar exige: cédula revisada a mano si `no_encontrada` (queda `revisada`), fecha de expedición
  no futura, vigencia no vencida, IPS y médico, producto en cada línea, cantidad del mes, entrega n de m
  válida, y que la entrega no supere el total prescrito. Si falta algo se guarda lo corregido y se
  muestra la lista.
- Pruebas: `php artisan test --filter=RevisionTest`.
- No se copió del nativo (para después si hace falta): control antifraude de entregas del mes,
  cierre administrativo, devolver a ventanilla, historial del paciente.

## Fase 3 hecha — orden de dispensación imprimible (2026-10-06)
- `GET /tickets/{ticket}/orden-entrega` (`OrdenEntregaController`, vista `ordenes/entrega.blade.php`,
  datos en `App\Services\Transcripcion\OrdenDeEntrega`). Botón «Orden de entrega» en la revisión
  cuando está confirmada. Permiso `transcripcion.ver` o `entrega.ver`, solo su sede (admin todas);
  cada apertura queda en `auditorias` (`imprimio_orden_entrega`). HTML con `window.print()`, sin librería PDF.
- Corrige el ejemplo del nativo (`public/img/Orden de Dispensación…pdf`): fórmulas numeradas de corrido
  en el ticket y cada medicamento con la suya; posología real; sin «total de unidades»; lote y vence
  en blanco para quien alista (el ejemplo ponía «FEFO»).
- Separa **1. ventanilla** (hay stock en la sede según la API al imprimir) de **2. pendiente / domicilio**
  (con dirección y teléfono; avisa si el contacto no está confirmado). Sin API: todo en 1 con aviso.
- Si alguna transcripción del ticket no está confirmada, sale como **BORRADOR**.
- Datos de empresa en `config/empresa.php` (nombre, NIT, logo, EPS).
- Pruebas: `php artisan test --filter=OrdenEntregaTest`.

## Fórmula que no se dispensa y acta de entrega (2026-10-07)
- En la revisión, cada tarjeta de fórmula tiene «Esta fórmula NO se dispensa» + motivo
  (`Transcripcion::MOTIVOS_RECHAZO_FORMULA`: vencida, sin médico, ilegible…). Las demás fórmulas siguen.
  Sus medicamentos no exigen producto ni cantidades, no se alistan y salen en la orden (sección 3).
  Si todas quedan marcadas, se rechaza la transcripción completa.
- **Acta de entrega** `GET /entregas/{id}/acta` (`ActaEntregaController`, vista `actas/entrega`; botón «Acta» en
  Entregas): lo entregado con lote y vencimiento (del kardex del inventario, `GET /api/movimientos?referencia=ENTREGA-{id}`),
  lo pendiente con motivo, lo que no se dispensa con su motivo, constancia y la firma de quien recibe
  (incrustada desde el disco privado). Queda en `auditorias` (`abrio_acta_entrega`).
- Las pruebas de entrega escribían firmas en `storage/app/private` real y pisaban las locales: ahora usan
  `Storage::fake('local')` (EntregaModuloTest, IntegracionEntregaTest, DispensadorAccesoTest).

## Pantalla de revisión (2026-10-08)
Arriba, lado a lado: la fórmula original y la **previsualización del acta** (con lo que hay en pantalla, sin
guardar): cada fórmula con sus medicamentos y a dónde va cada uno según el stock de la sede (ventanilla, parcial,
domicilio, no se dispensa). Botón ✓ por línea = `transcripcion_items.revisado`: no se confirma con líneas sin
revisar. Stock cacheado 2 min por sede (`OrdenDeEntrega::separarPorStock(cacheado: true)`). Abajo, el formulario.

## Fase 4 hecha — rectificación (2026-10-08)
- Botón «Rectificar» en una confirmada (permiso `transcripcion.rectificar`), con motivo obligatorio.
  `RevisarTranscripcion::rectificar()`: solo si no hay entregas del ticket (no anuladas); la deja en revisión,
  tomada por quien rectifica, y guarda la foto «antes» en `formula.rectificacion`.
- Al confirmar: `version` + 1 y auditoría `rectifico_transcripcion` con antes → después (fórmula, producto y
  cantidades por línea, y fórmulas no dispensadas; sin texto clínico). La orden dice «VERSIÓN n — RECTIFICADA».
- En rectificación no se puede «Soltar» (perdería que estaba confirmada): se termina confirmando.
- Si el ticket ya fue alistado, farmacia debe alistarlo de nuevo (el puente transcripción → ticket es del
  módulo de tickets).
- Pruebas: `php artisan test --filter=RectificacionTest`.

## Demo local
`php artisan db:seed --class=DemoProcesoSeeder` → 4 pacientes inventados, tickets A-00x en PRIN,
5 fórmulas dibujadas como PNG, A-001 alistado y llamado (se ve en `/sala/PRIN`).
Google Vision devolvió 403 por **facturación no activada** en el proyecto de Google Cloud; las 5
transcripciones de la demo del 2026-10-05 se llenaron con texto simulado (empieza con
`[SIMULADO, NO ES GOOGLE VISION]`). Tras activar facturación: reiniciar `queue:work` y volver a sembrar.

## Objetivo
La orientadora sube la(s) fórmula(s) del paciente (ya pasa: tabla `soportes`, disco privado).
**Los medicamentos los trae la fórmula** (OCR). Transcripción **solo compara y confirma**:
1. Google Vision lee cada fórmula y propone medicamentos + datos de la fórmula.
2. Se **verifica la cédula** impresa contra el paciente.
3. Pantalla lado a lado **fórmula original (imagen) ↔ lectura generada**: confirmar o corregir.
4. Al confirmar se genera la **Orden de Dispensación & Alistamiento** (modelo:
   `public/img/Orden de Dispensación & Alistamiento - TK-PRD-261005-0333.pdf`, logo en `turnero.md`)
   y con ella **lo que farmacia debe despachar**.

### Lo que se corrige del formato de ejemplo
1. **Separar ventanilla de domicilio.** El ejemplo pone todo en una sola tabla. La orden nuestra
   tiene dos secciones: «Entrega en ventanilla» y «Pendiente — envío a domicilio» (con dirección
   y teléfono confirmados del paciente, `Paciente::CAMPOS_CONTACTO`). Los domicilios siguen por el
   flujo existente (`domicilio_envios`, Dómina).
2. **Cada medicamento con su fórmula real** (ver regla abajo); el ejemplo marca todo «Fór. #1».

**Quién separa ventanilla de domicilio: farmacia**, al alistar con la orden ya generada.
Lo que no hay en la sede queda pendiente y sale por Dómina (flujo existente). Transcripción no decide eso.

## Coincidencia con el inventario (a ciegas por ahora)
Referencia de pantalla: el bloque «Información de Prescripción & Auditoría RIPS» del sistema anterior.
Por cada medicamento leído de la fórmula:
- Se busca en el **catálogo de inventario** el producto más parecido y se muestra:
  código (`MX804-1`), agrupador (`MX804`), nombre en bodega, **% de similitud**.
- Alertas visibles: **«Difiere concentración»** (Rx 40 MG vs bodega 10 MG), forma farmacéutica distinta,
  similitud baja. Nunca se acepta sola: la transcriptora confirma cada línea.
- La transcriptora **compara y edita** (cantidad, producto sugerido, meses), no digita desde cero.
- Plan de tratamiento por línea: total prescrito, meses, **entrega hoy (mes n de m)**, saldo futuro.
  Ej. Losartan 50 mg: 120 total, 12 meses → hoy 10, saldo 110.

**El inventario es una API aparte** (2026-10-06): proyecto `C:\laragon\www\inventario-api`
(Laravel 13, base propia `inventario_db`, sacada de `farmacia_db` del nativo).
- Endpoints (token `Authorization: Bearer`, `INVENTARIO_API_TOKEN` igual en los dos `.env`):
  `GET /api/productos?buscar=&sede=` y `POST /api/dispensaciones {referencia, sede, items:[{codigo, unidades|presentaciones}]}`.
- Stock en **presentaciones** (tableta, frasco, caja). El producto tiene `unidad_minima` y
  `unidades_por_presentacion`: 180 gotas con frasco de 100 → 2 frascos (redondeo hacia arriba).
- Descuento FEFO repartido entre lotes, sin vencidos ni de otra sede; lo que no alcanza vuelve como
  `faltante`; la misma `referencia` no descuenta dos veces. Kardex en `movimientos`.
- Importar del nativo: `php artisan inventario:importar-nativo` (los lotes ya importados no se pisan).
  Mapa de sedes nativo → SISPAM_2 en el comando (PRD→PPLZ, AYC→AVEN, CTR→LA30, PBL→BIC; Laureles sin sede).
- Demo: `php artisan db:seed` (goteros y agujas en LA30). Local: `php artisan serve --port=8100`.
- En SISPAM_2: `App\Services\Inventario\InventarioApi` (buscar + dispensar). Con `INVENTARIO_API_URL`
  vacío se usa el mock. Al pasar una `Entrega` a completada/parcial se encola
  `DescontarInventarioDeEntrega` (referencia `ENTREGA-{id}`); requiere `queue:work`.
- **El inventario es un sistema aparte con interfaz propia** (decisión 2026-10-06): productos, bodegas,
  existencias, kardex, ajustes y carga masiva se administran en `http://127.0.0.1:8100` (ver README de
  inventario-api). SISPAM_2 solo **consulta por SKU** (menú Inventario → «Consultar por SKU»,
  `ExistenciasInventario`, `GET /api/productos/{sku}?sede=`, permiso `inventario.ver`) y registra las salidas
  al entregar (`POST /api/dispensaciones`).
- Facturación (decisión 2026-10-06): se factura lo entregado, nunca un faltante ni una entrada a bodega.
  Pendiente para quien lleva entrega: una entrega a domicilio debería quedar facturable cuando Dómina
  la marca `entregado`, no al alistar.
- Fallos del nativo que no se copiaron: un solo lote por item, `estado_lote = 'ACTIVO'` que no existe en
  el enum (nunca descontaba por FEFO), entregas cargadas al primer lote del sistema, stock forzado a 0.
- `App\Contracts\Inventario\CatalogoInventarioInterface::buscar(string $prescrito): list<Coincidencia>`
- `CatalogoInventarioMock` con unos productos de ejemplo (los del PDF y de esta referencia).
- Stock y lote FEFO: **no se muestran** hasta tener inventario real (la interfaz los deja en `null`).
- Similitud mientras tanto: normalizar (mayúsculas, sin tildes, unidades `MG`/`MCG`) + `similar_text()`
  de PHP, más comparación aparte de la concentración. `ponytail:` cambiar por la búsqueda del inventario
  real cuando exista.

## Datos que exige Savia (reporte de 85 columnas)
`public/reportes/BD 85 COLUMNAS.xlsx` es el formato de dispensación que pide la EPS. Lo que la
transcripción debe dejar capturado para poder llenarlo después:
número y tipo de fórmula (FORMULA / MIPRES), fecha fórmula, **duración del tratamiento en días**,
**cantidad solicitada (total del tratamiento)**, cantidad del mes, IPS formulante, médico (código,
nombre, especialidad), CIE-10, tipo autorización / NUA, código producto, CUM/ATC (los trae el
inventario cuando exista). Entregada / pendiente / faltante las llena entrega, no transcripción.

## Regla de exactitud: un paciente, muchas fórmulas
Un ticket puede tener varias fórmulas (el ejemplo tiene 4). **Cada medicamento pertenece a una
sola fórmula y nunca se mezclan.** El PDF de ejemplo muestra justo el error a evitar: 4 fórmulas
de IPS distintas y los 15 medicamentos marcados «Fór. #1».
- Cada `soporte` se transcribe por separado; el item guarda `soporte_id`.
- Si una imagen trae dos fórmulas, la transcriptora la parte (o la orientadora sube cada una aparte).
- Mismo medicamento en dos fórmulas = dos items distintos (ej. Pregabalina 150 mg #9 y #14 del ejemplo).
- Por fórmula se guarda: IPS, médico + RM, especialidad, CIE-10 (principal/secundarios), MIPRES,
  fecha de expedición, vigencia, entrega n de m.

## Cola de trabajo
Ya hay `QUEUE_CONNECTION=database` y tabla `jobs`. No se agrega nada más.

```
soporte subido → Job LeerFormula (por soporte) → transcripciones.estado = por_revisar
                → pantalla «Por transcribir» (filtrada por sede, FiltraPorSede)
                → transcriptora toma una (lockForUpdate, como LlamadorDeTurnos) → confirma / corrige
                → orden de entrega generada (ventanilla + domicilio) → farmacia alista
```
Estados de `transcripciones`: `en_cola` → `leida` (Vision respondió) → `en_revision` (alguien la tomó)
→ `confirmada` | `rechazada` (ilegible, cédula no coincide). Error de Vision → `fallida`, reintento manual.
Correr local: `php artisan queue:work`.

## Verificación de cédula
Se quitan separadores de miles (`1.000.873.458` → `1000873458`) y se busca el documento del
paciente entre los números del texto. Resultado: `coincide` / `no_encontrada`.
No hay «no coincide»: una fórmula trae muchos números (registro médico, teléfonos, autorizaciones),
así que no encontrar la cédula no prueba que sea de otra persona. `no_encontrada` → la transcriptora
lo revisa en el original.

## Rectificar (rebase del ticket)
Si después de confirmada hay un error (cantidad, medicamento, fórmula equivocada):
- Se reabre la transcripción, se corrige y se **regenera la orden** (nueva versión; la anterior queda).
- Solo mientras no se haya entregado.
- Pueden hacerlo las del rol transcripción **y las orientadoras sobre tickets ya confirmados**
  (permiso `transcripcion.rectificar`).
- Cada rectificación queda en `auditorias` con antes → después de cantidades
  y códigos, sin texto clínico libre.

- **No se modifica el módulo de tickets**: la orden y sus items viven en tablas de transcripción;
  entrega las lee.

## Datos y privacidad
- Tablas: `transcripciones` (soporte_id, ticket_id, estado, texto_ocr, cedula_detectada,
  verificacion_cedula, datos de la fórmula, tomada_por, confirmada_por/en) y
  `transcripcion_items` (transcripcion_id, texto_prescrito, concentración, forma, posología,
  cantidad_total, duracion_dias, meses, entrega_mes, cantidad_mes, codigo_inventario, agrupador,
  nombre_inventario, similitud, alertas json, editado_por).
- `texto_ocr` es dato de salud: columna `encrypted` (cast de Laravel), nunca en logs ni en `auditorias`.
- Auditar con `CAMPOS_AUDITADOS` solo estado, ids y quién confirmó.

## Motor de lectura: Gemini (como el nativo) o Vision
`TRANSCRIPCION_MOTOR=gemini|vision` en el `.env`; el job es `LeerFormula`.
- **Gemini** (`GeminiClient`): el motor del sistema nativo (`AIExtractorService`). Devuelve la fórmula ya
  ordenada en JSON: paciente, fórmulas (IPS, médico, CIE-10, MIPRES, autorización) y medicamentos
  (posología, duración, cantidad total). `InterpretarFormula::medicamentosDeLectura()` calcula meses y
  cuota del mes y busca el producto en inventario. Si la imagen trae varias fórmulas, cada línea lleva la
  alerta para partirla. `.env`: `GEMINI_API_KEY` (Google AI Studio), `GEMINI_MODELO`.
  **Con datos de pacientes usar el plan pago**: en el gratuito Google puede usar lo enviado para mejorar
  sus modelos.
- No se copió del nativo: la clave en la URL, `CURLOPT_SSL_VERIFYPEER=false`, y el «extractor simulado»
  que, si fallaba la IA, devolvía medicamentos de ejemplo como si fueran la lectura.

## Google Vision
- API: `images:annotate` con `DOCUMENT_TEXT_DETECTION` (mejor para manuscrito/tablas que `TEXT_DETECTION`).
  PDF → `files:annotate` (hasta 5 páginas síncrono).
- Llamada con `Http::` de Laravel y API key — no hace falta el SDK `google/cloud-vision`.
- `.env`: `GOOGLE_VISION_API_KEY` → `config('services.google_vision.key')`.
- Crear la key: Google Cloud Console → proyecto → habilitar *Cloud Vision API* → Credenciales →
  Crear clave de API → **restringirla a Cloud Vision API** (y a la IP del servidor en producción).
- Pruebas con `Http::fake()`, como `SaviaClientTest`. Ninguna prueba toca Vision real.

## Permisos y rol
- Módulo `transcripcion` en `Rol::MODULOS`: `ver`, `transcribir`, `rectificar`.
- Para abrir el original desde la transcripción el rol necesita además `orientacion.ver_orden`.
- Rol de prueba en **Authentik** (se hace en la consola de Authentik, no en código):
  1. Directory → Groups → Create → nombre `transcripcion`.
  2. Directory → Users → crear usuario de prueba con **username = su documento**; agregarlo al grupo.
  3. En SISPAM: crear el usuario (recurso Usuarios, mismo documento, `activo`, sede) y en
     Roles y permisos marcarle al rol `transcripcion` las acciones del módulo.
  Al iniciar sesión el claim `groups` copia el rol a `users.roles`.
