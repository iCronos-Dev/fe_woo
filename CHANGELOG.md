# Changelog

Todos los cambios notables del plugin se documentan en este archivo.

Formato basado en [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/).
Versionado [SemVer](https://semver.org/lang/es/).

Mantenido por [icronos.dev](https://icronos.dev).

## [1.32.0] - 2026-05-11

### Added
- **Filter `fe_woo_emission_datetime`** en
  `FE_Woo_Factura_Generator::get_emission_datetime()`. Permite a callers
  (típicamente manual batches del modelo hold-back de pausa por evento)
  sobreescribir el `DateTime` usado tanto para los componentes DDMMYY de
  la `<Clave>` como para el campo `<FechaEmision>` del XML. Default sin
  callbacks: idéntico al pre-1.32.0 (`$order->get_date_created()` en CR tz),
  preservando el fix de v1.29.1 para -405 en cruce de medianoche del cron.
  Argumentos: `(DateTime $dt, WC_Order $order, array $ctx)` donde
  `$ctx['source']` es el label del source actual ('cron', 'manual',
  'manual_operaciones_productor', etc.).
- **`FE_Woo_Factura_Generator::set_emission_source(?string $source)`**
  static setter — expone al stack de generación la etiqueta del lote
  actual para que callbacks del filter discriminen contextos. Lo setea/
  limpia automáticamente `FE_Woo_Queue_Processor::process_items()` antes
  y después del loop, en `try/finally`, para que excepciones mid-batch no
  filtren el override a emisiones posteriores en el mismo request.

### Changed
- `FE_Woo_Queue_Processor::process_items()` ahora hace
  `FE_Woo_Factura_Generator::set_emission_source($opts['source'])` al
  entrar y `set_emission_source(null)` en `finally`. Cambio interno; la
  firma pública y el contrato de stats no cambian.

### Security
- `get_emission_datetime()` valida defensivamente el retorno del filter:
  si un callback retorna algo que no es `DateTime`/`DateTimeImmutable`, se
  cae al default (creación de la orden) en lugar de propagar un valor
  inválido que generaría -405. Defensa-en-profundidad — los callbacks
  legítimos del tema retornan siempre un DateTime.

## [1.31.0] - 2026-05-11

### Added
- **Filter `fe_woo_pending_items`** en `FE_Woo_Queue::get_pending_items()`.
  Permite a integraciones externas (p.ej. el tema padre, modelo hold-back
  por evento) excluir queue items del barrido del cron sin tocar el SQL del
  plugin. Post-filter sobre el resultado del SELECT — recibe
  `(array $items, int $limit)` y debe retornar el subset que el cron debe
  procesar. Default: identity passthrough.
- **`FE_Woo_Queue::get_pending_items_for_product(int $product_id, int $limit = 10)`**.
  Retorna queue items en estado `pending`/`retry` cuyo `order_id` contiene
  el producto especificado (o cualquiera de sus variations) en sus line
  items. **No** aplica `fe_woo_pending_items` — escape hatch explícito para
  manual batches que deliberadamente procesan items retenidos por filters.
- **`FE_Woo_Queue_Processor::process_items(array $items, array $opts = [])`**
  pública. Recibe una lista pre-fetched de queue items y los procesa con la
  misma lógica de seguridad que el cron (per-order lock, mark_processing,
  exception handling). Retorna estadísticas:
  `['attempted', 'processed', 'failed', 'skipped', 'errors']`.
  Opciones:
  - `acquire_global_lock` (bool, default true): si toma el transient
    `fe_woo_queue_processing`. Manual batches pueden pasar `false` para
    coexistir con el cron — el per-order lock sigue garantizando atomicidad.
  - `source` (string, default 'manual'): label libre para trazabilidad en
    los logs.

### Changed
- **`FE_Woo_Queue_Processor::process_queue()`** ahora es un wrapper sobre
  `process_items()`. Comportamiento externo idéntico al pre-1.31.0 (cron sigue
  procesando lotes de 10, toma el global lock, hace `recover_stale_processing_items`
  antes del SELECT). El wrapper adquiere el transient `fe_woo_queue_processing`
  **antes** de `recover_stale_processing_items()` y `get_pending_items()` para
  cerrar una ventana TOCTOU donde dos cron ticks podían leer el mismo set de
  pending rows antes de set_transient. Internamente delega a `process_items()`
  con `acquire_global_lock = false` (lock ya tomado).
- **`FE_Woo_Queue_Processor::process_item()`** ahora retorna un string
  outcome: `'processed' | 'failed' | 'skipped_locked'`. La firma sigue siendo
  privada y el contrato externo no se rompe — solo `process_items` consume
  el valor de retorno.

### Performance
- `get_pending_items_for_product()` usa `EXISTS` en lugar de `INNER JOIN
  + DISTINCT` para evitar materializar un temp table sobre las columnas
  longtext del queue (`factura_data`, `xml_data`, `hacienda_response`)
  cuando hay muchas órdenes por producto. El inner scan corta en el
  primer match por orden vía `LIMIT 1`.

### Security
- `apply_filters('fe_woo_pending_items', ...)` ahora valida el shape del
  retorno: cada item debe ser un objeto con `id` y `order_id`, items
  malformados se descartan en lugar de propagarse a `process_item()`.
- `process_items()` documenta explícitamente vía docblock `@security` que
  el caller es responsable de capability/nonce checks; no expone el
  método directamente a AJAX/REST sin un gate en el handler.

## [1.30.0] - 2026-05-09

### Added
- **Filter `fe_woo_should_skip_emisor_factura`** en
  `FE_Woo_Multi_Factura_Generator::generate_facturas_for_order()`. Permite a
  integraciones externas (p.ej. el tema padre, vetando emisores cuyos
  productos están pausados a nivel evento) saltar la generación de la
  factura de un emisor específico para una orden sin afectar las facturas
  de los otros emisores de la misma orden. Argumentos: `(bool $skip,
  int|string $emisor_id, array $items, WC_Order $order)`. La bandera
  `include_shipping` se preserva para el siguiente grupo que sobreviva al
  filtro, garantizando que el cargo de envío siempre aterrice en una
  factura efectivamente generada.

## [1.29.4] - 2026-05-08

### Fixed
- **El botón "Volver a consultar a Hacienda" fallaba con "API credentials not
  configured" en sitios multi-emisor donde la config global está vacía.** El
  flujo POST inicial (envío de la factura) sí soportaba multi-emisor vía
  `send_invoice_with_emisor()` + `obtain_access_token_with_emisor()`, pero el
  flujo GET (consulta de status) no tenía análogo: `query_invoice_status()`
  caía en `obtain_access_token()` global, que en estos sitios devuelve vacío.
  El mismo bug atascaba el polling cron del acuse (`fe_woo_poll_acuse_xml`)
  y `refresh_hacienda_status_from_api`, dejando órdenes en `procesando`
  indefinidamente aunque Hacienda ya hubiera respondido.

### Added
- `FE_Woo_API_Client::query_invoice_status_with_emisor($clave, $emisor)`:
  análogo simétrico de `send_invoice_with_emisor()`. Autentica con las
  credenciales del emisor antes del GET.
- `FE_Woo_API_Client::resolve_emisor_for_clave($clave)`: extrae la cédula
  del clave (posiciones 9..20, padded a 12 dígitos) y resuelve a un emisor
  activo, con fallback al parent emisor.
- `FE_Woo_Emisor_Manager::get_emisor_by_cedula($cedula)`: lookup agnóstico
  de padding (matchea `003101950828` con `3101950828` en BD).

### Changed
- `FE_Woo_API_Client::query_invoice_status()` ahora resuelve emisor por
  clave y delega al variant `_with_emisor` cuando hay credenciales. Si no
  hay match, cae al flujo legacy con config global (preserva compat con
  sitios single-emisor).
- `FE_Woo_Queue_Processor::process_single_factura()` usa el variant
  explícito en la verificación de `_fe_woo_factura_clave_pending` ya que
  el emisor está resuelto en ese scope.

## [1.29.3] - 2026-05-08

### Fixed
- Órdenes con `_fe_woo_otras_senas` vacío fallaban al emitir desde admin
  "Ejecutar" con "Faltan datos del receptor: Otras Señas". El XSD v4.4 exige
  `minLength=5` en `<OtrasSenas>`, así que el campo no puede ser opcional a
  nivel XML — pero sí puede ser opcional para el cliente si se concatena un
  suffix invisible al emitir.

### Changed
- Nuevo helper `FE_Woo_Factura_Generator::build_otras_senas_effective()` que
  computa el valor de `<OtrasSenas>` concatenando un suffix fijo
  (`' Otras senas para la direccion'`) al texto del cliente y truncando a
  250 chars. La meta del order **no se modifica** — el suffix se aplica solo
  al emitir XML y al validar.
- `build_receptor()` consume el helper para `<OtrasSenas>`.
- `validate_receptor_data()` valida el valor efectivo (siempre 5–250 chars
  por design) y removió `_fe_woo_otras_senas` del array `$required`.
- Constante `RECEPTOR_OTRAS_SENAS_SUFFIX` cambia de
  `'| otras senas especificadas por el emisor'` a
  `' Otras senas para la direccion'` (más limpio en el documento fiscal).

## [1.29.2] - 2026-05-07

### Fixed
- **Hacienda XSD rejection en órdenes con caracteres especiales en datos de cliente**
  — `DOMDocument::createElement($name, $value)` no escapa caracteres especiales
  XML. Un `&` en el valor (común en nombres de empresa: "ALFARO & JIMENEZ",
  "LUTZ HERMANOS & COMPAÑIA") disparaba el warning de PHP "unterminated entity
  reference" y dejaba el elemento `<Nombre>` con valor vacío. Hacienda
  rechazaba por XSD: `Element 'Nombre': [facet 'minLength']`. El bug afectaba
  también `<NombreComercial>`, `<OtrasSenas>`, `<Detalle>` y cualquier otro
  campo con texto del cliente que pudiera contener `&`, `<` o `>`.
- Nuevo helper privado `FE_Woo_Factura_Generator::text_element()` que usa
  `appendChild(createTextNode(...))` para escape automático de todos los
  caracteres XML especiales. Aplicado a los 110 sitios donde el valor del
  elemento es variable. Los 26 sitios de creación de containers vacíos
  (sin valor) se mantienen con `createElement($name)` puro.

## [1.29.1] - 2026-05-07

### Fixed
- **Hacienda -405 en órdenes procesadas por la cola** — `<FechaEmision>` se
  derivaba de `now()` (hora del cron tick) mientras la `<Clave>` codificaba
  la fecha de creación de la orden. Cuando el cron lag de Pantheon cruzaba
  medianoche CR (orden creada 23:55 día N → procesada 01:30 día N+1) las
  fechas divergían y Hacienda rechazaba con código -405 ("La fecha de la
  clave numérica no concuerda con el campo Fecha Emisión del comprobante").
  La emisión manual desde el admin (`ajax_manual_execute_factura`) no se
  veía afectada porque el operador típicamente ejecuta dentro del mismo día
  CR en que se creó la orden.
- Ambos campos ahora se derivan del nuevo helper
  `FE_Woo_Factura_Generator::get_emission_datetime()` (single source of
  truth basada en `$order->get_date_created()` en CR tz).
- Trade-off conocido: órdenes con cron lag grande pueden recibir la
  observación -53 ("hora no coincide con hora oficial"). -53 es
  no-bloqueante (sale en `aceptado`). -405 era rechazo terminal y bloqueaba
  toda la cola de la orden hasta intervención manual.

## [1.29.0] - 2026-05-06

### Changed
- **Sucursal por defecto** en la generación de clave numérica cambia de
  `007` a `088` (`FE_Woo_Factura_Generator::generate_clave`).

### Fixed
- **Consulta manual a Hacienda** — respuestas con `success=false` ahora
  se detectan y devuelven el mensaje de error al usuario en lugar de
  continuar al procesamiento de acuse (`FE_Woo_Order_Admin`).

## [1.28.1] - 2026-05-05

### Changed
- **Sucursal por defecto** en la generación de clave numérica cambia de
  `001` a `007` (`FE_Woo_Factura_Generator::generate_clave`).

## [1.28.0] - 2026-05-02

Hardening operativo de la cola de envío: cuatro fixes que eliminan modos
de falla silenciosa donde una orden podía quemar consecutivos extra,
correr concurrente con el cron, o quedarse en `failed` sin que nadie se
enterara. Consolida planes #1, #2, #3 y #5 (#4 — recovery automático de
polls de acuse — queda diferido).

### Added
- **Comando WP-CLI `wp fe-woo unblock_failed`** — reactiva en lote items
  varados en `status='failed'` (status → `retry`, attempts → `0`,
  error_message → `NULL`). Acepta `--since`, `--limit`, `--max-attempts=N`
  para override per-item, y por defecto excluye rechazos terminales de
  Hacienda. Pensado para reanimar transitorios de red / outages de
  Hacienda sin tocar SQL.
- **Método `FE_Woo_Factura_Generator::rebuild_xml_for_clave()`** —
  reconstruye el XML para una clave existente sin consumir nuevo
  consecutivo. Usado por el retry del queue cuando la clave previa
  quedó "pending" tras un POST fallido.
- **Meta `_fe_woo_factura_clave_pending`** — guarda la clave generada
  ANTES del sign+POST a Hacienda. Sirve como ack temprano para que el
  retry del próximo tick pueda recuperar sin regenerar consecutivo.

### Fixed
- **Reuso de clave en retry single-factura** (Plan #2): cuando un POST a
  Hacienda fallaba después de `generate_clave` pero antes de persistir
  el meta confirmado, el siguiente tick **regeneraba clave nueva y
  consumía OTRO consecutivo**. Ahora el retry consulta primero
  `/recepcion/{clave}` con la clave pending:
  - Hacienda la tiene (cualquier estado) → skip POST, recovery via acuse.
  - Hacienda 404 → rebuild XML con misma clave + sign + re-POST.
  - Hacienda error/timeout → throw, retry próximo tick (clave
    preservada). Cero consecutivos perdidos por outages de red.
- **Per-order lock en cron worker** (Plan #3): `process_item()` ahora
  adquiere `FE_Woo_Order_Lock` antes de `mark_processing` y lo libera
  en `finally`. Cierra la ventana de race contra rutas manuales
  (Reintentar, Ejecutar, `reexecute_invoice`) que ya tomaban el mismo
  lock. Sin esto un operador clickeando "Ejecutar" mientras el cron
  estaba mid-POST sobre el mismo order_id producía dos consecutivos
  consumidos y dos POST con misma intención fiscal.
  - Comportamiento ante contención: log + skip (no incrementa attempts);
    próximo tick lo intenta cuando el lock libere o expire (TTL 300s).
- **Threshold del `health_check`** (Plan #5): hardcoded a 5 mientras el
  schema de la cola usa `max_attempts=3` por defecto. El email diario
  de "cola requiere atención" nunca se disparaba porque ningún item
  realmente fallido (3 intentos) llegaba al threshold (5+). Bajado a 3
  para alinear con schema y con `unblock_failed`. Body del email ahora
  menciona `wp fe-woo unblock_failed` como herramienta canónica.

### Changed
- **`clear_invoice_data()`** ahora también borra
  `_fe_woo_factura_clave_pending`. Si el operador hace force-rerun, la
  pending se limpia para no disparar la rama de recovery sobre datos
  obsoletos.
- **`query_invoice_status()`** del API client devuelve
  `not_found = true` cuando Hacienda responde HTTP 404 (clave no
  encontrada). Permite distinguir "Hacienda nunca recibió" de "Hacienda
  está temporalmente caída" en el path de recovery.

### Notes
- **Multi-factura no se modifica** en este release: el flujo
  `generate_and_send_multi_facturas` mantiene su retry parcial actual
  (resume desde la primera factura con `status='sent'`). El caso
  análogo de pending-clave-en-multi queda para un release posterior si
  hace falta.
- **Schema sin cambios**: `max_attempts` sigue en 3 por default. Plan #1
  permite override per-item via `--max-attempts=N`.
- **Plan #4 diferido**: recovery automático de polls de acuse perdidos
  (sweep de órdenes en `procesando` sin evento cron pendiente). El JS
  recheck del admin + `wp fe-woo find_orphans` cubren el caso por ahora.

## [1.27.0] - 2026-05-02

### Changed
- **`OtrasSenas` del Receptor ahora es opcional** en checkout y admin de orden.
  Al construir el XML del Receptor se concatena siempre el sufijo
  `| otras senas especificadas por el emisor` al texto del cliente, con
  truncado a 250 chars si el resultado lo excede. Garantiza el `minLength=5`
  del XSD v4.4 sin bloquear órdenes con campo vacío.
- UI de checkout y admin: removida la marca de campo requerido (`*`) y el
  hint "Mínimo 5 caracteres, máximo 250.". Reemplazado por "Opcional. Si lo
  dejas vacío, completaremos genéricamente para Hacienda. Máximo 250 caracteres."

### Removed
- Validación pre-flight de longitud de `OtrasSenas` del Receptor en
  `validate_receptor_fields()` (la regla ahora es estructural en
  `build_receptor`).
- Validación de campo requerido y longitud (5–250) en
  `validate_factura_electronica_fields()` durante checkout.
- Check `>= 5` chars en la condición de emisión del bloque `<Ubicacion>` del
  Receptor en `build_receptor`. La nueva condición solo requiere
  `provincia && canton && distrito` válidos contra el catálogo CR.

### Added
- Constante `FE_Woo_Factura_Generator::RECEPTOR_OTRAS_SENAS_SUFFIX`.

### Notes
- **Emisor sin cambios**: sigue requerido y validado (5–250) en settings y
  pre-flight. Esto es config one-time del admin del sitio; un emisor mal
  configurado debe arreglarse, no enmascararse.
- Órdenes POS sin ubicación capturada continúan emitiendo XML del Receptor
  sin bloque `<Ubicacion>` (sin regresión).

## [1.26.1] - 2026-05-02

### Fixed
- **Empaquetado del autoload de Composer**: el `vendor/composer/autoload_*.php`
  commiteado en v1.26.0 se generó con dev deps activas, así que registraba
  paquetes (`myclabs/deep-copy`, `phpunit`, `sebastian/*`, `doctrine/instantiator`,
  `nikic/*`, `phar-io/*`, `theseer/*`) que el `.gitignore` del propio plugin
  excluye del commit. Resultado: en cualquier instalación vía Composer
  (zip dist) WordPress moría con
  `Failed opening required '.../myclabs/deep-copy/src/DeepCopy/deep_copy.php'`
  durante el bootstrap del plugin.
  - `vendor/` regenerado con `composer install --no-dev --optimize-autoloader`.
  - Añadido script `composer release-vendor` para automatizar el comando
    correcto antes de cada tag.
  - Documentado el flujo de release en `README.md`.

## [1.26.0] - 2026-05-01

### Added
- Pre-flight de longitud de `OtrasSenas` (5–250 caracteres) en emisor y
  receptor antes de firmar — evita rechazos `OtrasSenas length` de Hacienda
  y que se queme un consecutivo en validaciones que solo el XSD detectaría.
- Defense-in-depth en `build_emisor` / `build_receptor`: trim, truncado a
  250 chars y omisión del bloque `Ubicacion` del receptor cuando
  `OtrasSenas` < 5 chars.
- Tests `tests/DocumentStorageTest.php` (11 casos) cubriendo round-trip
  save/get, fallback al layout legacy, idempotencia de delete y cache
  del `FE_Woo_Document_Storage` introducido en 1.25.0.

### Changed
- Notices del admin (`order-admin.js`): soporte de tipo `info`, render
  multi-línea (`\n` → `<br>`) para pre-flights con varias viñetas, y
  errores persistentes hasta que el admin los descarte.

## [1.25.0] - 2026-05-01

### Changed
- **Document storage layout fechado**: los archivos pasan de
  `factura-electronica/order-{id}/` a `factura-electronica/Y/m/d/order-{id}/`,
  derivando `Y/m/d` de la fecha de creación de la orden. Reduce la cantidad
  de entradas por directorio en sitios con miles de órdenes.
- `delete_order_documents()` limpia tanto el directorio fechado como el
  legacy plano.

### Added
- Cache per-order de la fecha resuelta en `FE_Woo_Document_Storage` para
  evitar llamadas repetidas a `wc_get_order()` durante una request.

### Compatibility
- Sin migración requerida: los getters de lectura (`get_xml_path`,
  `get_acuse_path`, `get_acuse_xml_path`, `get_pdf_path`) caen al layout
  legacy plano cuando el archivo todavía vive ahí.

## [1.24.0] - 2026-05-01

### Fixed
- `find_orphans` ahora soporta múltiples emisores (antes asumía emisor
  único y falsamente marcaba como huérfanas órdenes de otros emisores).
- Pre-flight de receptor: validación de campos requeridos antes del envío.

## [1.23.3] - 2026-05-01

### Changed
- Texto de ayuda fijo para el campo "código de actividad económica" en el
  checkout (el dinámico se desincronizaba según el cache de WC).

## [1.23.2] - 2026-05-01

### Fixed
- HPOS stale-instance bug: cuando dos paths cargaban `$order` y uno borraba
  meta, el otro hacía `UPDATE WHERE id=X` que afectaba 0 filas. La meta de
  clave/factura no persistía tras `force-reexecute`. Fix recarga tras
  `clear_invoice_data()`.

## [1.23.1] - 2026-05-01

### Fixed
- Concurrencia del lock por orden: rechazo explícito en lugar de espera
  silenciosa. Nuevo parámetro `$skip_lock` para callers que ya tienen el
  lock tomado.

## [1.23.0] - 2026-05-01

### Added
- Lock por orden alrededor de la emisión (previene doble-envío en
  condiciones de carrera).
- UI guard contra clic doble en el botón de emisión manual.
- Emission log para detectar órdenes huérfanas (clave generada pero sin
  envío a Hacienda).

## [1.22.0] - 2026-05-01

### Added
- Contador atómico de consecutivos (resuelve la race entre emisiones
  simultáneas que producía consecutivos duplicados).
- Pre-flight de validación de IVA antes de firmar.

### Fixed
- `consecutivo`: leer `LAST_INSERT_ID()` vía `SELECT`, no `$wpdb->insert_id`
  (que en HPOS retornaba el ID de la orden, no del contador).
- `preflight`: saltar líneas en cero (`tax_status none` o `subtotal=0`).
- `consecutivo`: paddear cédula a 12 dígitos en counter lookup.

## [1.21.0] - 2026-04-30

### Changed
- Bump de versión (override sobre v1.19.1) para sincronizar tag y header
  tras un release fallido.

## [1.19.1] - 2026-04-30

### Fixed
- Excluir `shop_order_refund` del CLI bulk re-encolado de `reexecute_all`.
- Eliminar `FE_Woo_CABYS_Watcher` (dead code que causaba timeout en
  pantallas de productos con muchos términos).

## [1.19.0] - 2026-04-30

### Added
- T-1: cobertura de tests pure-unit (PaymentMethod, CodigoTarifaIva,
  FacturaGenerator).
- T-3: alerta de certificado próximo a vencer.
- T-4: monitor de cola de facturación.
- T-7: fold-in de patches inline.

## [1.18.0] - 2026-04-28

### Fixed
- PDF-6 watermark TCPDF: subclase de TCPDF + override `_putinfo` Producer
  field + `tcpdflink` deshabilitado + Producer reemplazado en XMP metadata.
- PDF v2 paridad referencia: 6 hallazgos amarillos cerrados (totales nobr,
  1 línea menos en footer, etc.).

## [1.17.0] - 2026-04-28

### Added
- PDF MVP: consecutivo formal, dirección emisor, totales completos, medio
  de pago, autorización.
- PDF fallback a parent emisor cuando faltan dirección/nombre/cedula.

## [1.16.0] - 2026-04-28

### Fixed
- H-5: mapeo `MedioPago` para PowerTranz y FooEvents POS.

## [1.15.0] - 2026-04-27

### Fixed
- H-1: `UnidadMedidaComercial` requerida en líneas de servicio.
- H-2: fallback `Barrio = "Desconocido"` cuando no se puede resolver.

## [1.14.0] - 2026-04-26

### Fixed
- B-4: clave varchar overflow (la columna era VARCHAR(50), insuficiente
  para metadata extendido).
- B-5: email multi-factura bloqueante (un fallo de envío ya no detiene
  el resto).

## [1.13.0] - 2026-04-26

### Fixed
- C-1: duplicación de queue.
- C-2: items varados en estado `processing`.

## [1.12.0] - 2026-04-26

### Changed
- Desacoplar el acuse del envío en queue + multi-factura.

### Fixed
- B-1: nota terminal en cron (los logs de cron no llegaban a la nota de
  la orden).
