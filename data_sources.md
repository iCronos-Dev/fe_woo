# Documentación Técnica - Plugin fe_woo

## Descripción General

Plugin de **Facturación Electrónica** para WooCommerce que integra con el sistema del Ministerio de Hacienda de Costa Rica (v4.4).

---

## Fuentes de Datos para Generación de XML

La data para crear el XML de una factura/tiquete electrónico proviene de **3 fuentes principales**:

### 1. Configuración del Plugin (wp_options) → EMISOR

Datos de la empresa que emite la factura:

```
┌─────────────────────────────────────────────────────────────┐
│  wp_options (Configuración del plugin)                      │
├─────────────────────────────────────────────────────────────┤
│  fe_woo_cedula_juridica    →  <Identificacion><Numero>      │
│  fe_woo_company_name       →  <Nombre>                      │
│  fe_woo_economic_activity  →  <CodigoActividad>             │
│  fe_woo_province_code      →  <Ubicacion><Provincia>        │
│  fe_woo_canton_code        →  <Ubicacion><Canton>           │
│  fe_woo_district_code      →  <Ubicacion><Distrito>         │
│  fe_woo_neighborhood_code  →  <Ubicacion><Barrio>           │
│  fe_woo_address            →  <Ubicacion><OtrasSenas>       │
│  fe_woo_phone              →  <Telefono><NumTelefono>       │
│  fe_woo_email              →  <CorreoElectronico>           │
└─────────────────────────────────────────────────────────────┘
```

### 2. Order Meta (_fe_woo_*) → RECEPTOR

Datos del cliente que recibe la factura (capturados en checkout):

```
┌─────────────────────────────────────────────────────────────┐
│  Order Meta (del checkout)                                  │
├─────────────────────────────────────────────────────────────┤
│  _fe_woo_full_name      →  <Receptor><Nombre>               │
│  _fe_woo_id_type        →  <Receptor><Identificacion><Tipo> │
│  _fe_woo_id_number      →  <Receptor><Identificacion><Numero>│
│  _fe_woo_invoice_email  →  <Receptor><CorreoElectronico>    │
│  _fe_woo_phone          →  <Receptor><Telefono><NumTelefono>│
│  _fe_woo_require_factura → Determina si es Factura o Tiquete│
└─────────────────────────────────────────────────────────────┘
```

### 3. WC_Order Object → DETALLE Y RESUMEN

Datos de la orden de WooCommerce:

```
┌─────────────────────────────────────────────────────────────┐
│  WC_Order (objeto de WooCommerce)                           │
├─────────────────────────────────────────────────────────────┤
│  $order->get_id()           →  Consecutivo, Clave           │
│  $order->get_date_created() →  <FechaEmision>, Clave        │
│  $order->get_currency()     →  <CodigoMoneda>               │
│  $order->get_payment_method()→ <MedioPago>                  │
│  $order->get_subtotal()     →  <TotalVenta>                 │
│  $order->get_total_tax()    →  <TotalImpuesto>              │
│  $order->get_total()        →  <TotalComprobante>           │
│  $order->get_total_discount()→ <TotalDescuentos>            │
│  $order->get_shipping_total()→ Línea de envío               │
├─────────────────────────────────────────────────────────────┤
│  $order->get_items() → <DetalleServicio>                    │
│    │                                                        │
│    ├─ $item->get_product()                                  │
│    │    ├─ ->get_sku()        → <Codigo>                    │
│    │    ├─ ->get_id()         → <Codigo> (si no hay SKU)    │
│    │    └─ ->get_tax_class()  → Código CABYS                │
│    │                                                        │
│    ├─ $item->get_name()       → <Detalle>                   │
│    ├─ $item->get_quantity()   → <Cantidad>                  │
│    ├─ $item->get_subtotal()   → <MontoTotal>                │
│    ├─ $item->get_total()      → <SubTotal>                  │
│    └─ $item->get_total_tax()  → <Impuesto><Monto>           │
└─────────────────────────────────────────────────────────────┘
```

### 4. Código CABYS (Tax Class) → Código Comercial

```
┌─────────────────────────────────────────────────────────────┐
│  WC_Product → Tax Class → CABYS                             │
├─────────────────────────────────────────────────────────────┤
│  $product->get_tax_class()                                  │
│       ↓                                                     │
│  FE_Woo_Tax_CABYS::get_product_cabys($product)             │
│       ↓                                                     │
│  <CodigoComercial>                                          │
│    <Tipo>04</Tipo>                                          │
│    <CodigoCABYS>4910100000100</CodigoCABYS>                │
│  </CodigoComercial>                                         │
└─────────────────────────────────────────────────────────────┘
```

---

## Diagrama de Flujo de Datos

```
┌──────────────────┐     ┌──────────────────┐     ┌──────────────────┐
│   wp_options     │     │   Order Meta     │     │    WC_Order      │
│   (Emisor)       │     │   (Receptor)     │     │  (Items/Totales) │
└────────┬─────────┘     └────────┬─────────┘     └────────┬─────────┘
         │                        │                        │
         └────────────────────────┼────────────────────────┘
                                  │
                                  ▼
                    ┌─────────────────────────┐
                    │  FE_Woo_Factura_Generator│
                    │  ::generate_from_order() │
                    └────────────┬────────────┘
                                 │
                    ┌────────────┼────────────┐
                    │            │            │
                    ▼            ▼            ▼
            generate_clave() build_emisor() build_detalle_servicio()
                    │            │            │
                    └────────────┼────────────┘
                                 │
                                 ▼
                         ┌──────────────┐
                         │   XML Final  │
                         │  (DOMDocument)│
                         └──────────────┘
```

---

## Ejemplo de Mapeo Concreto

Para una orden #123 con un boleto de ferry:

| Dato en XML | Fuente | Valor Ejemplo |
|-------------|--------|---------------|
| `<Clave>` | Generado: país + fecha + cédula + orden + random | `50608022631012345670000000000001231...` |
| `<Emisor><Nombre>` | `fe_woo_company_name` | `Coonatramar R.L.` |
| `<Emisor><Numero>` | `fe_woo_cedula_juridica` | `3101234567` |
| `<Receptor><Nombre>` | `_fe_woo_full_name` | `Juan Pérez` |
| `<Receptor><Numero>` | `_fe_woo_id_number` | `123456789` |
| `<LineaDetalle><Detalle>` | `$item->get_name()` | `Adulto - Ferry Playa Naranjo` |
| `<LineaDetalle><PrecioUnitario>` | `$item->get_subtotal() / qty` | `1119.00000` |
| `<TotalComprobante>` | `$order->get_total()` | `1265.47000` |

---

## Estructura de la Clave (50 caracteres)

```
[País][Día][Mes][Año][Cédula][Consecutivo][Situación][Código Seguridad]
  3     2    2    2    12        20            1           8
```

| Campo | Longitud | Descripción |
|-------|----------|-------------|
| País | 3 | `506` (Costa Rica) |
| Día | 2 | Día de emisión |
| Mes | 2 | Mes de emisión |
| Año | 2 | Últimos 2 dígitos del año |
| Cédula | 12 | Cédula jurídica (padded con 0s) |
| Consecutivo | 20 | ID de orden (padded con 0s) |
| Situación | 1 | `1`=Normal, `2`=Contingencia, `3`=Sin internet |
| Código Seguridad | 8 | Número aleatorio |

---

## Número Consecutivo (20 caracteres)

```
[Sucursal][Terminal][Tipo Doc][Número]
    3         3         2        10
```

**Tipos de Documento:**
- `01` = Factura Electrónica
- `02` = Nota de Débito
- `03` = Nota de Crédito
- `04` = Tiquete Electrónico

---

## Metadata Completa del Plugin

### Metadata de Órdenes (Order Meta)

#### Datos del Cliente (Receptor)

| Meta Key | Tipo | Descripción |
|----------|------|-------------|
| `_fe_woo_require_factura` | `yes`/vacío | Si el cliente solicitó factura electrónica |
| `_fe_woo_id_type` | string | Tipo de identificación: `01`=Física, `02`=Jurídica, `03`=DIMEX, `04`=NITE |
| `_fe_woo_id_number` | string | Número de cédula/identificación |
| `_fe_woo_full_name` | string | Nombre completo o razón social |
| `_fe_woo_invoice_email` | email | Correo para envío de factura |
| `_fe_woo_phone` | string | Teléfono del receptor |
| `_fe_woo_activity_code` | string | Código de actividad económica (si aplica) |

#### Estado del Documento Principal

| Meta Key | Tipo | Descripción |
|----------|------|-------------|
| `_fe_woo_document_type` | `factura`/`tiquete` | Tipo de documento generado |
| `_fe_woo_factura_clave` | string (50 chars) | Clave única del documento |
| `_fe_woo_factura_xml` | text | Contenido XML del documento |
| `_fe_woo_factura_status` | `pending`/`sent`/`error` | Estado interno del envío |
| `_fe_woo_factura_sent_date` | datetime | Fecha/hora de envío |
| `_fe_woo_hacienda_status` | string | Estado de Hacienda: `aceptado`, `rechazado`, `procesando` |
| `_fe_woo_hacienda_response` | array | Respuesta completa de Hacienda |
| `_fe_woo_status_last_checked` | datetime | Última consulta de estado |

#### Archivos Generados

| Meta Key | Tipo | Descripción |
|----------|------|-------------|
| `_fe_woo_xml_file_path` | path | Ruta al XML firmado |
| `_fe_woo_pdf_file_path` | path | Ruta al PDF generado |
| `_fe_woo_acuse_file_path` | path | Ruta al acuse de Hacienda (JSON) |

#### Mensaje Receptor

| Meta Key | Tipo | Descripción |
|----------|------|-------------|
| `_fe_woo_mensaje_receptor_clave` | string (50 chars) | Clave del mensaje receptor |
| `_fe_woo_mensaje_receptor_xml` | text | Contenido XML del mensaje |
| `_fe_woo_mensaje_receptor_file_path` | path | Ruta al archivo XML |

#### Exoneraciones (Exenciones Fiscales)

| Meta Key | Tipo | Descripción |
|----------|------|-------------|
| `_fe_woo_has_exoneracion` | `yes`/`no` | Si tiene exoneración |
| `_fe_woo_exoneracion_tipo` | string | Tipo: `01`-`05`, `99` |
| `_fe_woo_exoneracion_numero` | string | Número de documento |
| `_fe_woo_exoneracion_institucion` | string | Institución emisora |
| `_fe_woo_exoneracion_fecha_emision` | date | Fecha de emisión |
| `_fe_woo_exoneracion_fecha_vencimiento` | date | Fecha de vencimiento |
| `_fe_woo_exoneracion_porcentaje` | int (0-100) | Porcentaje de exoneración |
| `_fe_woo_exoneracion_status` | string | Estado de validación |
| `_fe_woo_exoneracion_validation_errors` | array | Errores de validación |

#### Notas de Crédito/Débito

| Meta Key | Tipo | Descripción |
|----------|------|-------------|
| `_fe_woo_notas` | array | Lista de notas generadas para esta orden |
| `_fe_woo_nota_{clave}_acuse_file_path` | path | Acuse de la nota |
| `_fe_woo_nota_{clave}_mensaje_receptor_clave` | string | Clave del mensaje receptor |
| `_fe_woo_nota_{clave}_mensaje_receptor_xml` | text | XML del mensaje receptor |
| `_fe_woo_nota_{clave}_mensaje_receptor_file_path` | path | Ruta al archivo |

---

### Metadata de Usuario (usermeta)

Datos guardados en el perfil del usuario para autocompletar:

| Meta Key | Tipo | Descripción |
|----------|------|-------------|
| `fe_woo_id_type` | string | Tipo de identificación |
| `fe_woo_id_number` | string | Número de cédula |
| `fe_woo_full_name` | string | Nombre completo |
| `fe_woo_invoice_email` | email | Correo para facturas |
| `fe_woo_phone` | string | Teléfono |
| `fe_woo_activity_code` | string | Código de actividad económica |

**Nota:** También se leen `id_number` e `id_type` del tema padre (Tiquetera) si están disponibles.

---

### Opciones Globales (wp_options)

| Option Key | Descripción |
|------------|-------------|
| `fe_woo_environment` | Ambiente: `production`, `sandbox`, `local` |
| `fe_woo_cedula_juridica` | Cédula jurídica de la empresa |
| `fe_woo_company_name` | Nombre de la empresa |
| `fe_woo_api_username` | Usuario API Hacienda |
| `fe_woo_api_password` | Contraseña API Hacienda |
| `fe_woo_certificate_path` | Ruta al certificado .p12 |
| `fe_woo_certificate_pin` | PIN del certificado |
| `fe_woo_economic_activity` | Código de actividad económica |
| `fe_woo_province_code` | Código de provincia |
| `fe_woo_canton_code` | Código de cantón |
| `fe_woo_district_code` | Código de distrito |
| `fe_woo_neighborhood_code` | Código de barrio |
| `fe_woo_address` | Dirección completa |
| `fe_woo_phone` | Teléfono de la empresa |
| `fe_woo_email` | Email de la empresa |
| `fe_woo_enable_debug` | Habilitar logs de debug |
| `fe_woo_enable_checkout_form` | Mostrar formulario en checkout |
| `fe_woo_pause_processing` | Pausar procesamiento de cola |
| `fe_woo_selected_cabys_codes` | Códigos CABYS seleccionados |
| `fe_woo_mensaje_receptor_consecutive` | Consecutivo para mensajes receptor |
| `fe_woo_version` | Versión del plugin instalada |

---

## Flujo Completo de Datos

```
┌─────────────────────────────────────────────────────────────┐
│                    REGISTRO DE USUARIO                       │
│  (Tema Tiquetera)                                           │
│  └─ id_number, id_type                                      │
└─────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────┐
│              MI CUENTA > FACTURACIÓN ELECTRÓNICA            │
│  (Plugin fe_woo)                                            │
│  └─ fe_woo_id_type, fe_woo_id_number, fe_woo_full_name...  │
│                                                             │
│  * Autocomplete desde Hacienda con cédula                   │
│  * Guarda datos para futuras compras                        │
└─────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────┐
│                        CHECKOUT                              │
│  (Pre-llenado desde usermeta)                               │
│  └─ Cliente puede solicitar factura                         │
│  └─ Datos copiados a order meta: _fe_woo_*                  │
└─────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────┐
│                    ORDEN COMPLETADA                          │
│  Order Meta:                                                │
│  ├─ _fe_woo_require_factura = "yes"                         │
│  ├─ _fe_woo_id_type, _fe_woo_id_number, _fe_woo_full_name  │
│  └─ (datos del receptor para el XML)                        │
└─────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────┐
│                 PROCESAMIENTO DE COLA                        │
│  Order Meta actualizado:                                    │
│  ├─ _fe_woo_factura_clave                                   │
│  ├─ _fe_woo_factura_status = "sent"                         │
│  ├─ _fe_woo_hacienda_status = "aceptado"                    │
│  ├─ _fe_woo_xml_file_path                                   │
│  ├─ _fe_woo_pdf_file_path                                   │
│  └─ _fe_woo_mensaje_receptor_*                              │
└─────────────────────────────────────────────────────────────┘
```

---

## Componentes del Plugin

| Clase | Archivo | Función |
|-------|---------|---------|
| `FE_Woo_Hacienda_Config` | `class-fe-woo-hacienda-config.php` | Configuración del API |
| `FE_Woo_Factura_Generator` | `class-fe-woo-factura-generator.php` | Genera XML de facturas/tiquetes |
| `FE_Woo_API_Client` | `class-fe-woo-api-client.php` | Comunicación con Hacienda |
| `FE_Woo_Certificate_Handler` | `class-fe-woo-certificate-handler.php` | Manejo de certificados .p12 |
| `FE_Woo_Queue` | `class-fe-woo-queue.php` | Sistema de cola |
| `FE_Woo_Queue_Processor` | `class-fe-woo-queue-processor.php` | Procesamiento de cola (cron) |
| `FE_Woo_Document_Storage` | `class-fe-woo-document-storage.php` | Almacenamiento de archivos |
| `FE_Woo_PDF_Generator` | `class-fe-woo-pdf-generator.php` | Genera PDFs (dompdf) |
| `FE_Woo_Exoneracion` | `class-fe-woo-exoneracion.php` | Exenciones fiscales |
| `FE_Woo_Mensaje_Receptor` | `class-fe-woo-mensaje-receptor.php` | Mensajes de aceptación/rechazo |
| `FE_Woo_Tax_CABYS` | `class-fe-woo-tax-cabys.php` | Integración códigos CABYS |
| `FE_Woo_Checkout` | `class-fe-woo-checkout.php` | Campos de checkout |
| `FE_Woo_My_Account` | `class-fe-woo-my-account.php` | Página Mi Cuenta |
| `FE_Woo_Order_Admin` | `class-fe-woo-order-admin.php` | Metabox en admin |
| `FE_Woo_REST_API` | `class-fe-woo-rest-api.php` | Endpoints mock (desarrollo) |
| `FE_Woo_Settings` | `class-fe-woo-settings.php` | Página de configuración |
| `FE_Woo_CABYS_Watcher` | `class-fe-woo-cabys-watcher.php` | Monitor de cambios CABYS |

---

## Códigos de Referencia

### Tipos de Identificación
| Código | Descripción |
|--------|-------------|
| `01` | Cédula Física |
| `02` | Cédula Jurídica |
| `03` | DIMEX |
| `04` | NITE |

### Condición de Venta
| Código | Descripción |
|--------|-------------|
| `01` | Contado |
| `02` | Crédito |
| `03` | Consignación |
| `04` | Apartado |
| `05` | Arrendamiento |
| `99` | Otros |

### Medio de Pago
| Código | Descripción |
|--------|-------------|
| `01` | Efectivo |
| `02` | Tarjeta |
| `03` | Cheque |
| `04` | Transferencia |
| `99` | Otros |

### Tipos de Exoneración
| Código | Descripción |
|--------|-------------|
| `01` | Compras autorizadas |
| `02` | Ventas exentas a diplomáticos |
| `03` | Donaciones |
| `04` | Incentivos |
| `05` | Zona Franca |
| `99` | Otros |

### Códigos de Referencia (Notas)
| Código | Descripción |
|--------|-------------|
| `01` | Anula documento de referencia |
| `02` | Corrige texto |
| `03` | Corrige monto |
| `04` | Referencia a otro documento |
| `05` | Sustituye por contingencia |
| `99` | Otros |
