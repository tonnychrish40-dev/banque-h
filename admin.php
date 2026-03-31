<?php
// ==============================================
// OPÉRATIONS ADMIN - admin.php
// ==============================================
require_once 'config.php';
requireConnexion();
requireAdmin();

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$pdo = getDB();

switch ($action) {

    // ---- TABLEAU DE BORD ADMIN ----
    case 'dashboard':
        $stats = [];

        $r = $pdo->query("SELECT COUNT(*) as nb, SUM(c.solde) as total_soldes FROM utilisateurs u JOIN comptes c ON c.utilisateur_id = u.id WHERE u.role = 'client'");
        $stats['clients'] = $r->fetch();

        $r = $pdo->query("SELECT COUNT(*) as nb, COALESCE(SUM(montant), 0) as total FROM transactions WHERE type_transaction = 'depot' AND DATE(date_transaction) = CURDATE()");
        $stats['depots_jour'] = $r->fetch();

        $r = $pdo->query("SELECT COUNT(*) as nb, COALESCE(SUM(montant), 0) as total FROM transactions WHERE type_transaction = 'retrait' AND DATE(date_transaction) = CURDATE()");
        $stats['retraits_jour'] = $r->fetch();

        $r = $pdo->query("SELECT COUNT(*) as nb, COALESCE(SUM(montant), 0) as total FROM transactions WHERE type_transaction = 'virement' AND DATE(date_transaction) = CURDATE()");
        $stats['virements_jour'] = $r->fetch();

        $r = $pdo->query("SELECT COUNT(*) as nb FROM demandes_depot WHERE statut = 'en_attente'");
        $stats['demandes_en_attente'] = $r->fetch()['nb'];

        $r = $pdo->query("SELECT COUNT(*) as nb FROM transactions WHERE MONTH(date_transaction) = MONTH(NOW())");
        $stats['transactions_mois'] = $r->fetch()['nb'];

        echo json_encode(['success' => true, 'stats' => $stats]);
        break;

    // ---- LISTE DES CLIENTS ----
    case 'clients':
        $stmt = $pdo->query("
            SELECT u.id, u.nom, u.prenom, u.email, u.telephone, u.statut, u.date_creation, u.derniere_connexion,
                c.id as compte_id, c.numero_compte, c.type_compte, c.solde, c.statut as statut_compte
            FROM utilisateurs u
            LEFT JOIN comptes c ON c.utilisateur_id = u.id
            WHERE u.role = 'client'
            ORDER BY u.date_creation DESC
        ");
        echo json_encode(['success' => true, 'clients' => $stmt->fetchAll()]);
        break;

    // ---- TOUTES LES TRANSACTIONS ----
    case 'transactions':
        $type = $_GET['type'] ?? 'toutes';
        $limit = intval($_GET['limit'] ?? 50);

        $sql = "
            SELECT t.*, 
                cs.numero_compte AS compte_source_numero,
                cd.numero_compte AS compte_dest_numero,
                CONCAT(us.prenom, ' ', us.nom) AS nom_source,
                CONCAT(ud.prenom, ' ', ud.nom) AS nom_dest,
                CONCAT(ua.prenom, ' ', ua.nom) AS effectue_par_nom
            FROM transactions t
            LEFT JOIN comptes cs ON t.compte_source_id = cs.id
            LEFT JOIN comptes cd ON t.compte_destination_id = cd.id
            LEFT JOIN utilisateurs us ON cs.utilisateur_id = us.id
            LEFT JOIN utilisateurs ud ON cd.utilisateur_id = ud.id
            LEFT JOIN utilisateurs ua ON t.effectue_par = ua.id
        ";

        if ($type !== 'toutes' && in_array($type, ['depot','retrait','virement'])) {
            $sql .= " WHERE t.type_transaction = " . $pdo->quote($type);
        }
        $sql .= " ORDER BY t.date_transaction DESC LIMIT " . $limit;

        $stmt = $pdo->query($sql);
        echo json_encode(['success' => true, 'transactions' => $stmt->fetchAll()]);
        break;

    // ---- DÉPÔT SUR UN COMPTE ----
    case 'depot':
        $compte_id  = intval($_POST['compte_id'] ?? 0);
        $montant    = floatval($_POST['montant'] ?? 0);
        $description = sanitize($_POST['description'] ?? 'Dépôt administrateur');

        if ($montant <= 0 || !$compte_id) {
            echo json_encode(['success' => false, 'message' => 'Données invalides.']);
            break;
        }

        $stmt = $pdo->prepare("SELECT c.*, u.id as user_id FROM comptes c JOIN utilisateurs u ON c.utilisateur_id = u.id WHERE c.id = ? AND c.statut = 'actif'");
        $stmt->execute([$compte_id]);
        $compte = $stmt->fetch();

        if (!$compte) {
            echo json_encode(['success' => false, 'message' => 'Compte introuvable.']);
            break;
        }

        $pdo->beginTransaction();
        try {
            $nouveau_solde = $compte['solde'] + $montant;
            $pdo->prepare("UPDATE comptes SET solde = ? WHERE id = ?")->execute([$nouveau_solde, $compte_id]);
            $ref = genererReference();
            $pdo->prepare("INSERT INTO transactions (compte_destination_id, type_transaction, montant, solde_avant, solde_apres, description, reference, effectue_par) VALUES (?, 'depot', ?, ?, ?, ?, ?, ?)")
                ->execute([$compte_id, $montant, $compte['solde'], $nouveau_solde, $description, $ref, $_SESSION['utilisateur_id']]);

            ajouterNotification($compte['user_id'], 'Dépôt reçu', "Un dépôt de " . number_format($montant, 2) . " € a été effectué sur votre compte. Nouveau solde : " . number_format($nouveau_solde, 2) . " €", 'succes');
            $pdo->commit();
            echo json_encode(['success' => true, 'message' => "Dépôt de " . number_format($montant, 2) . " € effectué.", 'nouveau_solde' => $nouveau_solde, 'reference' => $ref]);
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Erreur lors du dépôt.']);
        }
        break;

    // ---- TRAITEMENT DEMANDES DÉPÔT ----
    case 'demandes_depot':
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $stmt = $pdo->query("
                SELECT d.*, c.numero_compte, c.solde, CONCAT(u.prenom, ' ', u.nom) AS nom_client, u.email
                FROM demandes_depot d
                JOIN comptes c ON d.compte_id = c.id
                JOIN utilisateurs u ON c.utilisateur_id = u.id
                ORDER BY d.date_demande DESC
            ");
            echo json_encode(['success' => true, 'demandes' => $stmt->fetchAll()]);
        } else {
            $demande_id = intval($_POST['demande_id'] ?? 0);
            $statut     = sanitize($_POST['statut'] ?? '');

            if (!in_array($statut, ['approuve', 'refuse'])) {
                echo json_encode(['success' => false, 'message' => 'Statut invalide.']);
                break;
            }

            $stmt = $pdo->prepare("SELECT d.*, c.solde, c.utilisateur_id FROM demandes_depot d JOIN comptes c ON d.compte_id = c.id WHERE d.id = ? AND d.statut = 'en_attente'");
            $stmt->execute([$demande_id]);
            $demande = $stmt->fetch();

            if (!$demande) {
                echo json_encode(['success' => false, 'message' => 'Demande introuvable.']);
                break;
            }

            $pdo->beginTransaction();
            try {
                $pdo->prepare("UPDATE demandes_depot SET statut = ?, traite_par = ?, date_traitement = NOW() WHERE id = ?")
                    ->execute([$statut, $_SESSION['utilisateur_id'], $demande_id]);

                if ($statut === 'approuve') {
                    $nouveau_solde = $demande['solde'] + $demande['montant'];
                    $pdo->prepare("UPDATE comptes SET solde = ? WHERE id = ?")->execute([$nouveau_solde, $demande['compte_id']]);
                    $ref = genererReference();
                    $pdo->prepare("INSERT INTO transactions (compte_destination_id, type_transaction, montant, solde_avant, solde_apres, description, reference, effectue_par) VALUES (?, 'depot', ?, ?, ?, 'Dépôt approuvé', ?, ?)")
                        ->execute([$demande['compte_id'], $demande['montant'], $demande['solde'], $nouveau_solde, $ref, $_SESSION['utilisateur_id']]);
                    ajouterNotification($demande['utilisateur_id'], 'Demande approuvée', "Votre demande de dépôt de " . number_format($demande['montant'], 2) . " € a été approuvée.", 'succes');
                } else {
                    ajouterNotification($demande['utilisateur_id'], 'Demande refusée', "Votre demande de dépôt de " . number_format($demande['montant'], 2) . " € a été refusée.", 'erreur');
                }
                $pdo->commit();
                echo json_encode(['success' => true, 'message' => 'Demande ' . ($statut === 'approuve' ? 'approuvée' : 'refusée') . '.']);
            } catch (Exception $e) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'message' => 'Erreur.']);
            }
        }
        break;

    // ---- SUSPENDRE / ACTIVER UN COMPTE ----
    case 'toggle_compte':
        $compte_id = intval($_POST['compte_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT statut, utilisateur_id FROM comptes WHERE id = ?");
        $stmt->execute([$compte_id]);
        $compte = $stmt->fetch();
        if (!$compte) { echo json_encode(['success' => false, 'message' => 'Compte introuvable.']); break; }
        $nouveau = $compte['statut'] === 'actif' ? 'suspendu' : 'actif';
        $pdo->prepare("UPDATE comptes SET statut = ? WHERE id = ?")->execute([$nouveau, $compte_id]);
        ajouterNotification($compte['utilisateur_id'], 'Compte ' . $nouveau, "Votre compte a été " . ($nouveau === 'actif' ? 'réactivé' : 'suspendu') . " par l'administrateur.", $nouveau === 'actif' ? 'succes' : 'avertissement');
        echo json_encode(['success' => true, 'message' => 'Compte ' . $nouveau . '.', 'nouveau_statut' => $nouveau]);
        break;

    // ---- RECHERCHE CLIENT ----
    case 'recherche_client':
        $q = '%' . sanitize($_GET['q'] ?? '') . '%';
        $stmt = $pdo->prepare("
            SELECT u.id, u.nom, u.prenom, u.email, c.id as compte_id, c.numero_compte, c.solde
            FROM utilisateurs u JOIN comptes c ON c.utilisateur_id = u.id
            WHERE u.role = 'client' AND (u.nom LIKE ? OR u.prenom LIKE ? OR u.email LIKE ? OR c.numero_compte LIKE ?)
            LIMIT 10
        ");
        $stmt->execute([$q, $q, $q, $q]);
        echo json_encode(['success' => true, 'clients' => $stmt->fetchAll()]);
        break;

    // ---- RAPPORT MENSUEL ----
    case 'rapport_mensuel':
        $mois = $_GET['mois'] ?? date('Y-m');
        $stmt = $pdo->prepare("
            SELECT type_transaction, COUNT(*) as nb, COALESCE(SUM(montant), 0) as total
            FROM transactions WHERE DATE_FORMAT(date_transaction, '%Y-%m') = ?
            GROUP BY type_transaction
        ");
        $stmt->execute([$mois]);
        echo json_encode(['success' => true, 'rapport' => $stmt->fetchAll()]);
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'Action inconnue.']);
}
