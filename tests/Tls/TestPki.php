<?php

declare(strict_types=1);

namespace Ondewo\Sip\Tests\Tls;

use RuntimeException;

/**
 * A throwaway PKI generated with ext-openssl at test time, so no private key is ever committed:
 * a CA, a server certificate for localhost / 127.0.0.1 / ::1, a client certificate of that CA, and
 * a second, unrelated CA with a client certificate of its own. EC P-256 keys, unencrypted.
 */
final class TestPki
{
    private const OPENSSL_CONFIG = <<<'CNF'
        [req]
        distinguished_name = dn
        # PHP 8.1 checks the RSA key length even for an EC key, and an unset one counts as 0.
        default_bits = 2048
        [dn]
        [ca_ext]
        basicConstraints = critical,CA:TRUE
        keyUsage = critical,keyCertSign,cRLSign
        subjectKeyIdentifier = hash
        [server_ext]
        basicConstraints = CA:FALSE
        keyUsage = critical,digitalSignature
        subjectAltName = DNS:localhost,IP:127.0.0.1,IP:::1
        extendedKeyUsage = serverAuth
        [client_ext]
        basicConstraints = CA:FALSE
        keyUsage = critical,digitalSignature
        extendedKeyUsage = clientAuth
        CNF;

    public readonly string $caCert;
    public readonly string $serverCert;
    public readonly string $serverKey;
    public readonly string $clientCert;
    public readonly string $clientKey;
    public readonly string $otherCaCert;
    public readonly string $otherClientCert;
    public readonly string $otherClientKey;

    private string $configFile;

    private int $serial = 1;

    public function __construct()
    {
        $configFile = tempnam(sys_get_temp_dir(), 'ondewo-pki-cnf-');
        if ($configFile === false || file_put_contents($configFile, self::OPENSSL_CONFIG) === false) {
            throw new RuntimeException('cannot write the openssl config of the test PKI');
        }
        $this->configFile = $configFile;
        try {
            [$this->caCert, $caKey] = $this->issue('Test CA', 'ca_ext', null, null);
            [$this->serverCert, $this->serverKey] = $this->issue('localhost', 'server_ext', $this->caCert, $caKey);
            [$this->clientCert, $this->clientKey] = $this->issue('my-client', 'client_ext', $this->caCert, $caKey);
            [$this->otherCaCert, $otherCaKey] = $this->issue('Unrelated CA', 'ca_ext', null, null);
            [$this->otherClientCert, $this->otherClientKey] = $this->issue(
                'intruder',
                'client_ext',
                $this->otherCaCert,
                $otherCaKey,
            );
        } finally {
            unlink($configFile);
        }
    }

    /**
     * @return array{string, string} the certificate and private key PEMs
     */
    private function issue(string $commonName, string $extensions, ?string $issuerCert, ?string $issuerKey): array
    {
        $options = ['config' => $this->configFile, 'digest_alg' => 'sha256', 'x509_extensions' => $extensions];
        $key = openssl_pkey_new([
            'config' => $this->configFile,
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);
        if ($key === false) {
            throw new RuntimeException('openssl_pkey_new failed: ' . openssl_error_string());
        }
        $csr = openssl_csr_new(['commonName' => $commonName], $key, $options);
        if (!$csr instanceof \OpenSSLCertificateSigningRequest) {
            throw new RuntimeException('openssl_csr_new failed: ' . openssl_error_string());
        }
        $cert = openssl_csr_sign($csr, $issuerCert, $issuerKey ?? $key, 2, $options, $this->serial++);
        if ($cert === false || !openssl_x509_export($cert, $certPem) || !openssl_pkey_export($key, $keyPem, null, $options)) {
            throw new RuntimeException('signing the certificate of ' . $commonName . ' failed: ' . openssl_error_string());
        }

        return [$certPem, $keyPem];
    }
}
