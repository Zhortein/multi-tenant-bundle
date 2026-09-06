<?php

declare(strict_types=1);

// Disposable test TLS material only. Never print or commit the generated key.
$directory = $argv[1] ?? throw new RuntimeException('A fresh output directory is required.');
$configuration = $directory.'/openssl.cnf';
file_put_contents($configuration, "[req]\ndistinguished_name=dn\nx509_extensions=ext\n[dn]\n[ext]\nsubjectAltName=DNS:minio,DNS:minio-public,DNS:localhost\nbasicConstraints=critical,CA:TRUE\nkeyUsage=critical,digitalSignature,keyEncipherment,keyCertSign\nextendedKeyUsage=serverAuth\n");
$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
$csr = openssl_csr_new(['commonName' => 'minio'], $key, ['config' => $configuration, 'digest_alg' => 'sha256']);
$certificate = openssl_csr_sign($csr, null, $key, 1, ['config' => $configuration, 'digest_alg' => 'sha256']);
if (!$certificate || !openssl_x509_export_to_file($certificate, $directory.'/public.crt')
    || !openssl_pkey_export_to_file($key, $directory.'/private.key')) {
    throw new RuntimeException('Test certificate generation failed.');
}
chmod($directory.'/private.key', 0600);
