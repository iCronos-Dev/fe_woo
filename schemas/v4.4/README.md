# Hacienda Costa Rica — XSDs v4.4

Este directorio debe contener los esquemas XSD oficiales de la Factura Electrónica v4.4
publicados por el Ministerio de Hacienda de Costa Rica. **No se incluyen en el repositorio
por política de redistribución**: descárgalos directamente desde una fuente oficial y
cómmiteálos aquí.

## Archivos esperados

Coloca los siguientes archivos en este directorio con el nombre exacto que espera
`FE_Woo_XML_Validator` (ver `includes/class-fe-woo-xml-validator.php`):

- `FacturaElectronica_V4.4.xsd`
- `TiqueteElectronico_V4.4.xsd`
- `NotaCreditoElectronica_V4.4.xsd`
- `NotaDebitoElectronica_V4.4.xsd`

Y los esquemas dependientes que referencien (tipos comunes, firma XAdES, etc.).

## Fuentes oficiales

1. **CDN de Hacienda** (misma URL que aparece en los `xmlns` de los documentos generados):
   - https://cdn.comprobanteselectronicos.go.cr/xml-schemas/v4.4/facturaElectronica.xsd
   - https://cdn.comprobanteselectronicos.go.cr/xml-schemas/v4.4/tiqueteElectronico.xsd
   - https://cdn.comprobanteselectronicos.go.cr/xml-schemas/v4.4/notaCreditoElectronica.xsd
   - https://cdn.comprobanteselectronicos.go.cr/xml-schemas/v4.4/notaDebitoElectronica.xsd

2. **Portal ATV / sitio oficial**: https://www.hacienda.go.cr/ → sección
   "Factura Electrónica" → "Esquemas XSD v4.4". El portal publica un ZIP con los XSD
   y un README de versión.

Verifica siempre la vigencia de los archivos contra el anuncio oficial antes de
subirlos a producción.

## Rutas relativas dentro de los XSDs

Si los XSDs descargados hacen `<xs:import schemaLocation="...">` apuntando a URLs
absolutas del CDN, ajústalos a rutas relativas a este directorio para que
`DOMDocument::schemaValidate()` funcione offline.

## Firma XAdES

Este plugin todavía no firma los XML antes de enviarlos. Si los XSDs declaran el
nodo `Firma` como obligatorio (`minOccurs="1"`), la validación previa al envío
fallará hasta que se implemente la firma. En ese caso, o bien se firma primero
y luego se valida, o bien se usan XSDs con el nodo `Firma` marcado como opcional
mientras se completa esa integración.
