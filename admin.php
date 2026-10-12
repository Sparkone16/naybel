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
$aboutFile = 'about.json';

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
$aboutData = file_exists($aboutFile) ? json_decode(file_get_contents($aboutFile), true) : [];

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
    // 2. AJOUT D'UNE SOUS-CATÉGORIE AU FORMAT OBJET {id, label}
    if ($action === 'add_subcat') {
        $targetCat = $_POST['target_category'] ?? '';
        $subcatName = trim($_POST['new_subcat'] ?? '');

        if (!empty($targetCat) && !empty($subcatName) && isset($categoriesData[$targetCat])) {
            // Création d'un ID propre (ex: "Noir et blanc" devient "noir-et-blanc")
            $subId = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $subcatName))));

            // Vérifier si l'ID existe déjà dans les sous-catégories de ce thème
            $exists = false;
            foreach ($categoriesData[$targetCat] as $sub) {
                if (is_array($sub) && $sub['id'] === $subId) {
                    $exists = true;
                    break;
                }
            }

            if (!$exists) {
                // On ajoute l'objet structuré
                $categoriesData[$targetCat][] = [
                    "id" => $subId,
                    "label" => ucfirst($subcatName)
                ];

                file_put_contents($categoriesFile, json_encode($categoriesData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                $successMsg = "Sous-catégorie '{$subcatName}' ajoutée avec succès !";
            } else {
                $errorMsg = "Cette sous-catégorie existe déjà pour ce thème.";
            }
        }
    }

    // 3. UPLOAD DE MULTIPLES PHOTOS SUR GITHUB
    if ($action === 'upload_photo' && isset($_FILES['photos'])) {
        $selectedCats = $_POST['categories'] ?? [];
        $selectedSubcats = $_POST['subcats'] ?? [];

        $categoryStr = implode(', ', $selectedCats);
        $subcatStr = implode(', ', $selectedSubcats);

        $baseTitle = htmlspecialchars($_POST['title'] ?? 'Photo');
        $files = $_FILES['photos'];

        $primaryCat = !empty($selectedCats) ? $selectedCats[0] : 'famille';
        $currentPhotos = file_exists($photosFile) ? json_decode(file_get_contents($photosFile), true) : [];

        $successCount = 0;
        $errorCount = 0;

        // Boucle sur chaque fichier envoyé
        for ($i = 0; $i < count($files['name']); $i++) {
            if ($files['error'][$i] === UPLOAD_ERR_OK) {
                $fileTmpPath = $files['tmp_name'][$i];
                $originalName = basename($files['name'][$i]);
                $fileName = time() . '_' . $i . '_' . preg_replace('/[^a-zA-Z0-9_\.-]/', '', $originalName);
                $targetPath = 'app/assets/' . $primaryCat . '/' . $fileName;

                $fileData = file_get_contents($fileTmpPath);
                $base64Data = base64_encode($fileData);

                $token = $_ENV['GITHUB_TOKEN'] ?? '';
                $repo = $_ENV['GITHUB_REPO'] ?? 'Sparkone16/naybel';
                $branch = $_ENV['GITHUB_BRANCH'] ?? 'main';

                $url = "https://api.github.com/repos/{$repo}/contents/{$targetPath}";

                $payload = json_encode([
                    "message" => "Admin upload multiple: Ajout de " . $fileName,
                    "content" => $base64Data,
                    "branch" => $branch
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
                    $rawUrl = "app/assets/" . $primaryCat . "/" . $fileName;
                    // S'il y a plusieurs photos, on peut numéroter le titre ou garder le titre de base
                    $photoTitle = (count($files['name']) > 1) ? $baseTitle . ' (' . ($i + 1) . ')' : $baseTitle;

                    $newPhotoData = [
                        "src" => $rawUrl,
                        "cat" => $categoryStr,
                        "subcat" => $subcatStr,
                        "titre" => $photoTitle
                    ];

                    array_unshift($currentPhotos, $newPhotoData);
                    $successCount++;
                } else {
                    $errorCount++;
                }
            }
        }

        // Sauvegarde globale du JSON une seule fois après la boucle
        if ($successCount > 0) {
            file_put_contents($photosFile, json_encode($currentPhotos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $successMsg = "{$successCount} photo(s) envoyée(s) avec succès sur GitHub !";
        }
        if ($errorCount > 0) {
            $errorMsg = "Échec pour {$errorCount} photo(s). Vérifiez votre token GitHub ou la taille des fichiers.";
        }
    }
    // 4. MODIFICATION D'UNE PHOTO EXISTANTE
    if ($action === 'update_photo') {
        $photoIndex = $_POST['photo_index'] ?? null;
        $newCats = $_POST['edit_categories'] ?? [];
        $newSubcats = $_POST['edit_subcats'] ?? [];
        $newTitle = htmlspecialchars($_POST['edit_title'] ?? '');

        $currentPhotos = file_exists($photosFile) ? json_decode(file_get_contents($photosFile), true) : [];

        if ($photoIndex !== null && isset($currentPhotos[$photoIndex])) {
            $currentPhotos[$photoIndex]['cat'] = implode(', ', $newCats);
            $currentPhotos[$photoIndex]['subcat'] = implode(', ', $newSubcats);
            if (!empty($newTitle)) {
                $currentPhotos[$photoIndex]['titre'] = $newTitle;
            }

            file_put_contents($photosFile, json_encode($currentPhotos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $successMsg = "La photo a été mise à jour avec succès !";
        } else {
            $errorMsg = "Impossible de trouver la photo à modifier.";
        }
    }
    // 5. SUPPRESSION D'UNE PHOTO EXISTANTE
    if ($action === 'delete_photo') {
        $photoIndex = $_POST['photo_index'] ?? null;
        $currentPhotos = file_exists($photosFile) ? json_decode(file_get_contents($photosFile), true) : [];

        if ($photoIndex !== null && isset($currentPhotos[$photoIndex])) {
            // Optionnel : Vous pouvez aussi supprimer le fichier sur GitHub via l'API si besoin, 
            // mais ici on supprime l'entrée du tableau JSON pour retirer la photo de la galerie.
            array_splice($currentPhotos, $photoIndex, 1);

            file_put_contents($photosFile, json_encode($currentPhotos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $successMsg = "La photo a été supprimée avec succès !";
        } else {
            $errorMsg = "Impossible de trouver la photo à supprimer.";
        }
    }
    // 6. MODIFICATION DE LA SECTION À PROPOS
    if ($action === 'update_about') {
        $newAboutData = [
            "subtitle" => trim($_POST['about_subtitle'] ?? ''),
            "title_part1" => trim($_POST['about_title_part1'] ?? ''),
            "title_part2" => trim($_POST['about_title_part2'] ?? ''),
            "paragraph" => trim($_POST['about_paragraph'] ?? '')
        ];

        file_put_contents('about.json', json_encode($newAboutData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $successMsg = "La section 'À propos' a été mise à jour avec succès !";
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
                <a href="https://naybel.fr"
                    class="text-xs uppercase tracking-widest text-ivory/60 hover:text-ivory transition-colors">Voir le
                    site</a>
                <a href="admin.php?logout=true"
                    class="text-xs uppercase tracking-widest text-red-400 hover:text-red-300 transition-colors">Déconnexion</a>
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
                        <label for="new_category"
                            class="absolute left-0 top-3 text-ivory/50 text-xs uppercase tracking-widest transition-all peer-placeholder-shown:text-sm peer-placeholder-shown:top-3 peer-focus:-top-4 peer-focus:text-xs peer-focus:text-sienna peer-valid:-top-4 peer-valid:text-xs">
                            Nom de la catégorie
                        </label>
                    </div>
                    <button type="submit"
                        class="w-full border border-ivory/30 py-3 text-xs uppercase tracking-widest text-ivory hover:bg-sienna hover:border-sienna transition-all">
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
                        <select name="target_category" required
                            class="w-full bg-dark border-b border-ivory/20 py-3 text-ivory focus:outline-none focus:border-sienna transition-colors text-sm">
                            <option value="" disabled selected>Sélectionner la catégorie parente</option>
                            <?php foreach ($categoriesData as $catName => $subcats): ?>
                                <option value="<?php echo $catName; ?>" class="bg-dark text-ivory">
                                    <?php echo ucfirst($catName); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="relative">
                        <input type="text" id="new_subcat" name="new_subcat" required placeholder=" "
                            class="w-full bg-transparent border-b border-ivory/20 py-3 text-ivory focus:outline-none focus:border-sienna transition-colors peer placeholder-transparent text-sm">
                        <label for="new_subcat"
                            class="absolute left-0 top-3 text-ivory/50 text-xs uppercase tracking-widest transition-all peer-placeholder-shown:text-sm peer-placeholder-shown:top-3 peer-focus:-top-4 peer-focus:text-xs peer-focus:text-sienna peer-valid:-top-4 peer-valid:text-xs">
                            Nom de la sous-catégorie
                        </label>
                    </div>
                    <button type="submit"
                        class="w-full border border-ivory/30 py-3 text-xs uppercase tracking-widest text-ivory hover:bg-sienna hover:border-sienna transition-all">
                        Créer la sous-catégorie
                    </button>
                </form>
            </div>

        </div>

        <!-- PANEL 3 : AJOUTER PLUSIEURS PHOTOS -->
        <div class="bg-[#1a1918] p-8 rounded-lg border border-ivory/10 shadow-2xl">
            <h2 class="text-xl font-light tracking-wide mb-6 text-sienna">Uploader des photos</h2>
            <form action="admin.php" method="POST" enctype="multipart/form-data" class="space-y-6">
                <input type="hidden" name="action" value="upload_photo">

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <!-- CATÉGORIES -->
                    <div>
                        <label class="block text-xs uppercase tracking-widest text-sienna font-semibold mb-2">
                            Catégories <span class="text-[10px] text-ivory/50">(Ctrl + clic pour plusieurs)</span>
                        </label>
                        <select id="categorySelect" name="categories[]" multiple required
                            class="w-full bg-dark border-b border-ivory/20 py-3 px-2 text-ivory focus:outline-none focus:border-sienna transition-colors text-sm h-36 cursor-pointer">
                            <?php foreach ($categoriesData as $catName => $subcats): ?>
                                <option value="<?php echo $catName; ?>" class="py-1 px-2 bg-dark text-ivory">
                                    <?php echo ucfirst($catName); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- SOUS-CATÉGORIES DYNAMIQUES -->
                    <div>
                        <label class="block text-xs uppercase tracking-widest text-sienna font-semibold mb-2">
                            Sous-catégories <span class="text-[10px] text-ivory/50">
                            </span>
                        </label>
                        <select id="subcatSelect" name="subcats[]" multiple
                            class="w-full bg-dark border-b border-ivory/20 py-3 px-2 text-ivory focus:outline-none focus:border-sienna transition-colors text-sm h-36 cursor-pointer">
                            <option value="" disabled class="text-ivory/40">Sélectionnez d'abord une catégorie</option>
                        </select>
                    </div>
                </div>

                <div class="relative pt-2">
                    <input type="text" id="title" name="title" required placeholder=" "
                        class="w-full bg-transparent border-b border-ivory/20 py-3 text-ivory focus:outline-none focus:border-sienna transition-colors peer placeholder-transparent text-sm">
                    <label for="title"
                        class="absolute left-0 top-5 text-ivory/50 text-xs uppercase tracking-widest transition-all peer-placeholder-shown:text-sm peer-placeholder-shown:top-5 peer-focus:-top-1 peer-focus:text-xs peer-focus:text-sienna peer-valid:-top-1 peer-valid:text-xs">
                        Titre / Légende de base (sera numéroté si plusieurs)
                    </label>
                </div>

                <div class="pt-2">
                    <label class="block text-xs uppercase tracking-widest text-sienna font-semibold mb-2">Fichiers images (Sélection multiple possible)</label>
                    <input type="file" name="photos[]" accept="image/*" multiple required
                        class="w-full text-sm text-ivory/70 file:mr-4 file:py-2 file:px-4 file:rounded-none file:border file:border-ivory/30 file:text-xs file:uppercase file:tracking-widest file:bg-transparent file:text-ivory hover:file:bg-sienna hover:file:border-sienna transition-all cursor-pointer">
                </div>

                <div class="pt-4 flex justify-end">
                    <button type="submit"
                        class="border border-ivory/30 px-8 py-4 text-xs uppercase tracking-widest text-ivory hover:bg-sienna hover:border-sienna transition-all duration-300">
                        Envoyer les photos
                    </button>
                </div>
            </form>
        </div>

        <!-- PANEL 4 : MODIFIER UNE PHOTO EXISTANTE -->
        <div class="bg-[#1a1918] p-8 rounded-lg border border-ivory/10 shadow-2xl">
            <h2 class="text-xl font-light tracking-wide mb-6 text-sienna">Modifier une photo existante</h2>

            <!-- ÉTAPE 1 : FILTRER PAR CATÉGORIE / SOUS-CATÉGORIE -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                <div>
                    <label class="block text-xs uppercase tracking-widest text-sienna font-semibold mb-2">Filtrer par
                        catégorie</label>
                    <select id="filterCategory"
                        class="w-full bg-dark border-b border-ivory/20 py-3 text-ivory focus:outline-none focus:border-sienna transition-colors text-sm">
                        <option value="all">Toutes les catégories</option>
                        <?php foreach ($categoriesData as $catName => $subcats): ?>
                            <option value="<?php echo $catName; ?>" class="bg-dark text-ivory">
                                <?php echo ucfirst($catName); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs uppercase tracking-widest text-sienna font-semibold mb-2">Filtrer par
                        sous-catégorie</label>
                    <select id="filterSubcat"
                        class="w-full bg-dark border-b border-ivory/20 py-3 text-ivory focus:outline-none focus:border-sienna transition-colors text-sm">
                        <option value="all">Toutes les sous-catégories</option>
                    </select>
                </div>
            </div>

            <!-- ÉTAPE 2 : LISTE DES PHOTOS CORRESPONDANTES AVEC APERÇU -->
            <div class="mb-8">
                <label class="block text-xs uppercase tracking-widest text-sienna font-semibold mb-3">Sélectionnez une
                    photo à modifier</label>
                <div id="photoListContainer"
                    class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-4 max-h-64 overflow-y-auto p-2 bg-dark border border-ivory/10 rounded">
                    <!-- Rempli dynamiquement par JS -->
                </div>
            </div>

            <!-- ÉTAPE 3 : FORMULAIRE DE MODIFICATION ET SUPPRESSION -->
            <div id="editFormContainer" class="hidden border-t border-ivory/10 pt-6 mt-6">
                <h3 class="text-sm font-semibold uppercase tracking-widest text-sienna mb-4">Modifier ou supprimer la
                    photo</h3>

                <!-- Formulaire unique qui gère l'action (mise à jour ou suppression) -->
                <form id="photoActionForm" action="admin.php" method="POST" class="space-y-6">
                    <input type="hidden" id="formAction" name="action" value="update_photo">
                    <input type="hidden" id="editPhotoIndex" name="photo_index" value="">

                    <!-- Aperçu et titre actuel -->
                    <div class="flex items-center space-x-6 bg-dark p-4 border border-ivory/10">
                        <img id="editPreviewImg" src="" alt="Aperçu"
                            class="w-24 h-24 object-cover border border-ivory/20">
                        <div class="flex-1">
                            <label class="block text-[10px] uppercase tracking-widest text-ivory/50 mb-1">Titre de la
                                photo</label>
                            <input type="text" id="editTitleInput" name="edit_title" required
                                class="w-full bg-transparent border-b border-ivory/20 py-2 text-ivory focus:outline-none focus:border-sienna text-sm">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <!-- Modification des Catégories -->
                        <div>
                            <label class="block text-xs uppercase tracking-widest text-sienna font-semibold mb-2">
                                Catégories principales <span class="text-[10px] text-ivory/50">(Ctrl + clic pour
                                    plusieurs)</span>
                            </label>
                            <select id="editCategories" name="edit_categories[]" multiple required
                                class="w-full bg-dark border border-ivory/20 p-3 text-ivory focus:outline-none focus:border-sienna transition-colors text-sm h-32 cursor-pointer">
                                <?php foreach ($categoriesData as $catName => $subcats): ?>
                                    <option value="<?php echo $catName; ?>" class="py-1 px-2 bg-dark text-ivory">
                                        <?php echo ucfirst($catName); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Modification des Sous-catégories -->
                        <div>
                            <label class="block text-xs uppercase tracking-widest text-sienna font-semibold mb-2">
                                Sous-catégories <span class="text-[10px] text-ivory/50">(S'adaptent aux catégories
                                    choisies)</span>
                            </label>
                            <select id="editSubcats" name="edit_subcats[]" multiple
                                class="w-full bg-dark border border-ivory/20 p-3 text-ivory focus:outline-none focus:border-sienna transition-colors text-sm h-32 cursor-pointer">
                                <option value="" disabled class="text-ivory/40">Sélectionnez d'abord une catégorie
                                </option>
                            </select>
                        </div>
                    </div>

                    <div class="flex justify-between items-center pt-4 border-t border-ivory/10">
                        <!-- Bouton Supprimer grisé par défaut -->
                        <button type="button" id="deletePhotoBtn" disabled onclick="confirmDeletion()"
                            class="border border-red-500/30 px-6 py-3 text-xs uppercase tracking-widest text-red-400 opacity-50 cursor-not-allowed transition-all">
                            Supprimer la photo
                        </button>

                        <button type="submit"
                            class="border border-ivory/30 px-8 py-3 text-xs uppercase tracking-widest text-ivory hover:bg-sienna hover:border-sienna transition-all">
                            Enregistrer les modifications
                        </button>
                    </div>
                </form>
            </div>
        </div>
        <!-- PANEL 5 : MODIFIER LA SECTION À PROPOS -->
        <div class="bg-[#1a1918] p-8 rounded-lg border border-ivory/10 shadow-2xl">
            <h2 class="text-xl font-light tracking-wide mb-6 text-sienna">Modifier la section "À propos"</h2>
            <form action="admin.php" method="POST" class="space-y-6">
                <input type="hidden" name="action" value="update_about">

                <div class="relative pt-2">
                    <input type="text" id="about_subtitle" name="about_subtitle" required placeholder=" "
                        value="<?php echo htmlspecialchars($aboutData['subtitle'] ?? ''); ?>"
                        class="w-full bg-transparent border-b border-ivory/20 py-3 text-ivory focus:outline-none focus:border-sienna transition-colors peer placeholder-transparent text-sm">
                    <label for="about_subtitle" class="absolute left-0 top-5 text-ivory/50 text-xs uppercase tracking-widest transition-all peer-placeholder-shown:text-sm peer-placeholder-shown:top-5 peer-focus:-top-1 peer-focus:text-xs peer-focus:text-sienna peer-valid:-top-1 peer-valid:text-xs">
                        Sous-titre (ex: L'ADN Naybel)
                    </label>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="relative pt-2">
                        <input type="text" id="about_title_part1" name="about_title_part1" required placeholder=" "
                            value="<?php echo htmlspecialchars($aboutData['title_part1'] ?? ''); ?>"
                            class="w-full bg-transparent border-b border-ivory/20 py-3 text-ivory focus:outline-none focus:border-sienna transition-colors peer placeholder-transparent text-sm">
                        <label for="about_title_part1" class="absolute left-0 top-5 text-ivory/50 text-xs uppercase tracking-widest transition-all peer-placeholder-shown:text-sm peer-placeholder-shown:top-5 peer-focus:-top-1 peer-focus:text-xs peer-focus:text-sienna peer-valid:-top-1 peer-valid:text-xs">
                            Titre - 1ère partie
                        </label>
                    </div>

                    <div class="relative pt-2">
                        <input type="text" id="about_title_part2" name="about_title_part2" required placeholder=" "
                            value="<?php echo htmlspecialchars($aboutData['title_part2'] ?? ''); ?>"
                            class="w-full bg-transparent border-b border-ivory/20 py-3 text-ivory focus:outline-none focus:border-sienna transition-colors peer placeholder-transparent text-sm">
                        <label for="about_title_part2" class="absolute left-0 top-5 text-ivory/50 text-xs uppercase tracking-widest transition-all peer-placeholder-shown:text-sm peer-placeholder-shown:top-5 peer-focus:-top-1 peer-focus:text-xs peer-focus:text-sienna peer-valid:-top-1 peer-valid:text-xs">
                            Titre - 2ème partie (à la ligne)
                        </label>
                    </div>
                </div>

                <div class="pt-2">
                    <label class="block text-xs uppercase tracking-widest text-sienna font-semibold mb-2">Paragraphe de description</label>
                    <textarea id="about_paragraph" name="about_paragraph" rows="4" required
                        class="w-full bg-dark border border-ivory/20 p-3 text-ivory focus:outline-none focus:border-sienna transition-colors text-sm"><?php echo htmlspecialchars($aboutData['paragraph'] ?? ''); ?></textarea>
                </div>

                <div class="pt-4 flex justify-end">
                    <button type="submit" class="border border-ivory/30 px-8 py-3 text-xs uppercase tracking-widest text-ivory hover:bg-sienna hover:border-sienna transition-all">
                        Enregistrer les modifications
                    </button>
                </div>
            </form>
        </div>

    </div>
    <script>
        const allPhotos = <?php echo json_encode($photosFile && file_exists($photosFile) ? json_decode(file_get_contents($photosFile), true) : [], JSON_UNESCAPED_UNICODE); ?>;
        const categoriesDataEdit = <?php echo json_encode($categoriesData, JSON_UNESCAPED_UNICODE); ?>;

        const filterCategory = document.getElementById('filterCategory');
        const filterSubcat = document.getElementById('filterSubcat');
        const photoListContainer = document.getElementById('photoListContainer');
        const editFormContainer = document.getElementById('editFormContainer');
        const editPhotoIndex = document.getElementById('editPhotoIndex');
        const editPreviewImg = document.getElementById('editPreviewImg');
        const editTitleInput = document.getElementById('editTitleInput');
        const editCategories = document.getElementById('editCategories');
        const editSubcats = document.getElementById('editSubcats');
        const deletePhotoBtn = document.getElementById('deletePhotoBtn');
        const photoActionForm = document.getElementById('photoActionForm');
        const formAction = document.getElementById('formAction');

        // Fonction de confirmation de suppression
        function confirmDeletion() {
            if (confirm("Êtes-vous sûr de vouloir supprimer cette photo ? Cette action est irréversible.")) {
                formAction.value = 'delete_photo'; // Change l'action du formulaire en suppression
                photoActionForm.submit();
            }
        }

        // Fonction pour mettre à jour dynamiquement les sous-catégories
        function updateEditSubcategories(preselectedSubs = []) {
            const selectedCategories = Array.from(editCategories.selectedOptions).map(opt => opt.value);
            editSubcats.innerHTML = '';

            if (selectedCategories.length === 0) {
                editSubcats.innerHTML = '<option value="" disabled class="text-ivory/40">Sélectionnez d\'abord une catégorie</option>';
                return;
            }

            let hasSubcats = false;

            selectedCategories.forEach(cat => {
                if (categoriesDataEdit[cat] && categoriesDataEdit[cat].length > 0) {
                    categoriesDataEdit[cat].forEach(sub => {
                        hasSubcats = true;
                        if (!Array.from(editSubcats.options).some(opt => opt.value === sub.id)) {
                            const option = document.createElement('option');
                            option.value = sub.id;
                            option.textContent = `${sub.label} (${cat.charAt(0).toUpperCase() + cat.slice(1)})`;
                            option.className = "py-1 px-2 bg-dark text-ivory";

                            if (preselectedSubs.includes(sub.id)) {
                                option.selected = true;
                            }
                            editSubcats.appendChild(option);
                        }
                    });
                }
            });

            if (!hasSubcats) {
                editSubcats.innerHTML = '<option value="" disabled class="text-ivory/40">Aucune sous-catégorie disponible</option>';
            }
        }

        editCategories.addEventListener('change', () => {
            const currentSelectedSubs = Array.from(editSubcats.selectedOptions).map(opt => opt.value);
            updateEditSubcategories(currentSelectedSubs);
        });

        filterCategory.addEventListener('change', function () {
            const cat = this.value;
            filterSubcat.innerHTML = '<option value="all">Toutes les sous-catégories</option>';
            if (categoriesDataEdit[cat]) {
                categoriesDataEdit[cat].forEach(sub => {
                    const opt = document.createElement('option');
                    opt.value = sub.id;
                    opt.textContent = sub.label;
                    opt.className = "bg-dark text-ivory";
                    filterSubcat.appendChild(opt);
                });
            }
            renderPhotoList();
        });

        filterSubcat.addEventListener('change', renderPhotoList);

        function renderPhotoList() {
            photoListContainer.innerHTML = '';
            const selectedCat = filterCategory.value;
            const selectedSub = filterSubcat.value;

            allPhotos.forEach((photo, index) => {
                const cats = photo.cat.split(',').map(c => c.trim());
                const subs = photo.subcat ? photo.subcat.split(',').map(s => s.trim()) : [];

                const matchCat = (selectedCat === 'all' || cats.includes(selectedCat));
                const matchSub = (selectedSub === 'all' || subs.includes(selectedSub));

                if (matchCat && matchSub) {
                    const imgSrc = photo.src.startsWith('http') ? photo.src : 'https://raw.githubusercontent.com/Sparkone16/naybel/refs/heads/main/' + photo.src;

                    const card = document.createElement('div');
                    card.className = "relative group cursor-pointer border border-ivory/10 hover:border-sienna transition-all overflow-hidden bg-dark p-1";
                    card.innerHTML = `
                    <img src="${imgSrc}" alt="${photo.titre}" class="w-full h-24 object-cover">
                    <div class="text-[10px] text-ivory/70 truncate p-1 text-center">${photo.titre}</div>
                `;

                    card.addEventListener('click', () => {
                        editFormContainer.classList.remove('hidden');
                        editPhotoIndex.value = index;
                        editPreviewImg.src = imgSrc;
                        editTitleInput.value = photo.titre;
                        formAction.value = 'update_photo'; // Réinitialise l'action à la mise à jour par sécurité

                        // Activer le bouton supprimer (retirer l'état grisé)
                        deletePhotoBtn.removeAttribute('disabled');
                        deletePhotoBtn.classList.remove('opacity-50', 'cursor-not-allowed');
                        deletePhotoBtn.classList.add('hover:bg-red-500', 'hover:text-white');

                        Array.from(editCategories.options).forEach(opt => {
                            opt.selected = cats.includes(opt.value);
                        });

                        updateEditSubcategories(subs);
                        editFormContainer.scrollIntoView({ behavior: 'smooth' });
                    });

                    photoListContainer.appendChild(card);
                }
            });

            if (photoListContainer.children.length === 0) {
                photoListContainer.innerHTML = '<div class="col-span-full text-center text-xs text-ivory/40 py-8">Aucune photo trouvée pour ces filtres.</div>';
            }
        }

        renderPhotoList();
    </script>
</body>

</html>