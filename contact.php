<?php
session_start();
// Fonction simple pour charger le fichier .env
function validateTurnstile($token, $secret, $remoteip = null)
{
    $url = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    $data = [
        'secret' => $secret,
        'response' => $token
    ];

    if ($remoteip) {
        $data['remoteip'] = $remoteip;
    }

    $options = [
        'http' => [
            'header' => "Content-type: application/x-www-form-urlencoded\r\n",
            'method' => 'POST',
            'content' => http_build_query($data)
        ]
    ];

    $context = stream_context_create($options);
    $response = file_get_contents($url, false, $context);

    if ($response === FALSE) {
        return ['success' => false, 'error-codes' => ['internal-error']];
    }

    return json_decode($response, true);

}
// Fonction de chargement du fichier .env
function loadEnv($file)
{
    if (!file_exists($file))
        return;
    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0)
            continue;
        list($name, $value) = explode('=', $line, 2);
        $_ENV[trim($name)] = trim($value);
    }
}
loadEnv(__DIR__ . '/.env');

// Vérification de la méthode POST
if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $secret_key = $_ENV['SECRET_KEY'] ?? '';
    $token = $_POST['cf-turnstile-response'] ?? '';
    $remoteip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'];

    // Validation Cloudflare Turnstile
    $validation = validateTurnstile($token, $secret_key, $remoteip);

    if (!$validation['success']) {
        // Si le captcha échoue, on enregistre l'erreur en session et on redirige vers le formulaire
        $_SESSION['contact_status'] = 'error';
        header("Location: index.php#contact");
        exit;
    }

    // Récupération et nettoyage des données du formulaire
    $name = htmlspecialchars(trim($_POST['name'] ?? ''));
    $email = filter_var(trim($_POST['email'] ?? ''), FILTER_SANITIZE_EMAIL);
    $project = htmlspecialchars(trim($_POST['project'] ?? 'Non spécifié'));
    $message = htmlspecialchars(trim($_POST['message'] ?? ''));

    // Validation basique des champs
    if (empty($name) || !filter_var($email, FILTER_VALIDATE_EMAIL) || empty($message)) {
        $_SESSION['contact_status'] = 'error';
        header("Location: index.php#contact");
        exit;
    }

    // Variables de configuration mail (.env ou valeurs par défaut)
    $mailTo = $_ENV['MAIL_TO'] ?? 'contact@naybel.fr';
    $mailNoReply = $_ENV['MAIL_USER'] ?? 'no-reply@naybel.fr';
    $fromName = $_ENV['MAIL_FROM_NAME'] ?? 'NAYBEL - Photographe';

    $subject = "Nouveau contact : " . $name . " [" . ucfirst($project) . "]";

    // Template HTML de l'e-mail
    $htmlBody = '
    <!DOCTYPE html>
    <html lang="fr">
    <head>
        <meta charset="UTF-8">
        <style>
            body { background-color: #efeae5; font-family: Montserrat, sans-serif; margin: 0; padding: 40px 0; }
            .wrapper { max-width: 600px; margin: 0 auto; background-color: #242322; color: #efeae5; border-radius: 8px; overflow: hidden; box-shadow: 0 10px 30px rgba(0,0,0,0.2); }
            .header { background-color: #1a1918; padding: 30px; text-align: center; border-bottom: 1px solid rgba(161, 103, 74, 0.3); }
            .header h1 { color: #a1674a; font-size: 22px; text-transform: uppercase; letter-spacing: 3px; margin: 0; font-weight: 300; }
            .content { padding: 40px 30px; }
            .field { margin-bottom: 25px; }
            .label { font-size: 11px; text-transform: uppercase; letter-spacing: 2px; color: #a1674a; display: block; margin-bottom: 5px; }
            .value { font-size: 15px; font-weight: 300; color: #efeae5; line-height: 1.5; }
            .message-box { background-color: rgba(239, 234, 229, 0.05); padding: 20px; border-left: 2px solid #a1674a; margin-top: 10px; }
            .footer { background-color: #1a1918; padding: 20px; text-align: center; font-size: 11px; color: rgba(239, 234, 229, 0.5); letter-spacing: 2px; border-top: 1px solid rgba(239, 234, 229, 0.05); }
        </style>
    </head>
    <body>
        <div class="wrapper">
            <div class="header">
                <h1>Nouveau Message - Portfolio</h1>
            </div>
            <div class="content">
                <div class="field">
                    <span class="label">Client(e) :</span>
                    <div class="value"><strong>' . $name . '</strong> (' . $email . ')</div>
                </div>
                <div class="field">
                    <span class="label">Type de projet :</span>
                    <div class="value" style="text-transform: uppercase; letter-spacing: 1px; color: #a1674a;">' . $project . '</div>
                </div>
                <div class="field">
                    <span class="label">Message :</span>
                    <div class="message-box value">
                        ' . nl2br($message) . '
                    </div>
                </div>
            </div>
            <div class="footer">
                &copy; 2026 NAYBEL &bull; Développé par Visuatek
            </div>
        </div>
    </body>
    </html>';

    // En-têtes de l'e-mail
    $headers = "MIME-Version: 1.0" . "\r\n";
    $headers .= "Content-type: text/html; charset=UTF-8" . "\r\n";
    $headers .= "From: " . mb_encode_mimeheader($fromName) . " <" . $mailNoReply . ">" . "\r\n";
    $headers .= "Reply-To: " . $email . "\r\n";

    // Envoi effectif de l'e-mail
    if (mail($mailTo, $subject, $htmlBody, $headers, "-fno-reply@naybel.fr")) {
        $_SESSION['contact_status'] = 'success';
        header("Location: index.php#contact");
        exit;
    } else {
        $_SESSION['contact_status'] = 'error';
        header("Location: index.php#contact");
        exit;
    }

} else {
    // Si on accède au fichier directement sans soumettre le formulaire
    header("Location: index.php");
    exit;
}