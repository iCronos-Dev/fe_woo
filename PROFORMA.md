# Funcionalidad de Proforma

## Descripción General

Este plugin ahora incluye soporte completo para órdenes en estado "Proforma". Una proforma es una cotización preliminar que **no genera factura electrónica** hasta que se confirme el pago y la orden cambie a un estado pagado (como "Completado" o "Procesando").

## Características Principales

### 1. Estado Personalizado "Proforma"

- **Estado registrado**: `wc-proforma`
- **Etiqueta en español**: "Proforma"
- **Ubicación**: Aparece después del estado "Pendiente" en la lista de estados de órdenes
- **Disponible en**: Dropdown de estados, acciones masivas (bulk actions)

### 2. Exclusión de la Cola de Facturación

**Comportamiento Clave**:
- Las órdenes en estado "Proforma" **NO ingresan** a la cola de facturación electrónica
- No se genera ninguna FE mientras la orden permanezca en este estado
- Esto se implementa mediante un filtro `fe_woo_should_add_order_to_queue`

**Implementación Técnica**:
```php
// En class-fe-woo-proforma.php
public static function exclude_proforma_from_queue($should_add, $order) {
    if ($order->get_status() === self::STATUS_SLUG) {
        return false; // No agregar a la cola
    }
    return $should_add;
}
```

### 3. Conversión a Factura Electrónica

**Proceso de Conversión**:
1. Un administrador cambia manualmente el estado de "Proforma" a "Completado" o "Procesando"
2. Se activa el hook `woocommerce_order_status_changed`
3. La clase `FE_Woo_Proforma` detecta la transición desde "Proforma"
4. La orden se agrega automáticamente a la cola de facturación
5. El procesador de cola genera la FE según los lineamientos de Hacienda

**Código de Implementación**:
```php
// En class-fe-woo-proforma.php
public static function handle_proforma_to_paid_transition($order_id, $old_status, $new_status, $order) {
    // Solo procesar si viene DESDE proforma
    if ($old_status !== self::STATUS_SLUG) {
        return;
    }

    // Solo procesar si va HACIA un estado pagado
    $paid_statuses = ['processing', 'completed'];
    if (!in_array($new_status, $paid_statuses, true)) {
        return;
    }

    // Agregar a la cola de facturación
    if (class_exists('FE_Woo_Queue') && !FE_Woo_Queue::order_exists_in_queue($order_id)) {
        FE_Woo_Queue::add_order_to_queue($order_id);
    }
}
```

### 4. Email de Proforma

**Configuración del Email**:
- **ID del Email**: `customer_proforma`
- **Tipo**: Email al cliente
- **Ubicación de configuración**: WooCommerce > Configuración > Emails > Proforma

**Triggers (Disparadores)**:
El email se envía automáticamente cuando una orden cambia a estado "Proforma" desde:
- Pendiente
- En espera
- Fallido
- Cancelado
- O cuando se crea directamente en estado Proforma

**Personalización**:
- **Asunto por defecto**: "Proforma de su pedido en {site_title}"
- **Encabezado por defecto**: "Gracias por su pedido"
- **Contenido adicional**: Mensaje explicando que es una proforma y que la FE se generará tras el pago

**Templates**:
- HTML: `/templates/emails/customer-proforma.php`
- Texto plano: `/templates/emails/plain/customer-proforma.php`

Los templates pueden ser sobrescritos copiándolos al tema:
- `tu-tema/woocommerce/emails/customer-proforma.php`
- `tu-tema/woocommerce/emails/plain/customer-proforma.php`

## Flujo de Trabajo Completo

### Escenario 1: Crear una Proforma

1. **Crear orden manualmente** o recibir una orden del sitio
2. **Cambiar estado** a "Proforma" desde el editor de órdenes
3. **Email automático** se envía al cliente con los detalles de la proforma
4. **Sin facturación**: La orden NO entra a la cola de FE
5. **Cotización válida**: El cliente recibe una cotización oficial sin FE

### Escenario 2: Convertir Proforma a Factura

1. **Cliente confirma pago** (fuera del sistema o mediante método de pago)
2. **Administrador cambia estado** de "Proforma" a "Completado"
3. **Entrada automática a cola**: La orden se agrega a la cola de facturación
4. **Procesamiento**: El cron job procesa la cola (cada hora por defecto)
5. **Generación de FE**: Se genera y envía la factura electrónica a Hacienda
6. **Email de confirmación**: Cliente recibe email de orden completada con FE

### Escenario 3: Cancelar una Proforma

1. **Cambiar estado** de "Proforma" a "Cancelado" o "Fallido"
2. **Sin facturación**: No se genera ninguna FE
3. **Registro limpio**: La orden nunca entra a la cola de facturación

## Archivos Modificados/Creados

### Archivos Nuevos

1. **`includes/class-fe-woo-proforma.php`**
   - Clase principal de gestión de proformas
   - Registro del estado personalizado
   - Lógica de exclusión de cola
   - Manejo de transiciones de estado

2. **`includes/emails/class-wc-proforma-email.php`**
   - Clase de email extendiendo `WC_Email`
   - Configuración de triggers y placeholders
   - Definición de asunto y encabezado por defecto

3. **`templates/emails/customer-proforma.php`**
   - Template HTML del email de proforma
   - Usa hooks estándar de WooCommerce

4. **`templates/emails/plain/customer-proforma.php`**
   - Template texto plano del email de proforma
   - Versión sin formato HTML

### Archivos Modificados

1. **`fe_woo.php`** (líneas 176-177, 213-214)
   - Agregado require_once para clase de proforma
   - Agregado FE_Woo_Proforma::init()

2. **`includes/class-fe-woo-queue.php`** (líneas 106-122)
   - Agregado filtro `fe_woo_should_add_order_to_queue`
   - Logging cuando una orden es excluida

## Configuración Adicional

### Ajustes del Email en WooCommerce

Para configurar el email de proforma:

1. Ir a: **WooCommerce > Configuración > Emails**
2. Buscar: **"Proforma"** en la lista de emails
3. Hacer clic para configurar:
   - Habilitar/Deshabilitar
   - Asunto personalizado
   - Encabezado personalizado
   - Contenido adicional
   - Tipo de email (HTML, texto plano, o ambos)

### Debug y Logging

El plugin registra eventos cuando el debug está habilitado en la configuración de FE Woo:

```php
// Orden excluida de la cola
'Order #123 excluded from queue (status: proforma)'

// Orden agregada a la cola tras conversión
'Order #123 transitioned from proforma to completed - added to invoice queue'
```

## API para Desarrolladores

### Verificar si una orden es Proforma

```php
// Usando el método estático
$is_proforma = FE_Woo_Proforma::is_proforma($order_id);

// O con objeto de orden
$order = wc_get_order($order_id);
$is_proforma = FE_Woo_Proforma::is_proforma($order);

// O directamente verificando el estado
$order = wc_get_order($order_id);
if ($order->get_status() === 'proforma') {
    // Es una proforma
}
```

### Filtrar si una orden debe entrar a la cola

```php
add_filter('fe_woo_should_add_order_to_queue', function($should_add, $order) {
    // Tu lógica personalizada
    if (alguna_condicion($order)) {
        return false; // No agregar a la cola
    }
    return $should_add;
}, 10, 2);
```

### Programáticamente cambiar a Proforma

```php
$order = wc_get_order($order_id);
$order->update_status('proforma', __('Cambiado a proforma manualmente', 'tu-plugin'));
```

## Consideraciones de Seguridad

1. **Permisos**: Solo usuarios con capacidad `edit_shop_orders` pueden cambiar estados
2. **Auditoría**: Todos los cambios de estado quedan registrados en las notas de la orden
3. **Logging**: Los eventos se registran si el debug está habilitado
4. **Validación**: Se verifica que la orden exista antes de procesarla

## Compatibilidad

- **WooCommerce**: 5.0+
- **WordPress**: 5.8+
- **PHP**: 7.4+
- **Factura Electrónica Costa Rica**: v4.4
- **HPOS**: Compatible con High-Performance Order Storage

## Preguntas Frecuentes

### ¿Puedo crear una orden directamente en estado Proforma?

Sí, al crear una orden manual en el admin, puedes seleccionar "Proforma" como estado inicial.

### ¿Qué pasa si cambio de Proforma a un estado que no es "Completado"?

La orden NO entrará a la cola de facturación. Solo los cambios a "Completado" o "Procesando" activan la generación de FE.

### ¿Puedo personalizar el email de proforma?

Sí, puedes:
1. Modificar el asunto y contenido en WooCommerce > Configuración > Emails
2. Copiar los templates a tu tema y personalizarlos completamente

### ¿Se puede revertir una proforma a otro estado?

Sí, puedes cambiar de Proforma a cualquier otro estado. Si es a un estado no pagado, no se generará FE.

### ¿Las proformas aparecen en los reportes?

Sí, aparecen en los reportes de órdenes con su estado "Proforma" claramente identificado.

## Solución de Problemas

### El estado Proforma no aparece

1. Verificar que el plugin FE Woo esté activado
2. Limpiar caché (WP y browser)
3. Verificar permisos de usuario
4. Revisar logs por errores PHP

### El email de proforma no se envía

1. Verificar que el email esté habilitado en WooCommerce > Configuración > Emails
2. Comprobar que la dirección de email del cliente sea válida
3. Revisar logs de email de WordPress
4. Verificar configuración SMTP si aplica

### La orden no entra a la cola tras cambiar a Completado

1. Verificar que la orden VENÍA del estado "proforma"
2. Revisar logs con debug habilitado
3. Verificar que el cron job esté funcionando: `wp cron event list`
4. Comprobar que la tabla de cola exista en la base de datos

## Soporte y Contribuciones

Para reportar bugs o solicitar features, contacta al equipo de desarrollo.

## Changelog

### Versión 1.0.0 (2026-02-09)
- ✨ Implementación inicial del estado Proforma
- ✨ Exclusión automática de cola de facturación
- ✨ Conversión automática a FE al cambiar a Completado
- ✨ Email personalizado para proformas
- 📝 Documentación completa en español
