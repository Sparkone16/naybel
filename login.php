<?php
session_start();

// Chargement du .env
function loadEnv($file) {
    if (!file_exists($file)) return;
    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        list($name, $value) = explode('=', $line, 2);
        $_ENV[trim($name)] = trim($value);
    }
}
loadEnv(__DIR__ . '/.env');

$error = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $password = $_POST['password'] ?? '';
    
    // Récupération du hash depuis le .env (ou un hash de secours par défaut sécurisé)
    // Par défaut ici, le hash correspond au mot de passe : "NaybelAdmin2026!"
    $storedHash = $_ENV['ADMIN_PASSWORD_HASH'] ?? '';

    // Vérification sécurisée du mot de passe saisi par rapport au hash
    if (password_verify($password, $storedHash)) {
        $_SESSION['admin_logged'] = true;
        header("Location: admin.php");
        exit;
    } else {
        $error = "Mot de passe incorrect.";
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Connexion Administration | NAYBEL</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600&display=swap" rel="stylesheet">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        dark: '#242322',
                        sienna: '#a1674a',
                        ivory: '#efeae5',
                    },
                    fontFamily: { sans: ['Montserrat', 'sans-serif'] }
                }
            }
        }
    </script>
</head>
<body class="bg-dark text-ivory font-sans h-screen flex items-center justify-center px-6">
    <div class="max-w-md w-full bg-[#1a1918] p-8 rounded-lg border border-ivory/10 shadow-2xl">
        <div class="text-center mb-8">
            <span class="text-sienna uppercase tracking-[0.3em] text-xs font-medium mb-2 block">Sécurité</span>
            <h1 class="text-2xl font-light tracking-wide">Espace Administrateur</h1>
            <div class="w-12 h-px bg-sienna mx-auto mt-4"></div>
        </div>

        <?php if ($error): ?>
            <div class="mb-6 p-3 bg-red-500/10 border border-red-500 text-red-400 text-xs text-center uppercase tracking-wider">
                <?php echo $error; ?>
            </div>
        <?php endif; ?>

        <form action="login.php" method="POST" class="space-y-6">
            <div class="relative">
                <input type="password" id="password" name="password" required placeholder=" "
                    class="w-full bg-transparent border-b border-ivory/20 py-3 text-ivory focus:outline-none focus:border-sienna transition-colors peer placeholder-transparent text-sm">
                <label for="password" class="absolute left-0 top-3 text-ivory/50 text-xs uppercase tracking-widest transition-all peer-placeholder-shown:text-sm peer-placeholder-shown:top-3 peer-focus:-top-4 peer-focus:text-xs peer-focus:text-sienna peer-valid:-top-4 peer-valid:text-xs">
                    Mot de passe administrateur
                </label>
            </div>

            <div class="pt-4">
                <button type="submit" class="w-full border border-ivory/30 py-4 text-xs uppercase tracking-widest text-ivory hover:bg-sienna hover:border-sienna transition-all duration-300">
                    Se connecter
                </button>
            </div>
        </form>
    </div>
</body>
</html>