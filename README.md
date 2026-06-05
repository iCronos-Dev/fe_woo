# FE WooCommerce - Hacienda Integration

WordPress plugin for integrating WooCommerce with Costa Rica's Ministerio de Hacienda electronic invoicing system (Facturación Electrónica).

## Overview

This plugin provides a complete solution for managing electronic invoices through the Hacienda API, including:

- Full configuration interface for Hacienda API credentials
- Cryptographic certificate management (.p12/.pfx)
- API client for sending and querying invoices
- Connection testing and validation
- Environment management (Production/Sandbox)

## Architecture

The plugin follows an object-oriented architecture with separate concerns:

### Core Classes

#### 1. `FE_Woo_Hacienda_Config`
**Location:** `includes/class-fe-woo-hacienda-config.php`

Central configuration management class that handles all Hacienda API settings.

**Key Features:**
- Environment management (Production/Sandbox)
- Company information storage
- API credentials management
- Configuration validation
- Export/import functionality

**Example Usage:**
```php
// Get current environment
$env = FE_Woo_Hacienda_Config::get_environment();

// Get company identification
$cedula = FE_Woo_Hacienda_Config::get_cedula_juridica();

// Validate configuration
$errors = FE_Woo_Hacienda_Config::validate_configuration();
if (empty($errors)) {
    // Configuration is valid
}

// Get all configuration
$config = FE_Woo_Hacienda_Config::get_all_config();
```

#### 2. `FE_Woo_Certificate_Handler`
**Location:** `includes/class-fe-woo-certificate-handler.php`

Manages cryptographic certificate operations required for Hacienda API authentication.

**Key Features:**
- Secure certificate upload and storage
- Certificate validation with PIN
- Certificate expiration monitoring
- Certificate information extraction
- Secure file permissions (0600)

**Example Usage:**
```php
// Upload certificate
$result = FE_Woo_Certificate_Handler::upload_certificate($_FILES['cert']);

// Verify certificate
$verification = FE_Woo_Certificate_Handler::verify_certificate($cert_path, $pin);

// Get certificate status
$status = FE_Woo_Certificate_Handler::get_status();

// Get certificate information
$info = FE_Woo_Certificate_Handler::get_certificate_info($cert_path, $pin);
```

#### 3. `FE_Woo_API_Client`
**Location:** `includes/class-fe-woo-api-client.php`

Handles all API communications with Hacienda's electronic invoicing system.

**Key Features:**
- Connection testing
- Invoice submission
- Invoice status queries
- Automatic authentication
- Request/response logging (when debug enabled)
- Invoice key generation

**Example Usage:**
```php
$api_client = new FE_Woo_API_Client();

// Test connection
$result = $api_client->test_connection();

// Send invoice
$result = $api_client->send_invoice($xml_data);

// Query invoice status
$result = $api_client->query_invoice_status($invoice_key);
```

#### 4. `FE_Woo_Settings`
**Location:** `includes/class-fe-woo-settings.php`

Manages the WooCommerce settings tab interface for configuration.

**Key Features:**
- Comprehensive configuration form
- Custom certificate upload field
- Real-time connection testing
- Configuration validation display
- Form validation

## Configuration Form

The plugin adds a "FE Settings" tab to WooCommerce settings with the following sections:

### 1. Environment Configuration
- **Environment:** Select Production or Sandbox
- **Debug Logging:** Enable detailed API logging

### 2. Company Information
- **Cédula Jurídica:** Company legal ID (numbers only)
- **Company Name:** Official registered name
- **Economic Activity Code:** Primary activity code

### 3. Location Information
- **Province Code:** Province code (e.g., 1 for San José)
- **Canton Code:** Canton within province
- **District Code:** District within canton
- **Neighborhood Code:** Optional neighborhood code
- **Address:** Complete company address

### 4. Contact Information
- **Phone Number:** Company phone
- **Email:** Company email address

### 5. Cryptographic Certificate
- **Certificate File:** Upload .p12 or .pfx file
- **Certificate PIN:** Certificate password
- **Status Display:** Shows certificate validity and expiration

### 6. Hacienda API Credentials
- **API Username:** ATV API username
- **API Password:** ATV API password

### 7. Connection Status
- **Configuration Validation:** Shows missing fields
- **Test Connection:** Button to test API connectivity

## Installation

1. Upload the plugin to `/wp-content/plugins/fe_woo/`
2. Ensure WooCommerce is installed and activated
3. Activate the plugin through WordPress admin
4. Navigate to WooCommerce → Settings → FE Settings
5. Configure all required fields

## Configuration Steps

### 1. Select Environment
Start with **Sandbox** for testing, switch to **Production** when ready for live invoices.

### 2. Enter Company Information
- Enter your Cédula Jurídica (numbers only)
- Enter your official company name
- Enter your economic activity code

### 3. Configure Location
Enter your location codes according to Hacienda's territorial division:
- Province (Provincia)
- Canton
- District (Distrito)
- Neighborhood (Barrio) - optional

### 4. Upload Cryptographic Certificate
1. Upload your .p12 or .pfx certificate file (max 5MB)
2. Enter the certificate PIN/password
3. Verify the certificate status shows as "Valid"

### 5. Enter API Credentials
- Enter your ATV API username
- Enter your ATV API password

### 6. Test Connection
Click "Test Connection" to verify your configuration is correct and can communicate with Hacienda.

## API Endpoints

The plugin automatically selects endpoints based on environment:

### Production
- **Reception:** `https://api.comprobanteselectronicos.go.cr/recepcion/v1`
- **Consultation:** `https://api.comprobanteselectronicos.go.cr/consulta/v1`

### Sandbox
- **Reception:** `https://api-sandbox.comprobanteselectronicos.go.cr/recepcion/v1`
- **Consultation:** `https://api-sandbox.comprobanteselectronicos.go.cr/consulta/v1`

## Security Features

### Certificate Storage
- Certificates stored in secure upload directory
- Protected by .htaccess (blocks direct access)
- File permissions set to 0600
- Unique filenames prevent conflicts

### Credential Storage
- Passwords stored using WordPress options API
- Sensitive data redacted from logs
- Certificate PIN stored securely

### Validation
- Certificate validity checked before use
- Certificate expiration monitoring
- Form validation on submit
- AJAX nonce verification

## Debug Logging

When debug logging is enabled:

1. Navigate to WooCommerce → Status → Logs
2. Select the `fe-woo-api` log file
3. View detailed request/response information

**Note:** Sensitive data (passwords, tokens) is redacted from logs.

## Developer Hooks

### Filters

```php
// Modify settings array
add_filter('fe_woo_settings', function($settings) {
    // Modify $settings
    return $settings;
});
```

### Configuration Access

```php
// Check if configured
if (FE_Woo_Hacienda_Config::is_configured()) {
    // All required settings are configured
}

// Get specific settings
$cedula = FE_Woo_Hacienda_Config::get_cedula_juridica();
$env = FE_Woo_Hacienda_Config::get_environment();

// Get location codes
$location = FE_Woo_Hacienda_Config::get_location_codes();
```

## Requirements

- WordPress 5.8 or higher
- PHP 7.4 or higher
- WooCommerce 5.0 or higher
- PHP OpenSSL extension (for certificate handling)
- PHP cURL extension (for API requests)

## File Structure

```
fe_woo/
├── fe_woo.php                                    # Main plugin file
├── README.md                                      # This file
├── includes/
│   ├── class-fe-woo-hacienda-config.php          # Configuration management
│   ├── class-fe-woo-certificate-handler.php      # Certificate operations
│   ├── class-fe-woo-api-client.php               # API communication
│   └── class-fe-woo-settings.php                 # Settings interface
└── assets/
    ├── css/
    │   └── admin.css                             # Admin styles
    └── js/
        └── admin.js                              # Admin JavaScript
```

## Support

For issues or questions:
1. Check WooCommerce logs for API errors
2. Verify all required fields are configured
3. Test connection using the "Test Connection" button
4. Ensure certificate is valid and not expired

## License

This plugin is part of a WordPress/WooCommerce integration project.

## Releasing

The plugin commits its `vendor/` directory so that consumers (e.g. Bedrock
projects) can install it as a Composer dist `zip` without having to run
`composer install` inside the plugin. The committed `vendor/` MUST contain
runtime dependencies only — dev packages (PHPUnit and friends) are excluded
via `.gitignore` and would otherwise leave the autoload pointing at files
that don't exist on disk.

Before tagging a new version, always regenerate the autoload without dev:

```bash
composer release-vendor   # alias for: composer install --no-dev --optimize-autoloader
git add vendor/ composer.json composer.lock fe_woo.php CHANGELOG.md
git commit -m "VERSION X.Y.Z"
git tag vX.Y.Z
git push origin main --tags
```

After pushing the tag, bump the consumer (Bedrock root):

```bash
composer update fe-woo/hacienda-integration --with-dependencies
```

> **Why this matters:** v1.26.0 shipped with a `vendor/composer/autoload_*.php`
> generated with `--dev`. Because `.gitignore` excludes the dev package
> directories, every consumer installation crashed at bootstrap with
> `Failed opening required '.../myclabs/deep-copy/.../deep_copy.php'`. The
> `release-vendor` script exists to prevent that regression.

## Credits

Developed for integration with Costa Rica's Ministerio de Hacienda electronic invoicing system (ATV).
