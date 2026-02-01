<?php
/**
 * Metin2-Flix Registration Handler mit PHPMailer
 * Konfiguriert für Strato SMTP
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// PHPMailer laden (wähle eine Methode)
// Methode 1: Mit Composer
require 'vendor/autoload.php';

// Methode 2: Manuell (falls kein Composer - dann diese Zeilen auskommentieren)
// require 'PHPMailer/src/Exception.php';
// require 'PHPMailer/src/PHPMailer.php';
// require 'PHPMailer/src/SMTP.php';

// CORS Headers
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

// ==========================================
// KONFIGURATION - NUR PASSWORT ANPASSEN!
// ==========================================
$CONFIG = [
    // Admin E-Mail (Empfänger)
    'admin_email' => 'kontakt@beardflix.de',
    
    // SMTP Server Einstellungen (STRATO) - KORRIGIERT!
    'smtp_host' => 'smtp.strato.de',
    'smtp_port' => 465,
    'smtp_username' => 'kontakt@beardflix.de',
    'smtp_password' => 'Ovawerkstatt1!#',
    'smtp_encryption' => 'ssl',
    
    // Absender-Informationen
    'smtp_from' => 'kontakt@beardflix.de',
    'smtp_from_name' => 'Metin2-Flix Registration',
    
    // Debug-Modus
    'debug_mode' => true,
];

// Weitere Einstellungen
$SAVE_TO_FILE = true;
$REGISTRATIONS_FILE = 'registrations.txt';
$RATE_LIMIT_FILE = 'rate_limits.json';
$MAX_REQUESTS_PER_IP = 5;

// ==========================================
// FUNKTIONEN
// ==========================================

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
    
    foreach ($limits as $key => $data) {
        if ($data['time'] < $hourAgo) {
            unset($limits[$key]);
        }
    }
    
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
    
    if (empty($username) || strlen($username) < 3 || strlen($username) > 20) {
        $errors[] = 'Benutzername muss 3-20 Zeichen lang sein';
    }
    
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
        $errors[] = 'Benutzername darf nur Buchstaben, Zahlen und _ enthalten';
    }
    
    if (empty($password) || strlen($password) < 6) {
        $errors[] = 'Passwort muss mindestens 6 Zeichen lang sein';
    }
    
    if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Ungültige E-Mail-Adresse';
    }
    
    return $errors;
}

/**
 * E-Mail mit PHPMailer senden - VERBESSERT FÜR STRATO
 */
function sendEmailWithPHPMailer($config, $username, $password, $email, $ip, $timestamp) {
    $mail = new PHPMailer(true);
    
    try {
        // Server settings
        if ($config['debug_mode']) {
            $mail->SMTPDebug = 2;
            $mail->Debugoutput = function($str, $level) {
                error_log("PHPMailer [$level]: $str");
            };
        }
        
        $mail->isSMTP();
        $mail->Host       = $config['smtp_host'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $config['smtp_username'];
        $mail->Password   = $config['smtp_password'];
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;  // SSL für Port 465
        $mail->Port       = $config['smtp_port'];
        $mail->CharSet    = 'UTF-8';
        
        // WICHTIG für Strato: Timeout erhöhen
        $mail->Timeout    = 30;
        $mail->SMTPKeepAlive = true;
        
        // WICHTIG: Verify Peer für Strato ausschalten falls SSL-Probleme
        $mail->SMTPOptions = array(
            'ssl' => array(
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            )
        );
        
        // Recipients
        $mail->setFrom($config['smtp_from'], $config['smtp_from_name']);
        $mail->addAddress($config['admin_email']);
        
        // Content
        $mail->isHTML(true);
        $mail->Subject = '🎮 Neue Metin2-Flix Registrierung';
        
        $mail->Body = "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <style>
                body { font-family: Arial, sans-serif; background-color: #f4f4f4; padding: 20px; }
                .container { background-color: #ffffff; border-radius: 8px; padding: 30px; max-width: 600px; margin: 0 auto; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
                h2 { color: #e50914; margin-top: 0; }
                table { width: 100%; border-collapse: collapse; margin-top: 20px; }
                td { padding: 12px; border: 1px solid #ddd; }
                td:first-child { background-color: #f8f8f8; font-weight: bold; width: 40%; }
                .footer { margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd; color: #666; font-size: 12px; text-align: center; }
            </style>
        </head>
        <body>
            <div class='container'>
                <h2>🎮 Neue Account-Registrierung</h2>
                <p>Ein neuer Benutzer hat sich auf Metin2-Flix registriert:</p>
                
                <table>
                    <tr>
                        <td>Zeitpunkt</td>
                        <td>$timestamp</td>
                    </tr>
                    <tr>
                        <td>IP-Adresse</td>
                        <td>$ip</td>
                    </tr>
                    <tr>
                        <td>Benutzername</td>
                        <td><strong>$username</strong></td>
                    </tr>
                    <tr>
                        <td>Passwort</td>
                        <td><strong>$password</strong></td>
                    </tr>
                    <tr>
                        <td>E-Mail</td>
                        <td>" . ($email ?: '<em>Nicht angegeben</em>') . "</td>
                    </tr>
                </table>
                
                <div class='footer'>
                    <p>Automatisch generiert von Metin2-Flix Registration System</p>
                </div>
            </div>
        </body>
        </html>
        ";
        
        $mail->AltBody = "=== Neue Metin2-Flix Registrierung ===\n\n";
        $mail->AltBody .= "Zeitpunkt: $timestamp\n";
        $mail->AltBody .= "IP-Adresse: $ip\n\n";
        $mail->AltBody .= "Benutzername: $username\n";
        $mail->AltBody .= "Passwort: $password\n";
        $mail->AltBody .= "E-Mail: " . ($email ?: 'Nicht angegeben') . "\n\n";
        $mail->AltBody .= "---\n";
        $mail->AltBody .= "Automatisch generiert von Metin2-Flix Registration System";
        
        $mail->send();
        return ['success' => true, 'message' => 'E-Mail erfolgreich gesendet'];
        
    } catch (Exception $e) {
        error_log("PHPMailer Error: " . $mail->ErrorInfo);
        file_put_contents('email_errors.log', date('Y-m-d H:i:s') . " - " . $mail->ErrorInfo . "\n", FILE_APPEND);
        return ['success' => false, 'message' => $mail->ErrorInfo];
    }
}

// ==========================================
// MAIN
// ==========================================
try {
    $ip = $_SERVER['REMOTE_ADDR'];
    
    if (!checkRateLimit($ip, $MAX_REQUESTS_PER_IP, $RATE_LIMIT_FILE)) {
        http_response_code(429);
        echo json_encode([
            'success' => false,
            'message' => 'Zu viele Anfragen. Bitte warte eine Stunde und versuche es erneut.'
        ]);
        exit();
    }
    
    $username = isset($_POST['username']) ? trim($_POST['username']) : '';
    $password = isset($_POST['password']) ? trim($_POST['password']) : '';
    $email = isset($_POST['email']) ? trim($_POST['email']) : '';
    
    $errors = validateInput($username, $password, $email);
    
    if (!empty($errors)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => implode(', ', $errors)
        ]);
        exit();
    }
    
    $username = htmlspecialchars($username, ENT_QUOTES, 'UTF-8');
    $password = htmlspecialchars($password, ENT_QUOTES, 'UTF-8');
    $email = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
    
    $timestamp = date('Y-m-d H:i:s');
    
    $emailResult = sendEmailWithPHPMailer($CONFIG, $username, $password, $email, $ip, $timestamp);
    
    if ($SAVE_TO_FILE) {
        $logEntry = sprintf(
            "[%s] IP: %s | User: %s | Pass: %s | Email: %s | EmailSent: %s\n",
            $timestamp,
            $ip,
            $username,
            $password,
            $email ?: 'N/A',
            $emailResult['success'] ? 'YES' : 'NO'
        );
        
        file_put_contents($REGISTRATIONS_FILE, $logEntry, FILE_APPEND | LOCK_EX);
    }
    
    http_response_code(200);
    
    if ($emailResult['success']) {
        echo json_encode([
            'success' => true,
            'message' => 'Registrierung erfolgreich! Deine Daten wurden an den Administrator gesendet.'
        ]);
    } else {
        echo json_encode([
            'success' => true,
            'message' => 'Registrierung gespeichert. E-Mail-Versand fehlgeschlagen: ' . $emailResult['message'],
            'debug' => $CONFIG['debug_mode'] ? $emailResult['message'] : null
        ]);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Serverfehler beim Verarbeiten der Registrierung.',
        'debug' => $CONFIG['debug_mode'] ? $e->getMessage() : null
    ]);
}
?>
