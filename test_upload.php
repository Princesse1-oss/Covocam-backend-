<?php
echo "1. Connexion en cours...\n";
$loginUrl = 'http://127.0.0.1:8000/api/login';
$loginData = json_encode([
    'email' => 'admin@covocam.cm',
    'motDePasse' => 'Admin1234!'
]);

$ch = curl_init($loginUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $loginData);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Accept: application/json'
]);

$loginResponse = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200) {
    die("❌ Échec de la connexion. Code HTTP: $httpCode\nRéponse: $loginResponse\n");
}

$token = json_decode($loginResponse)->token ?? null;
if (!$token) {
    die("❌ Aucun token reçu.\n");
}
echo "✅ Token obtenu avec succès.\n";

echo "2. Envoi du fichier en cours...\n";
// Assurez-vous que test.jpg est bien dans ce même dossier
$filePath = __DIR__ . '\test.jpg';

if (!file_exists($filePath)) {
    die("❌ Le fichier test.jpg n'existe pas dans ce dossier.\n");
}

$uploadUrl = 'http://127.0.0.1:8000/api/upload/profil';
$ch2 = curl_init($uploadUrl);
curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch2, CURLOPT_POST, true);

// CURLFile gère parfaitement le multipart/form-data sans bug
$curlFile = new CURLFile($filePath, 'image/jpeg', 'test.jpg');
curl_setopt($ch2, CURLOPT_POSTFIELDS, [
    'photo' => $curlFile
]);

curl_setopt($ch2, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $token,
    'Accept: application/json'
]);

$uploadResponse = curl_exec($ch2);
$httpCode2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
curl_close($ch2);

echo "==================================================\n";
echo "Code HTTP : $httpCode2\n";
echo "Réponse du serveur :\n";
echo $uploadResponse . "\n";
echo "==================================================\n";

$decoded = json_decode($uploadResponse);
if (json_last_error() === JSON_ERROR_NONE) {
    echo "✅ Succès : La réponse est un JSON valide !\n";
} else {
    echo "⚠️ La réponse n'est pas du JSON (le serveur a peut-être renvoyé une erreur HTML).\n";
}