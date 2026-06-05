<?php
/**
 * FE WooCommerce XML Signer
 *
 * XAdES-EPES signer for Costa Rica Hacienda v4.4 electronic invoices.
 *
 * Builds an enveloped XML-DSig signature with the XAdES-EPES qualifying
 * properties required by Resolución DGT-R-48-2016 (SigningTime,
 * SigningCertificate, SignaturePolicyIdentifier) and inserts it as the last
 * child of the document root.
 *
 * @package FE_Woo
 */

if (!defined('ABSPATH')) {
    exit;
}

use RobRichards\XMLSecLibs\XMLSecurityDSig;
use RobRichards\XMLSecLibs\XMLSecurityKey;

class FE_Woo_XML_Signer {

    const DS_NS    = 'http://www.w3.org/2000/09/xmldsig#';
    const XADES_NS = 'http://uri.etsi.org/01903/v1.3.2#';

    /**
     * Hacienda CR v4.4 signature policy: the "policy identifier" is the
     * schema URI of the document being signed, and the policy digest is
     * the SHA-1 of that Identifier element canonicalized with EXC-C14N.
     *
     * This matches the xadesjs-based signer used by other CR v4.4
     * integrations (e.g. Servora). It replaces the older pattern where
     * a PDF (DGT-R-48-2016) was hashed directly with SHA-256 — that PDF
     * is obsolete for v4.4, no longer at a stable URL, and its hash
     * drifts every time Hacienda re-publishes the document.
     */
    const SCHEMA_BASE = 'https://cdn.comprobanteselectronicos.go.cr/xml-schemas/v4.4/';

    /**
     * Sign an XML document (v4.4 factura/tiquete/nota) with XAdES-EPES.
     *
     * @param string $xml Unsigned XML.
     * @param string $cert_path Absolute path to the .p12/.pfx.
     * @param string $pin PIN (plaintext).
     * @return string Signed XML.
     * @throws Exception On any signing failure.
     */
    public static function sign($xml, $cert_path, $pin) {
        $material = FE_Woo_Certificate_Handler::load_key_material($cert_path, $pin);

        $doc = new DOMDocument();
        $doc->preserveWhiteSpace = true;
        $doc->formatOutput = false;
        if (!$doc->loadXML($xml, LIBXML_NONET)) {
            throw new Exception('XML malformado al iniciar la firma.');
        }
        if ($doc->doctype !== null) {
            throw new Exception('DOCTYPE no permitido en XML a firmar.');
        }
        $root = $doc->documentElement;
        if (!$root) {
            throw new Exception('XML sin elemento raíz.');
        }

        $sig_uuid        = self::uuid_v4();
        $signature_id    = 'xmldsig-' . $sig_uuid;
        $signed_props_id = $signature_id . '-signedprops';
        $keyinfo_id      = $signature_id . '-keyinfo';

        $policy_url = self::policy_url_for_root($root->localName);
        $policy_hash_b64 = self::compute_policy_hash($policy_url);

        // Cert fingerprint + issuer/serial from the loaded x509.
        $cert_der = self::pem_to_der($material['cert']);
        $cert_digest_b64 = base64_encode(hash('sha256', $cert_der, true));
        $cert_b64 = base64_encode($cert_der);
        $x509 = $material['x509_parsed'];
        $issuer_name = self::issuer_name_string($x509);
        $serial_number = isset($x509['serialNumber']) ? (string) $x509['serialNumber'] : '0';

        $signing_time = gmdate('Y-m-d\TH:i:s\Z');

        // Build the full <ds:Signature> as a raw XML string. We build it by
        // hand because xmlseclibs + PHP DOM re-inject xmlns:ds on every
        // descendant of SignedProperties, which changes the canonical form
        // Hacienda recomputes and causes "referencia a los datos firmados
        // no existe o es incorrecta". Constructing it as a string keeps the
        // xmlns layout deterministic; we then hash and sign the exact bytes
        // that will end up in the serialized document.
        $signature_xml = self::build_signature_template(
            $signature_id,
            $signed_props_id,
            $keyinfo_id,
            $cert_b64,
            $cert_digest_b64,
            $issuer_name,
            $serial_number,
            $signing_time,
            $policy_url,
            $policy_hash_b64
        );

        // Parse the Signature template in a standalone doc and import it.
        // DOMDocumentFragment::appendXML doesn't handle xmlns prefixes
        // declared inside the fragment; loadXML does.
        $sig_doc = new DOMDocument();
        $sig_doc->preserveWhiteSpace = true;
        $sig_doc->formatOutput = false;
        if (!$sig_doc->loadXML($signature_xml, LIBXML_NONET)) {
            throw new Exception('No se pudo construir el bloque ds:Signature.');
        }
        $imported = $doc->importNode($sig_doc->documentElement, true);
        $root->appendChild($imported);

        // Compute digests against the final serialized form of each subtree.
        // Reference 1: the enveloped document with ds:Signature temporarily
        //              removed (enveloped-signature transform), then inclusive-C14N.
        // Reference 2: SignedProperties as it appears in the final doc,
        //              exclusive-C14N so ancestor xmlns (xades, xsi...) are stripped.
        $ref1_digest = self::digest_enveloped_document($doc);
        $ref2_digest = self::digest_signed_properties($doc);

        // Inject the two DigestValues into the SignedInfo.
        self::inject_digest_values($doc, $signed_props_id, $ref1_digest, $ref2_digest);

        // Now that SignedInfo has its final DigestValues, canonicalize it
        // (inclusive C14N as declared in CanonicalizationMethod) and sign
        // with the private key.
        $si_canonical = self::canonicalize_signed_info($doc);

        $pkey = openssl_pkey_get_private($material['pkey']);
        if (!$pkey) {
            throw new Exception('Clave privada no cargable.');
        }
        $signature_bytes = '';
        if (!openssl_sign($si_canonical, $signature_bytes, $pkey, OPENSSL_ALGO_SHA256)) {
            throw new Exception('openssl_sign falló.');
        }
        $sig_value_b64 = base64_encode($signature_bytes);

        // Inject SignatureValue into ds:Signature.
        self::inject_signature_value($doc, $sig_value_b64);

        $signed_xml = $doc->saveXML();

        if (isset($material['pkey']) && is_string($material['pkey'])) {
            $material['pkey'] = str_repeat("\0", strlen($material['pkey']));
        }
        unset($material);

        return $signed_xml;
    }

    /**
     * Build ds:Signature as a raw XML string with empty DigestValue and
     * SignatureValue placeholders. We fill those in after computing
     * digests against the serialized document.
     *
     * Structure follows XAdES-EPES v1.3.2 + Hacienda CR v4.4 policy,
     * mirroring what xadesjs (Servora) produces:
     *   - Inclusive C14N on SignedInfo
     *   - Enveloped transform on Reference 1 (the document)
     *   - Exclusive C14N on Reference 2 (SignedProperties) so the digest
     *     doesn't pick up ancestor xmlns from FacturaElectronica
     *   - ClaimedRole=ObligadoTributario
     *   - SigPolicyId.Identifier = schema URL (OIDAsURI), SHA-1 policy hash
     */
    private static function build_signature_template(
        $signature_id,
        $signed_props_id,
        $keyinfo_id,
        $cert_b64,
        $cert_digest_b64,
        $issuer_name,
        $serial_number,
        $signing_time,
        $policy_url,
        $policy_hash_b64
    ) {
        $ds  = 'http://www.w3.org/2000/09/xmldsig#';
        $xds = 'http://uri.etsi.org/01903/v1.3.2#';

        $c14n_inclusive = 'http://www.w3.org/TR/2001/REC-xml-c14n-20010315';
        $c14n_exclusive = 'http://www.w3.org/2001/10/xml-exc-c14n#';
        $sha256 = 'http://www.w3.org/2001/04/xmlenc#sha256';
        $sha1 = 'http://www.w3.org/2000/09/xmldsig#sha1';
        $rsa_sha256 = 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256';
        $enveloped = 'http://www.w3.org/2000/09/xmldsig#enveloped-signature';
        $signed_props_type = 'http://uri.etsi.org/01903#SignedProperties';

        $e = function ($s) { return htmlspecialchars((string) $s, ENT_XML1 | ENT_QUOTES, 'UTF-8'); };

        return <<<XML
<ds:Signature xmlns:ds="{$ds}" Id="{$signature_id}">
<ds:SignedInfo>
<ds:CanonicalizationMethod Algorithm="{$c14n_inclusive}"/>
<ds:SignatureMethod Algorithm="{$rsa_sha256}"/>
<ds:Reference Id="Reference-{$signature_id}" URI=""><ds:Transforms><ds:Transform Algorithm="{$enveloped}"/></ds:Transforms><ds:DigestMethod Algorithm="{$sha256}"/><ds:DigestValue></ds:DigestValue></ds:Reference>
<ds:Reference Type="{$signed_props_type}" URI="#{$signed_props_id}"><ds:Transforms><ds:Transform Algorithm="{$c14n_exclusive}"/></ds:Transforms><ds:DigestMethod Algorithm="{$sha256}"/><ds:DigestValue></ds:DigestValue></ds:Reference>
</ds:SignedInfo>
<ds:SignatureValue></ds:SignatureValue>
<ds:KeyInfo Id="{$keyinfo_id}"><ds:X509Data><ds:X509Certificate>{$cert_b64}</ds:X509Certificate></ds:X509Data></ds:KeyInfo>
<ds:Object><xades:QualifyingProperties xmlns:xades="{$xds}" Target="#{$signature_id}"><xades:SignedProperties Id="{$signed_props_id}"><xades:SignedSignatureProperties><xades:SigningTime>{$signing_time}</xades:SigningTime><xades:SigningCertificate><xades:Cert><xades:CertDigest><ds:DigestMethod Algorithm="{$sha256}"/><ds:DigestValue>{$cert_digest_b64}</ds:DigestValue></xades:CertDigest><xades:IssuerSerial><ds:X509IssuerName>{$e($issuer_name)}</ds:X509IssuerName><ds:X509SerialNumber>{$e($serial_number)}</ds:X509SerialNumber></xades:IssuerSerial></xades:Cert></xades:SigningCertificate><xades:SignaturePolicyIdentifier><xades:SignaturePolicyId><xades:SigPolicyId><xades:Identifier Qualifier="OIDAsURI">{$e($policy_url)}</xades:Identifier></xades:SigPolicyId><xades:SigPolicyHash><ds:DigestMethod Algorithm="{$sha1}"/><ds:DigestValue>{$policy_hash_b64}</ds:DigestValue></xades:SigPolicyHash></xades:SignaturePolicyId></xades:SignaturePolicyIdentifier><xades:SignerRole><xades:ClaimedRoles><xades:ClaimedRole>ObligadoTributario</xades:ClaimedRole></xades:ClaimedRoles></xades:SignerRole></xades:SignedSignatureProperties></xades:SignedProperties></xades:QualifyingProperties></ds:Object>
</ds:Signature>
XML;
    }

    /**
     * Compute Reference 1 digest: inclusive-C14N of the document with the
     * ds:Signature element removed (enveloped-signature transform), SHA-256.
     */
    private static function digest_enveloped_document(DOMDocument $doc) {
        // Clone the document, remove the signature, canonicalize.
        $clone = new DOMDocument();
        $clone->preserveWhiteSpace = true;
        $clone->formatOutput = false;
        $clone->loadXML($doc->saveXML(), LIBXML_NONET);

        $sigs = $clone->getElementsByTagNameNS(self::DS_NS, 'Signature');
        while ($sigs->length > 0) {
            $sigs->item(0)->parentNode->removeChild($sigs->item(0));
        }

        $canonical = $clone->C14N(false, false);
        return base64_encode(hash('sha256', $canonical, true));
    }

    /**
     * Compute Reference 2 digest: exclusive-C14N of <xades:SignedProperties>
     * as it lives in the final document, SHA-256.
     */
    private static function digest_signed_properties(DOMDocument $doc) {
        $xp = new DOMXPath($doc);
        $xp->registerNamespace('xades', self::XADES_NS);
        $sp = $xp->query('//xades:SignedProperties')->item(0);
        if (!$sp) {
            throw new Exception('SignedProperties no encontrado en el documento firmado.');
        }
        $canonical = $sp->C14N(true, false);
        return base64_encode(hash('sha256', $canonical, true));
    }

    /**
     * Canonicalize <ds:SignedInfo> with inclusive C14N, returning the bytes
     * over which openssl_sign should produce the SignatureValue.
     */
    private static function canonicalize_signed_info(DOMDocument $doc) {
        $xp = new DOMXPath($doc);
        $xp->registerNamespace('ds', self::DS_NS);
        $si = $xp->query('//ds:SignedInfo')->item(0);
        if (!$si) {
            throw new Exception('SignedInfo no encontrado.');
        }
        return $si->C14N(false, false);
    }

    /**
     * Put the computed digests into the two Reference/DigestValue nodes.
     * Reference 2 is the one URI="#{signed_props_id}".
     */
    private static function inject_digest_values(DOMDocument $doc, $signed_props_id, $ref1_digest, $ref2_digest) {
        $xp = new DOMXPath($doc);
        $xp->registerNamespace('ds', self::DS_NS);

        $ref1 = $xp->query('//ds:Reference[@URI=""]/ds:DigestValue')->item(0);
        $ref2 = $xp->query(sprintf('//ds:Reference[@URI="#%s"]/ds:DigestValue', $signed_props_id))->item(0);
        if (!$ref1 || !$ref2) {
            throw new Exception('Reference/DigestValue nodes no encontrados.');
        }
        $ref1->nodeValue = $ref1_digest;
        $ref2->nodeValue = $ref2_digest;
    }

    private static function inject_signature_value(DOMDocument $doc, $sig_b64) {
        $xp = new DOMXPath($doc);
        $xp->registerNamespace('ds', self::DS_NS);
        $sv = $xp->query('//ds:SignatureValue')->item(0);
        if (!$sv) {
            throw new Exception('SignatureValue no encontrado.');
        }
        $sv->nodeValue = $sig_b64;
    }

    /**
     * Build the issuer DN string the way XAdES expects (RFC2253-ish format),
     * mirroring what PHP's openssl_x509_parse returns in $parsed['name']
     * (falls back to concatenating parsed['issuer']).
     */
    private static function issuer_name_string(array $x509) {
        // Build from the parsed `issuer` dict (not `name`, which is the
        // subject DN). Use comma-space separator and RDN order from the
        // x509 parse: typical output is CN=...,OU=...,O=...,C=...
        if (!empty($x509['issuer']) && is_array($x509['issuer'])) {
            $parts = [];
            foreach ($x509['issuer'] as $k => $v) {
                $parts[] = $k . '=' . $v;
            }
            return implode(', ', $parts);
        }
        return '';
    }

    /** @deprecated unused — kept only so diff review is easier. */
    private static function reconcile_signed_properties_digest_unused($xml, $pkey_pem) {
        // 1. Strip redundant xmlns:ds within SignedProperties. We keep the
        //    first occurrence (the setAttributeNS-added one on the root)
        //    so C14N still sees the ds prefix declared in scope.
        $xml = preg_replace_callback(
            '~<xades:SignedProperties\b.*?</xades:SignedProperties>~s',
            function ($m) {
                $block = $m[0];
                $seen = false;
                return preg_replace_callback(
                    '~ xmlns:ds="http://www\.w3\.org/2000/09/xmldsig\#"~',
                    function () use (&$seen) {
                        if (!$seen) { $seen = true; return ' xmlns:ds="http://www.w3.org/2000/09/xmldsig#"'; }
                        return '';
                    },
                    $block
                );
            },
            $xml
        );

        // 2. Reparse, canonicalize SignedProperties, compute new digest.
        $tmp = new DOMDocument();
        $tmp->preserveWhiteSpace = true;
        $tmp->formatOutput = false;
        if (!$tmp->loadXML($xml, LIBXML_NONET)) {
            throw new Exception('Post-firma: XML malformado al limpiar xmlns:ds.');
        }
        $xp = new DOMXPath($tmp);
        $xp->registerNamespace('xades', self::XADES_NS);
        $xp->registerNamespace('ds', self::DS_NS);

        $sp_node = $xp->query('//xades:SignedProperties')->item(0);
        if (!$sp_node) {
            throw new Exception('Post-firma: SignedProperties no encontrado.');
        }
        // Hacienda expects the SignedProperties digest to be computed over
        // the inclusive C14N of the element as a fragment — excluding
        // ancestor xmlns declarations we don't use. PHP's C14N(false,false)
        // emits full inclusive-mode (injecting ancestor xmlns:xades/xmlns/
        // etc.). To get the fragment-local form the verifier expects we use
        // exclusive C14N instead: C14N(true, false). Exclusive-mode output
        // only carries the namespace prefixes that are *actually used* in
        // the subtree, which is what xadesjs-based signers produce and
        // what Hacienda recomputes on its side.
        $sp_canonical = $sp_node->C14N(true, false);
        $new_sp_digest = base64_encode(hash('sha256', $sp_canonical, true));

        // 3. Replace Reference 2 DigestValue. Reference 2 is the one whose
        //    URI points at the SignedProperties by fragment (#...-signedprops).
        //    xmlseclibs doesn't support emitting Type="..." on the reference,
        //    so we match by URI instead.
        $sp_id = $sp_node->getAttribute('Id');
        $ref_node = $xp->query(
            sprintf('//ds:Reference[@URI="#%s"]/ds:DigestValue', $sp_id)
        )->item(0);
        if (!$ref_node) {
            throw new Exception('Post-firma: Reference a SignedProperties no encontrado.');
        }
        $ref_node->nodeValue = $new_sp_digest;

        // 4. Canonicalize the updated SignedInfo and re-sign.
        $si_node = $xp->query('//ds:SignedInfo')->item(0);
        if (!$si_node) {
            throw new Exception('Post-firma: SignedInfo no encontrado.');
        }
        // SignedInfo uses inclusive C14N per its CanonicalizationMethod
        // (set on the Signature element). That's what Hacienda will apply
        // too, and for SignedInfo there are no ancestor xmlns we need to
        // exclude (it only uses the ds namespace which is declared locally).
        $si_canonical = $si_node->C14N(false, false);

        $pkey = openssl_pkey_get_private($pkey_pem);
        if (!$pkey) {
            throw new Exception('Post-firma: private key no cargable para re-firma.');
        }
        $signature = '';
        if (!openssl_sign($si_canonical, $signature, $pkey, OPENSSL_ALGO_SHA256)) {
            throw new Exception('Post-firma: openssl_sign falló.');
        }
        $new_sig_b64 = base64_encode($signature);

        // 5. Replace SignatureValue in the XML. There's exactly one per document.
        $sigval_node = $xp->query('//ds:SignatureValue')->item(0);
        if (!$sigval_node) {
            throw new Exception('Post-firma: SignatureValue no encontrado.');
        }
        $sigval_node->nodeValue = $new_sig_b64;

        return $tmp->saveXML();
    }

    /**
     * Persist an unsigned XML for debugging failed signing attempts.
     *
     * Writes into the FE_Woo certificate upload dir (`private/fe_woo/debug/`),
     * which is protected by `.htaccess Deny from all` + `index.php` (created
     * by FE_Woo_Certificate_Handler). A random suffix is appended so the
     * filename cannot be enumerated from a known `clave`, and the file is
     * chmod 0600 after write. Disabled unless WP_DEBUG is on.
     *
     * @param int    $order_id Order ID.
     * @param string $clave Invoice clave (or placeholder).
     * @param string $xml XML content.
     * @return string|false File path on success, false on failure or when disabled.
     */
    public static function dump_unsigned_xml($order_id, $clave, $xml) {
        if (!(defined('WP_DEBUG') && WP_DEBUG)) {
            return false;
        }
        if (!class_exists('FE_Woo_Certificate_Handler')) {
            return false;
        }
        $base = FE_Woo_Certificate_Handler::get_upload_path();
        if (empty($base)) {
            return false;
        }
        $dir = trailingslashit($base) . 'debug';
        if (!file_exists($dir)) {
            wp_mkdir_p($dir);
        }
        $safe_clave = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $clave);
        $random     = wp_generate_password(16, false);
        $file = sprintf('%s/fe_woo-unsigned-%d-%s-%s.xml', $dir, (int) $order_id, $safe_clave, $random);
        $ok = file_put_contents($file, (string) $xml);
        if ($ok === false) {
            return false;
        }
        @chmod($file, 0600);
        return $file;
    }

    /**
     * Map the root element's local name (FacturaElectronica, etc.) to the
     * v4.4 schema URI that acts as the signature policy identifier.
     */
    private static function policy_url_for_root($root_local_name) {
        $map = [
            'FacturaElectronica'          => 'facturaElectronica',
            'TiqueteElectronico'          => 'tiqueteElectronico',
            'NotaCreditoElectronica'      => 'notaCreditoElectronica',
            'NotaDebitoElectronica'       => 'notaDebitoElectronica',
            'FacturaElectronicaCompra'    => 'facturaElectronicaCompra',
            'FacturaElectronicaExportacion' => 'facturaElectronicaExportacion',
            'ReciboElectronicoPago'       => 'reciboElectronicoPago',
        ];
        $suffix = $map[$root_local_name] ?? 'facturaElectronica';
        return self::SCHEMA_BASE . $suffix;
    }

    /**
     * Compute the SigPolicyHash DigestValue the way xadesjs does it:
     * create the Identifier element with Qualifier=OIDAsURI, canonicalize
     * it via EXC-C14N, then SHA-1 the canonicalized bytes and base64 the
     * digest. The computed hash is what Hacienda verifies, so its format
     * must match byte-for-byte what we emit in <xades:Identifier> below.
     */
    private static function compute_policy_hash($policy_url) {
        $tmp = new DOMDocument('1.0', 'UTF-8');
        // We canonicalize only the Identifier element itself (not the
        // surrounding SigPolicyId) — this matches xadesjs's behaviour
        // when no explicit Transforms are provided on the policy.
        $ident = $tmp->createElementNS(self::XADES_NS, 'xades:Identifier', $policy_url);
        $ident->setAttribute('Qualifier', 'OIDAsURI');
        $tmp->appendChild($ident);
        $canonical = $tmp->documentElement->C14N(true, false);
        return base64_encode(sha1($canonical, true));
    }

    /**
     * Build the <xades:QualifyingProperties> DOM subtree and return both the
     * wrapper element and the <xades:SignedProperties> child (needed to
     * reference it from ds:SignedInfo).
     *
     * @return array ['qualifying_properties' => DOMElement, 'signed_properties' => DOMElement]
     */
    private static function build_qualifying_properties(
        DOMDocument $doc,
        $signature_id,
        $signed_props_id,
        $cert_pem,
        array $x509_parsed,
        array $policy
    ) {
        $qp = $doc->createElementNS(self::XADES_NS, 'xades:QualifyingProperties');
        $qp->setAttribute('Target', '#' . $signature_id);

        $sp = $doc->createElementNS(self::XADES_NS, 'xades:SignedProperties');
        $sp->setAttribute('Id', $signed_props_id);

        // Declare xmlns:ds once on the SignedProperties root so the nested
        // ds:DigestMethod / ds:DigestValue / ds:X509* elements inherit it
        // via the canonical DOM xmlns resolution instead of each redeclaring
        // it locally. Without this, xmlseclibs-created elements end up with
        // redundant xmlns:ds attributes on every descendant, which changes
        // the inclusive-C14N output and breaks digest verification on
        // Hacienda's side (error: "referencia a los datos firmados no existe").
        $sp->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:ds', self::DS_NS);

        $ssp = $doc->createElementNS(self::XADES_NS, 'xades:SignedSignatureProperties');

        // xades:SigningTime — RFC3339 UTC.
        $signing_time = gmdate('Y-m-d\TH:i:s\Z');
        $ssp->appendChild($doc->createElementNS(self::XADES_NS, 'xades:SigningTime', $signing_time));

        // xades:SigningCertificate → Cert → CertDigest + IssuerSerial.
        $signing_cert = $doc->createElementNS(self::XADES_NS, 'xades:SigningCertificate');
        $cert_el = $doc->createElementNS(self::XADES_NS, 'xades:Cert');

        $cert_digest = $doc->createElementNS(self::XADES_NS, 'xades:CertDigest');
        $digest_method = $doc->createElementNS(self::DS_NS, 'ds:DigestMethod');
        $digest_method->setAttribute('Algorithm', 'http://www.w3.org/2001/04/xmlenc#sha256');
        $cert_digest->appendChild($digest_method);

        $cert_der = self::pem_to_der($cert_pem);
        $cert_hash = base64_encode(hash('sha256', $cert_der, true));
        $cert_digest->appendChild($doc->createElementNS(self::DS_NS, 'ds:DigestValue', $cert_hash));
        $cert_el->appendChild($cert_digest);

        $issuer_serial = $doc->createElementNS(self::XADES_NS, 'xades:IssuerSerial');
        $issuer_name = isset($x509_parsed['name']) ? $x509_parsed['name'] : '';
        if (empty($issuer_name) && isset($x509_parsed['issuer']) && is_array($x509_parsed['issuer'])) {
            $parts = [];
            foreach ($x509_parsed['issuer'] as $k => $v) {
                $parts[] = $k . '=' . $v;
            }
            $issuer_name = implode(',', $parts);
        }
        $x509_issuer = $doc->createElementNS(self::DS_NS, 'ds:X509IssuerName', $issuer_name);
        $serial_number = isset($x509_parsed['serialNumber']) ? (string) $x509_parsed['serialNumber'] : '0';
        $x509_serial = $doc->createElementNS(self::DS_NS, 'ds:X509SerialNumber', $serial_number);
        $issuer_serial->appendChild($x509_issuer);
        $issuer_serial->appendChild($x509_serial);
        $cert_el->appendChild($issuer_serial);

        $signing_cert->appendChild($cert_el);
        $ssp->appendChild($signing_cert);

        // xades:SignaturePolicyIdentifier → xades:SignaturePolicyId.
        // Identifier carries the v4.4 schema URI with Qualifier=OIDAsURI,
        // and SigPolicyHash is SHA-1 over the canonicalized Identifier
        // element. This is the pattern Hacienda accepts from xadesjs-based
        // signers (Servora et al.); the older pattern of hashing a PDF
        // policy document with SHA-256 no longer validates.
        $policy_id_wrap = $doc->createElementNS(self::XADES_NS, 'xades:SignaturePolicyIdentifier');
        $policy_id = $doc->createElementNS(self::XADES_NS, 'xades:SignaturePolicyId');

        $sig_policy = $doc->createElementNS(self::XADES_NS, 'xades:SigPolicyId');
        $identifier = $doc->createElementNS(self::XADES_NS, 'xades:Identifier', $policy['url']);
        $identifier->setAttribute('Qualifier', 'OIDAsURI');
        $sig_policy->appendChild($identifier);
        $policy_id->appendChild($sig_policy);

        $policy_hash = $doc->createElementNS(self::XADES_NS, 'xades:SigPolicyHash');
        $policy_hash_method = $doc->createElementNS(self::DS_NS, 'ds:DigestMethod');
        $policy_hash_method->setAttribute('Algorithm', 'http://www.w3.org/2000/09/xmldsig#sha1');
        $policy_hash->appendChild($policy_hash_method);
        $policy_hash->appendChild($doc->createElementNS(self::DS_NS, 'ds:DigestValue', self::compute_policy_hash($policy['url'])));
        $policy_id->appendChild($policy_hash);

        $policy_id_wrap->appendChild($policy_id);
        $ssp->appendChild($policy_id_wrap);

        // xades:SignerRole > ClaimedRoles > ClaimedRole = ObligadoTributario.
        // Required by Hacienda's Resolución DGT-R-48-2016 §7.2 and mirrored
        // by every working CR v4.4 signer (Servora's xadesjs config sets it).
        $signer_role = $doc->createElementNS(self::XADES_NS, 'xades:SignerRole');
        $claimed_roles = $doc->createElementNS(self::XADES_NS, 'xades:ClaimedRoles');
        $claimed_role = $doc->createElementNS(self::XADES_NS, 'xades:ClaimedRole', 'ObligadoTributario');
        $claimed_roles->appendChild($claimed_role);
        $signer_role->appendChild($claimed_roles);
        $ssp->appendChild($signer_role);

        $sp->appendChild($ssp);
        $qp->appendChild($sp);

        // Strip xmlns:ds redeclarations from descendants — PHP DOM emits one
        // on every ds:* child because the ds prefix isn't declared on an
        // ancestor. Hacienda canonicalizes without those redundant
        // declarations, so we have to match: keep the xmlns:ds on the
        // SignedProperties root (set via setAttributeNS above) and delete
        // the per-element duplicates so inclusive-C14N output matches.
        self::strip_redundant_ns($sp, 'ds', self::DS_NS);

        return [
            'qualifying_properties' => $qp,
            'signed_properties'     => $sp,
        ];
    }

    /**
     * Remove xmlns:$prefix="$uri" attribute from every descendant of $root
     * except $root itself. Used to eliminate PHP DOM's automatic ns
     * redeclarations before C14N.
     */
    private static function strip_redundant_ns(DOMElement $root, $prefix, $uri) {
        $xpath = new DOMXPath($root->ownerDocument);
        // XPath 1.0 can't query xmlns attributes directly, so walk descendants.
        foreach ($xpath->query('.//*', $root) as $el) {
            if (!($el instanceof DOMElement)) continue;
            if ($el->hasAttributeNS('http://www.w3.org/2000/xmlns/', $prefix)) {
                $el->removeAttributeNS('http://www.w3.org/2000/xmlns/', $prefix);
            }
        }
    }

    /**
     * Convert a PEM-encoded certificate to raw DER bytes for digest purposes.
     */
    private static function pem_to_der($pem) {
        $stripped = preg_replace('#-----(BEGIN|END) CERTIFICATE-----#', '', (string) $pem);
        $stripped = preg_replace('#\s+#', '', $stripped);
        return base64_decode($stripped);
    }

    /**
     * Generate a v4 UUID.
     */
    private static function uuid_v4() {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
