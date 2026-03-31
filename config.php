<?php
// ==============================================
// CONFIGURATION - config.php
// ==============================================

define('DB_HOST', 'localhost');
define('DB_USER', 'root');          // Changez selon votre config
define('DB_PASS', '');              // Changez selon votre config
define('DB_NAME', 'banque_christophe');
define('DB_CHARSET', 'utf8mb4');

define('APP_NAME', 'Banque Christophe');
define('APP_VERSION', '1.0.0');
define('ADMIN_EMAIL', 'Christophe@gmail.com');

// Sécurité sessions
ini_set('session.cookie_httponly', 1);
ini_set('session.use_strict_mode', 1);
session_start();

// Connexion PDO
function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            die(json_encode(['success' => false, 'message' => 'Erreur de connexion à la base de données.']));
        }
    }
    return $pdo;
}

// Générer un numéro de compte unique
function genererNumeroCompte() {
    do {
        $numero = 'FR' . str_pad(mt_rand(0, 99999999999999), 14, '0', STR_PAD_LEFT);
        $pdo = getDB();
        $stmt = $pdo->prepare("SELECT id FROM comptes WHERE numero_compte = ?");
        $stmt->execute([$numero]);
    } while ($stmt->fetch());
    return $numero;
}

// Générer une référence de transaction
function genererReference() {
    return 'TXN' . strtoupper(uniqid()) . mt_rand(100, 999);
}

// Vérifier si connecté
function estConnecte() {
    return isset($_SESSION['utilisateur_id']);
}

// Vérifier si admin
function estAdmin() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

// Rediriger si non connecté
function requireConnexion() {
    if (!estConnecte()) {
        header('Location: index.html');
        exit;
    }
}

// Rediriger si non admin
function requireAdmin() {
    if (!estAdmin()) {
        http_response_code(403);
        die(json_encode(['success' => false, 'message' => 'Accès refusé.']));
    }
}

// Sanitizer les entrées
function sanitize($data) {
    return htmlspecialchars(strip_tags(trim($data)));
}

// Ajouter une notification
function ajouterNotification($utilisateur_id, $titre, $message, $type = 'info') {
    $pdo = getDB();
    $stmt = $pdo->prepare("INSERT INTO notifications (utilisateur_id, titre, message, type) VALUES (?, ?, ?, ?)");
    $stmt->execute([$utilisateur_id, $titre, $message, $type]);
}

// Initialiser l'admin si inexistant
function initAdmin() {
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT id FROM utilisateurs WHERE email = ?");
    $stmt->execute([ADMIN_EMAIL]);
    if (!$stmt->fetch()) {
        $hash = password_hash('Christophe 2026', PASSWORD_BCRYPT);
        $pdo->prepare("INSERT INTO utilisateurs (nom, prenom, email, mot_de_passe, role, statut) VALUES (?, ?, ?, ?, 'admin', 'actif')")
            ->execute(['Admin', 'Christophe', ADMIN_EMAIL, $hash]);
    }
}

initAdmin();

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
