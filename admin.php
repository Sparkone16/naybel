<?php
session_start();

// Protection de la page
if (!isset($_SESSION['admin_logged']) || $_SESSION['admin_logged'] !== true) {
    header("Location: login.php");
    exit;
}

if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: login.php");
    exit;
}

// Fichiers JSON de stockage
$categoriesFile = 'categories.json';
$photosFile = 'photos.json';

// Initialisation du fichier categories.json s'il n'existe pas
if (!file_exists($categoriesFile)) {
    $defaultCategories = [
        "famille" => ["mariage", "anniversaire"],
        "animaux" => ["chiens", "chats", "sauvage"],
        "voyages" => ["new-york", "marrakech", "londres"]
    ];
    file_put_contents($categoriesFile, json_encode($defaultCategories, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

$categoriesData = json_decode(file_get_contents($categoriesFile), true);

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

$successMsg = '';
$errorMsg = '';

// --- GESTION DES ACTIONS POST ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. AJOUT D'UNE CATÉGORIE
    if ($action === 'add_category') {
        $newCat = trim(strtolower($_POST['new_category'] ?? ''));
        if (!empty($newCat)) {
            if (!isset($categoriesData[$newCat])) {
                $categoriesData[$newCat] = [];
                file_put_contents($categoriesFile, json_encode($categoriesData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                $successMsg = "Catégorie '{$newCat}' ajoutée avec succès !";
            } else {
                $errorMsg = "Cette catégorie existe déjà.";
            }
        }
    }

    // 2. AJOUT D'UNE SOUS-CATÉGORIE
    if ($action === 'add_subcat') {
        $targetCat = $_POST['target_category'] ?? '';
        $newSub = trim(strtolower($_POST['new_subcat'] ?? ''));
        if (!empty($targetCat) && !empty($newSub) && isset($categoriesData[$targetCat])) {
            if (!in_array($newSub, $categoriesData[$targetCat])) {
                $categoriesData[$targetCat][] = $newSub;
                file_put_contents($categoriesFile, json_encode($categoriesData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                $successMsg = "Sous-catégorie '{$newSub}' ajoutée à '{$targetCat}' avec succès !";
            } else {
                $errorMsg = "Cette sous-catégorie existe déjà pour ce thème.";
            }
        }
    }

    // 3. UPLOAD DE PHOTO (VERS GITHUB)
    if ($action === 'upload_photo' && isset($_FILES['photo'])) {
        $category    = $_POST['category'] ?? '';
        $subcat      = $_POST['subcat'] ?? '';
        $title       = htmlspecialchars($_POST['title'] ?? 'Photo');
        $file        = $_FILES['photo'];
        
        if ($file['error'] === UPLOAD_ERR_OK) {
            $fileTmpPath = $file['tmp_name'];
            $fileName    = time() . '_' . preg_replace('/[^a-zA-Z0-9_\.-]/', '', basename($file['name']));
            $targetPath  = 'app/assets/' . $category . '/' . $fileName;
            
            $fileData    = file_get_contents($fileTmpPath);
            $base64Data  = base64_encode($fileData);

            $token       = $_ENV['GITHUB_TOKEN'] ?? '';
            $repo        = $_ENV['GITHUB_REPO'] ?? 'Sparkone16/naybel';
            $branch      = $_ENV['GITHUB_BRANCH'] ?? 'main';

            $url = "https://api.github.com/repos/{$repo}/contents/{$targetPath}";

            $payload = json_encode([
                "message" => "Admin upload: Ajout de " . $fileName,
                "content" => $base64Data,
                "branch"  => $branch
            ]);

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Authorization: Bearer " . $token,
                "User-Agent: NAYBEL-Admin-Panel",
                "Content-Type: application/json"
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 201 || $httpCode === 200) {
                $currentPhotos = file_exists($photosFile) ? json_decode(file_get_contents($photosFile), true) : [];
                $rawUrl = "app/assets/" . $category . "/" . $fileName;

                $newPhotoData = [
                    "src" => $rawUrl,
                    "cat" => $category,
                    "subcat" => $subcat,
                    "titre" => $title
                ];
                
                array_unshift($currentPhotos, $newPhotoData);
                file_put_contents($photosFile, json_encode($currentPhotos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

                $successMsg = "Photo envoyée avec succès sur GitHub !";
            } else {
                $errorMsg = "Erreur GitHub (Code HTTP {$httpCode}) : Vérifiez votre token.";
            }
        } else {
            $errorMsg = "Erreur lors de la sélection du fichier.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Administration | NAYBEL</title>
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
<body class="bg-dark text-ivory font-sans min-h-screen py-12 px-6">
    <div class="max-w-3xl mx-auto space-y-8">
        
        <!-- En-tête -->
        <div class="bg-[#1a1918] p-8 rounded-lg border border-ivory/10 shadow-2xl flex justify-between items-center">
            <div>
                <span class="text-sienna uppercase tracking-[0.3em] text-xs font-medium block">Panel Admin</span>
                <h1 class="text-2xl font-light tracking-wide">Gestion du Portfolio</h1>
            </div>
            <div class="flex items-center space-x-6">
                <a href="index.php" class="text-xs uppercase tracking-widest text-ivory/60 hover:text-ivory transition-colors">Voir le site</a>
                <a href="admin.php?logout=true" class="text-xs uppercase tracking-widest text-red-400 hover:text-red-300 transition-colors">Déconnexion</a>
            </div>
        </div>

        <?php if ($successMsg): ?>
            <div class="p-4 bg-sienna/10 border border-sienna text-sienna text-xs uppercase tracking-wider text-center">
                <?php echo $successMsg; ?>
            </div>
        <?php endif; ?>

        <?php if ($errorMsg): ?>
            <div class="p-4 bg-red-500/10 border border-red-500 text-red-400 text-xs uppercase tracking-wider text-center">
                <?php echo $errorMsg; ?>
            </div>
        <?php endif; ?>

        <!-- GRID DES PANELS DE GESTION -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
            
            <!-- PANEL 1 : AJOUTER UNE CATÉGORIE -->
            <div class="bg-[#1a1918] p-6 rounded-lg border border-ivory/10 shadow-xl">
                <h2 class="text-lg font-light tracking-wide mb-4 text-sienna">Ajouter une catégorie</h2>
                <form action="admin.php" method="POST" class="space-y-4">
                    <input type="hidden" name="action" value="add_category">
                    <div class="relative">
                        <input type="text" id="new_category" name="new_category" required placeholder=" "
                            class="w-full bg-transparent border-b border-ivory/20 py-3 text-ivory focus:outline-none focus:border-sienna transition-colors peer placeholder-transparent text-sm">
                        <label for="new_category" class="absolute left-0 top-3 text-ivory/50 text-xs uppercase tracking-widest transition-all peer-placeholder-shown:text-sm peer-placeholder-shown:top-3 peer-focus:-top-4 peer-focus:text-xs peer-focus:text-sienna peer-valid:-top-4 peer-valid:text-xs">
                            Nom de la catégorie (ex: portrait)
                        </label>
                    </div>
                    <button type="submit" class="w-full border border-ivory/30 py-3 text-xs uppercase tracking-widest text-ivory hover:bg-sienna hover:border-sienna transition-all">
                        Créer la catégorie
                    </button>
                </form>
            </div>

            <!-- PANEL 2 : AJOUTER UNE SOUS-CATÉGORIE -->
            <div class="bg-[#1a1918] p-6 rounded-lg border border-ivory/10 shadow-xl">
                <h2 class="text-lg font-light tracking-wide mb-4 text-sienna">Ajouter une sous-catégorie</h2>
                <form action="admin.php" method="POST" class="space-y-4">
                    <input type="hidden" name="action" value="add_subcat">
                    <div>
                        <select name="target_category" required class="w-full bg-dark border-b border-ivory/20 py-3 text-ivory focus:outline-none focus:border-sienna transition-colors text-sm">
                            <option value="" disabled selected>Sélectionner la catégorie parente</option>
                            <?php foreach ($categoriesData as $catName => $subcats): ?>
                                <option value="<?php echo $catName; ?>" class="bg-dark text-ivory"><?php echo ucfirst($catName); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="relative">
                        <input type="text" id="new_subcat" name="new_subcat" required placeholder=" "
                            class="w-full bg-transparent border-b border-ivory/20 py-3 text-ivory focus:outline-none focus:border-sienna transition-colors peer placeholder-transparent text-sm">
                        <label for="new_subcat" class="absolute left-0 top-3 text-ivory/50 text-xs uppercase tracking-widest transition-all peer-placeholder-shown:text-sm peer-placeholder-shown:top-3 peer-focus:-top-4 peer-focus:text-xs peer-focus:text-sienna peer-valid:-top-4 peer-valid:text-xs">
                            Nom de la sous-catégorie (ex: londres)
                        </label>
                    </div>
                    <button type="submit" class="w-full border border-ivory/30 py-3 text-xs uppercase tracking-widest text-ivory hover:bg-sienna hover:border-sienna transition-all">
                        Créer la sous-catégorie
                    </button>
                </form>
            </div>

        </div>

        <!-- PANEL 3 : AJOUTER UNE PHOTO -->
        <div class="bg-[#1a1918] p-8 rounded-lg border border-ivory/10 shadow-2xl">
            <h2 class="text-xl font-light tracking-wide mb-6 text-sienna">Uploader une photo</h2>
            <form action="admin.php" method="POST" enctype="multipart/form-data" class="space-y-6">
                <input type="hidden" name="action" value="upload_photo">
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-xs uppercase tracking-widest text-sienna font-semibold mb-2">Catégorie</label>
                        <select name="category" required class="w-full bg-dark border-b border-ivory/20 py-3 text-ivory focus:outline-none focus:border-sienna transition-colors text-sm">
                            <?php foreach ($categoriesData as $catName => $subcats): ?>
                                <option value="<?php echo $catName; ?>" class="bg-dark text-ivory"><?php echo ucfirst($catName); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="relative">
                        <input type="text" id="subcat" name="subcat" placeholder=" "
                            class="w-full bg-transparent border-b border-ivory/20 py-3 text-ivory focus:outline-none focus:border-sienna transition-colors peer placeholder-transparent text-sm">
                        <label for="subcat" class="absolute left-0 top-3 text-ivory/50 text-xs uppercase tracking-widest transition-all peer-placeholder-shown:text-sm peer-placeholder-shown:top-3 peer-focus:-top-4 peer-focus:text-xs peer-focus:text-sienna peer-valid:-top-4 peer-valid:text-xs">
                            Sous-catégorie optionnelle (ex: new-york)
                        </label>
                    </div>
                </div>

                <div class="relative">
                    <input type="text" id="title" name="title" required placeholder=" "
                        class="w-full bg-transparent border-b border-ivory/20 py-3 text-ivory focus:outline-none focus:border-sienna transition-colors peer placeholder-transparent text-sm">
                    <label for="title" class="absolute left-0 top-3 text-ivory/50 text-xs uppercase tracking-widest transition-all peer-placeholder-shown:text-sm peer-placeholder-shown:top-3 peer-focus:-top-4 peer-focus:text-xs peer-focus:text-sienna peer-valid:-top-4 peer-valid:text-xs">
                        Titre / Légende de la photo
                    </label>
                </div>

                <div class="pt-2">
                    <label class="block text-xs uppercase tracking-widest text-sienna font-semibold mb-2">Fichier image</label>
                    <input type="file" name="photo" accept="image/*" required class="w-full text-sm text-ivory/70 file:mr-4 file:py-2 file:px-4 file:rounded-none file:border file:border-ivory/30 file:text-xs file:uppercase file:tracking-widest file:bg-transparent file:text-ivory hover:file:bg-sienna hover:file:border-sienna transition-all cursor-pointer">
                </div>

                <div class="pt-4 flex justify-end">
                    <button type="submit" class="border border-ivory/30 px-8 py-4 text-xs uppercase tracking-widest text-ivory hover:bg-sienna hover:border-sienna transition-all duration-300">
                        Envoyer la photo
                    </button>
                </div>
            </form>
        </div>

    </div>
</body>
</html>