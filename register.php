<?php
/**
 * Metin2-Flix Registration Handler
 * Alternative zu FormSubmit.co - falls du ein eigenes PHP-Backend verwenden möchtest
 */

// CORS Headers (falls die Seite auf anderer Domain liegt)
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

// Handle OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Nur POST-Requests erlauben
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Nur POST erlaubt']);
    exit();
}

// Konfiguration
$ADMIN_EMAIL = 'kontakt@beardflix.de'; // DEINE E-MAIL HIER
$SAVE_TO_FILE = true; // Registrierungen auch in Datei speichern?
$REGISTRATIONS_FILE = 'registrations.txt';

// Rate Limiting (optional)
$MAX_REQUESTS_PER_IP = 5; // Max 5 Registrierungen pro Stunde pro IP
$RATE_LIMIT_FILE = 'rate_limits.json';

/**
 * Rate Limiting Check
 */
function checkRateLimit($ip, $maxRequests, $limitFile) {
    if (!file_exists($limitFile)) {
        file_put_contents($limitFile, json_encode([]));
    }
    
    $limits = json_decode(file_get_contents($limitFile), true);
    $now = time();
    $hourAgo = $now - 3600;
    
    // Alte Einträge löschen
    foreach ($limits as $key => $data) {
        if ($data['time'] < $hourAgo) {
            unset($limits[$key]);
        }
    }
    
    // IP prüfen
    if (!isset($limits[$ip])) {
        $limits[$ip] = ['count' => 0, 'time' => $now];
    }
    
    if ($limits[$ip]['count'] >= $maxRequests) {
        return false;
    }
    
    $limits[$ip]['count']++;
    $limits[$ip]['time'] = $now;
    file_put_contents($limitFile, json_encode($limits));
    
    return true;
}

/**
 * Input validieren
 */
function validateInput($username, $password, $email) {
    $errors = [];
    
    // Username
    if (empty($username) || strlen($username) < 3 || strlen($username) > 20) {
        $errors[] = 'Benutzername muss 3-20 Zeichen lang sein';
    }
    
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
        $errors[] = 'Benutzername darf nur Buchstaben, Zahlen und _ enthalten';
    }
    
    // Password
    if (empty($password) || strlen($password) < 6) {
        $errors[] = 'Passwort muss mindestens 6 Zeichen lang sein';
    }
    
    // Email (optional)
    if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Ungültige E-Mail-Adresse';
    }
    
    return $errors;
}

/**
 * Registrierung verarbeiten
 */
try {
    // IP-Adresse für Rate Limiting
    $ip = $_SERVER['REMOTE_ADDR'];
    
    // Rate Limiting prüfen
    if (!checkRateLimit($ip, $MAX_REQUESTS_PER_IP, $RATE_LIMIT_FILE)) {
        http_response_code(429);
        echo json_encode([
            'success' => false,
            'message' => 'Zu viele Anfragen. Bitte warte eine Stunde.'
        ]);
        exit();
    }
    
    // Daten auslesen
    $username = isset($_POST['username']) ? trim($_POST['username']) : '';
    $password = isset($_POST['password']) ? trim($_POST['password']) : '';
    $email = isset($_POST['email']) ? trim($_POST['email']) : '';
    
    // Validierung
    $errors = validateInput($username, $password, $email);
    
    if (!empty($errors)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => implode(', ', $errors)
        ]);
        exit();
    }
    
    // HTML-Entities für Sicherheit
    $username = htmlspecialchars($username, ENT_QUOTES, 'UTF-8');
    $password = htmlspecialchars($password, ENT_QUOTES, 'UTF-8');
    $email = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
    
    // Zeitstempel
    $timestamp = date('Y-m-d H:i:s');
    
    // E-Mail vorbereiten
    $subject = 'Neue Metin2-Flix Registrierung';
    $message = "Neue Account-Registrierung\n\n";
    $message .= "Zeitpunkt: $timestamp\n";
    $message .= "IP-Adresse: $ip\n\n";
    $message .= "Benutzername: $username\n";
    $message .= "Passwort: $password\n";
    $message .= "E-Mail: " . ($email ?: 'Nicht angegeben') . "\n\n";
    $message .= "---\n";
    $message .= "Automatisch generiert von Metin2-Flix Registration System";
    
    $headers = "From: noreply@metin2-flix.de\r\n";
    $headers .= "Reply-To: noreply@metin2-flix.de\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion();
    
    // E-Mail senden
    $emailSent = mail($ADMIN_EMAIL, $subject, $message, $headers);
    
    // In Datei speichern (optional)
    if ($SAVE_TO_FILE) {
        $logEntry = sprintf(
            "[%s] IP: %s | User: %s | Pass: %s | Email: %s\n",
            $timestamp,
            $ip,
            $username,
            $password,
            $email ?: 'N/A'
        );
        
        file_put_contents($REGISTRATIONS_FILE, $logEntry, FILE_APPEND | LOCK_EX);
    }
    
    // Erfolgreiche Antwort
    if ($emailSent) {
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'Registrierung erfolgreich! Deine Daten wurden gesendet.'
        ]);
    } else {
        // E-Mail fehlgeschlagen, aber in Datei gespeichert
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'Registrierung gespeichert (E-Mail-Versand fehlgeschlagen)'
        ]);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Serverfehler: ' . $e->getMessage()
    ]);
}
?>
