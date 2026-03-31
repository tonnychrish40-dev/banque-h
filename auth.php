<?php
// ==============================================
// AUTHENTIFICATION - auth.php
// ==============================================
require_once 'config.php';

$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {

    // ---- INSCRIPTION ----
    case 'inscription':
        $nom    = sanitize($_POST['nom'] ?? '');
        $prenom = sanitize($_POST['prenom'] ?? '');
        $email  = filter_var($_POST['email'] ?? '', FILTER_SANITIZE_EMAIL);
        $mdp    = $_POST['mot_de_passe'] ?? '';
        $tel    = sanitize($_POST['telephone'] ?? '');
        $adresse = sanitize($_POST['adresse'] ?? '');
        $type_compte = $_POST['type_compte'] ?? 'courant';

        if (!$nom || !$prenom || !$email || !$mdp) {
            echo json_encode(['success' => false, 'message' => 'Tous les champs obligatoires doivent être remplis.']);
            break;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'message' => 'Email invalide.']);
            break;
        }
        if (strlen($mdp) < 6) {
            echo json_encode(['success' => false, 'message' => 'Le mot de passe doit faire au moins 6 caractères.']);
            break;
        }
        if (!in_array($type_compte, ['courant', 'epargne'])) {
            $type_compte = 'courant';
        }

        $pdo = getDB();
        $stmt = $pdo->prepare("SELECT id FROM utilisateurs WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Cet email est déjà utilisé.']);
            break;
        }

        $hash = password_hash($mdp, PASSWORD_BCRYPT);
        $pdo->beginTransaction();
        try {
            $pdo->prepare("INSERT INTO utilisateurs (nom, prenom, email, mot_de_passe, telephone, adresse) VALUES (?, ?, ?, ?, ?, ?)")
                ->execute([$nom, $prenom, $email, $hash, $tel, $adresse]);
            $userId = $pdo->lastInsertId();
            $numero = genererNumeroCompte();
            $pdo->prepare("INSERT INTO comptes (utilisateur_id, numero_compte, type_compte, solde) VALUES (?, ?, ?, 0.00)")
                ->execute([$userId, $numero, $type_compte]);
            
            ajouterNotification($userId, 'Bienvenue !', "Votre compte bancaire ($numero) a été créé avec succès.", 'succes');
            $pdo->commit();
            echo json_encode(['success' => true, 'message' => "Compte créé avec succès ! Numéro : $numero"]);
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Erreur lors de la création du compte.']);
        }
        break;

    // ---- CONNEXION ----
    case 'connexion':
        $email = filter_var($_POST['email'] ?? '', FILTER_SANITIZE_EMAIL);
        $mdp   = $_POST['mot_de_passe'] ?? '';

        if (!$email || !$mdp) {
            echo json_encode(['success' => false, 'message' => 'Email et mot de passe requis.']);
            break;
        }

        $pdo = getDB();
        $stmt = $pdo->prepare("SELECT * FROM utilisateurs WHERE email = ? AND statut = 'actif'");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($mdp, $user['mot_de_passe'])) {
            echo json_encode(['success' => false, 'message' => 'Email ou mot de passe incorrect.']);
            break;
        }

        $pdo->prepare("UPDATE utilisateurs SET derniere_connexion = NOW() WHERE id = ?")->execute([$user['id']]);

        $_SESSION['utilisateur_id'] = $user['id'];
        $_SESSION['nom']            = $user['prenom'] . ' ' . $user['nom'];
        $_SESSION['email']          = $user['email'];
        $_SESSION['role']           = $user['role'];
        session_regenerate_id(true);

        echo json_encode([
            'success'  => true,
            'role'     => $user['role'],
            'message'  => 'Connexion réussie.',
            'redirect' => $user['role'] === 'admin' ? 'admin.html' : 'dashboard.html'
        ]);
        break;

    // ---- DÉCONNEXION ----
    case 'deconnexion':
        session_destroy();
        echo json_encode(['success' => true, 'message' => 'Déconnecté.']);
        break;

    // ---- VÉRIFIER SESSION ----
    case 'verifier_session':
        if (estConnecte()) {
            echo json_encode([
                'success'  => true,
                'connecte' => true,
                'nom'      => $_SESSION['nom'],
                'role'     => $_SESSION['role']
            ]);
        } else {
            echo json_encode(['success' => true, 'connecte' => false]);
        }
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'Action inconnue.']);
}
