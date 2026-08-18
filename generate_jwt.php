<?php
// generate_jwt.php

if (!is_dir('config/jwt')) {
    mkdir('config/jwt', 0755, true);
}

$config = [
    'digest_alg' => 'sha256',
    'private_key_bits' => 4096,
    'private_key_type' => OPENSSL_KEYTYPE_RSA,
];

$privateKey = openssl_pkey_new($config);

if (!$privateKey) {
    die('Erreur lors de la génération de la clé privée: ' . openssl_error_string());
}

// Exporter la clé privée
openssl_pkey_export($privateKey, $privateKeyPem, null);

// Exporter la clé publique
$publicKeyDetails = openssl_pkey_get_details($privateKey);
$publicKeyPem = $publicKeyDetails['key'];

// Sauvegarder les clés
file_put_contents('config/jwt/private.pem', $privateKeyPem);
file_put_contents('config/jwt/public.pem', $publicKeyPem);

echo "✅ Clés JWT générées avec succès !\n";
echo "📁 Clé privée: config/jwt/private.pem\n";
echo "📁 Clé publique: config/jwt/public.pem\n";