<?php

declare(strict_types=1);

$temporaryPath = null;
$status = 0;

try {
    $json = getenv('GOOGLE_APPLICATION_CREDENTIALS_JSON');
    $path = getenv('GOOGLE_APPLICATION_CREDENTIALS');
    if (! is_string($json) || $json === '' || ! is_string($path) || $path === '') {
        throw new RuntimeException;
    }

    $credentials = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    if (! is_array($credentials)
        || ($credentials['type'] ?? null) !== 'service_account'
        || ! is_string($credentials['client_email'] ?? null)
        || filter_var($credentials['client_email'], FILTER_VALIDATE_EMAIL) === false
        || ! is_string($credentials['private_key'] ?? null)) {
        throw new RuntimeException;
    }

    $key = openssl_pkey_get_private($credentials['private_key']);
    if ($key === false || (openssl_pkey_get_details($key)['type'] ?? null) !== OPENSSL_KEYTYPE_RSA) {
        throw new RuntimeException;
    }

    umask(0077);
    $directory = dirname($path);
    if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
        throw new RuntimeException;
    }

    $temporaryPath = tempnam($directory, '.google-credentials-');
    if ($temporaryPath === false || ! chmod($temporaryPath, 0600)) {
        throw new RuntimeException;
    }
    if (file_put_contents($temporaryPath, $json, LOCK_EX) !== strlen($json) || ! rename($temporaryPath, $path)) {
        throw new RuntimeException;
    }
    $temporaryPath = null;
} catch (Throwable) {
    fwrite(STDERR, "Google Speech credentials could not be prepared. Check the service-account JSON and credential path permissions.\n");
    $status = 1;
} finally {
    if (is_string($temporaryPath) && is_file($temporaryPath)) {
        unlink($temporaryPath);
    }
}

exit($status);
