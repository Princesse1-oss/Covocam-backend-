<?php
// generate_jwt_keys.php

// Créer le dossier config/jwt s'il n'existe pas
if (!is_dir("config/jwt")) {
    mkdir("config/jwt", 0755, true);
    echo "Dossier config/jwt créé\n";
}

// Vérifier que l'extension OpenSSL est disponible
if (!extension_loaded("openssl")) {
    die("L'extension OpenSSL n'est pas activée dans PHP.\n");
}

echo "Génération des clés JWT...\n";

// Générer une paire de clés RSA
$config = [
    "digest_alg" => "sha256",
    "private_key_bits" => 4096,
    "private_key_type" => OPENSSL_KEYTYPE_RSA,
];

// Créer la clé privée
$privateKey = openssl_pkey_new($config);
if (!$privateKey) {
    die("Erreur lors de la génération de la clé privée: " . openssl_error_string());
}

// Exporter la clé privée
openssl_pkey_export($privateKey, $privateKeyPem, null);

// Exporter la clé publique
$publicKeyDetails = openssl_pkey_get_details($privateKey);
$publicKeyPem = $publicKeyDetails["key"];

// Sauvegarder les clés
file_put_contents("config/jwt/private.pem", $privateKeyPem);
file_put_contents("config/jwt/public.pem", $publicKeyPem);

echo "✅ Clés JWT générées avec succès !\n";
echo "📁 Clé privée: config/jwt/private.pem\n";
echo "📁 Clé publique: config/jwt/public.pem\n";
echo "\n🔑 Ajoutez dans votre .env.local:\n";
echo "JWT_PASSPHRASE=covocam2026\n";

// Vérifier les fichiers
if (file_exists("config/jwt/private.pem") && file_exists("config/jwt/public.pem")) {
    echo "\n✅ Vérification : Les deux fichiers existent bien !\n";
} else {
    echo "\n⚠️ Vérification : Un ou plusieurs fichiers manquent.\n";
}