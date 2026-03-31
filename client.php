<?php
// ==============================================
// OPÉRATIONS CLIENT - client.php
// ==============================================
require_once 'config.php';
requireConnexion();

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$pdo = getDB();
$userId = $_SESSION['utilisateur_id'];

switch ($action) {

    // ---- TABLEAU DE BORD ----
    case 'dashboard':
        $stmt = $pdo->prepare("
            SELECT c.*, u.nom, u.prenom, u.email, u.telephone, u.derniere_connexion
            FROM comptes c
            JOIN utilisateurs u ON c.utilisateur_id = u.id
            WHERE c.utilisateur_id = ? AND c.statut = 'actif'
        ");
        $stmt->execute([$userId]);
        $compte = $stmt->fetch();

        if (!$compte) {
            echo json_encode(['success' => false, 'message' => 'Aucun compte actif trouvé.']);
            break;
        }

        // Dernières transactions
        $stmtTx = $pdo->prepare("
            SELECT t.*, 
                cs.numero_compte AS compte_source_numero,
                cd.numero_compte AS compte_dest_numero
            FROM transactions t
            LEFT JOIN comptes cs ON t.compte_source_id = cs.id
            LEFT JOIN comptes cd ON t.compte_destination_id = cd.id
            WHERE t.compte_source_id = ? OR t.compte_destination_id = ?
            ORDER BY t.date_transaction DESC LIMIT 10
        ");
        $stmtTx->execute([$compte['id'], $compte['id']]);
        $transactions = $stmtTx->fetchAll();

        // Notifications non lues
        $stmtN = $pdo->prepare("SELECT COUNT(*) as nb FROM notifications WHERE utilisateur_id = ? AND lue = 0");
        $stmtN->execute([$userId]);
        $nb_notifs = $stmtN->fetch()['nb'];

        echo json_encode([
            'success'      => true,
            'compte'       => $compte,
            'transactions' => $transactions,
            'nb_notifs'    => $nb_notifs
        ]);
        break;

    // ---- RETRAIT ----
    case 'retrait':
        $montant     = floatval($_POST['montant'] ?? 0);
        $description = sanitize($_POST['description'] ?? 'Retrait');

        if ($montant <= 0) {
            echo json_encode(['success' => false, 'message' => 'Montant invalide.']);
            break;
        }
        if ($montant > 5000) {
            echo json_encode(['success' => false, 'message' => 'Retrait maximum 5 000 € par opération.']);
            break;
        }

        $stmt = $pdo->prepare("SELECT * FROM comptes WHERE utilisateur_id = ? AND statut = 'actif'");
        $stmt->execute([$userId]);
        $compte = $stmt->fetch();

        if (!$compte) {
            echo json_encode(['success' => false, 'message' => 'Compte introuvable.']);
            break;
        }
        if ($compte['solde'] < $montant) {
            echo json_encode(['success' => false, 'message' => 'Solde insuffisant.']);
            break;
        }

        $pdo->beginTransaction();
        try {
            $nouveau_solde = $compte['solde'] - $montant;
            $pdo->prepare("UPDATE comptes SET solde = ? WHERE id = ?")->execute([$nouveau_solde, $compte['id']]);
            $ref = genererReference();
            $pdo->prepare("INSERT INTO transactions (compte_source_id, type_transaction, montant, solde_avant, solde_apres, description, reference, effectue_par) VALUES (?, 'retrait', ?, ?, ?, ?, ?, ?)")
                ->execute([$compte['id'], $montant, $compte['solde'], $nouveau_solde, $description, $ref, $userId]);

            ajouterNotification($userId, 'Retrait effectué', "Retrait de " . number_format($montant, 2) . " € effectué. Nouveau solde : " . number_format($nouveau_solde, 2) . " €", 'info');
            $pdo->commit();
            echo json_encode(['success' => true, 'message' => "Retrait de " . number_format($montant, 2) . " € effectué.", 'nouveau_solde' => $nouveau_solde, 'reference' => $ref]);
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Erreur lors du retrait.']);
        }
        break;

    // ---- VIREMENT ----
    case 'virement':
        $montant         = floatval($_POST['montant'] ?? 0);
        $numero_dest     = sanitize($_POST['numero_destination'] ?? '');
        $description     = sanitize($_POST['description'] ?? 'Virement');

        if ($montant <= 0) {
            echo json_encode(['success' => false, 'message' => 'Montant invalide.']);
            break;
        }
        if ($montant > 10000) {
            echo json_encode(['success' => false, 'message' => 'Virement maximum 10 000 € par opération.']);
            break;
        }
        if (!$numero_dest) {
            echo json_encode(['success' => false, 'message' => 'Numéro de compte destinataire requis.']);
            break;
        }

        $stmtSrc = $pdo->prepare("SELECT * FROM comptes WHERE utilisateur_id = ? AND statut = 'actif'");
        $stmtSrc->execute([$userId]);
        $source = $stmtSrc->fetch();

        if (!$source) {
            echo json_encode(['success' => false, 'message' => 'Compte source introuvable.']);
            break;
        }
        if ($source['numero_compte'] === $numero_dest) {
            echo json_encode(['success' => false, 'message' => 'Vous ne pouvez pas virer vers votre propre compte.']);
            break;
        }
        if ($source['solde'] < $montant) {
            echo json_encode(['success' => false, 'message' => 'Solde insuffisant.']);
            break;
        }

        $stmtDest = $pdo->prepare("SELECT c.*, u.nom, u.prenom FROM comptes c JOIN utilisateurs u ON c.utilisateur_id = u.id WHERE c.numero_compte = ? AND c.statut = 'actif'");
        $stmtDest->execute([$numero_dest]);
        $dest = $stmtDest->fetch();

        if (!$dest) {
            echo json_encode(['success' => false, 'message' => 'Compte destinataire introuvable ou inactif.']);
            break;
        }

        $pdo->beginTransaction();
        try {
            $solde_src_apres  = $source['solde'] - $montant;
            $solde_dest_apres = $dest['solde'] + $montant;
            $ref = genererReference();

            $pdo->prepare("UPDATE comptes SET solde = ? WHERE id = ?")->execute([$solde_src_apres, $source['id']]);
            $pdo->prepare("UPDATE comptes SET solde = ? WHERE id = ?")->execute([$solde_dest_apres, $dest['id']]);

            $pdo->prepare("INSERT INTO transactions (compte_source_id, compte_destination_id, type_transaction, montant, solde_avant, solde_apres, description, reference, effectue_par) VALUES (?, ?, 'virement', ?, ?, ?, ?, ?, ?)")
                ->execute([$source['id'], $dest['id'], $montant, $source['solde'], $solde_src_apres, $description, $ref, $userId]);

            ajouterNotification($userId, 'Virement envoyé', "Virement de " . number_format($montant, 2) . " € vers " . $dest['prenom'] . ' ' . $dest['nom'] . " effectué.", 'succes');
            ajouterNotification($dest['utilisateur_id'], 'Virement reçu', "Vous avez reçu un virement de " . number_format($montant, 2) . " €.", 'succes');

            $pdo->commit();
            echo json_encode([
                'success'      => true,
                'message'      => "Virement de " . number_format($montant, 2) . " € effectué vers " . $dest['prenom'] . ' ' . $dest['nom'] . ".",
                'nouveau_solde'=> $solde_src_apres,
                'reference'    => $ref
            ]);
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Erreur lors du virement.']);
        }
        break;

    // ---- DEMANDE DE DÉPÔT ----
    case 'demande_depot':
        $montant = floatval($_POST['montant'] ?? 0);
        $motif   = sanitize($_POST['motif'] ?? '');

        if ($montant <= 0) {
            echo json_encode(['success' => false, 'message' => 'Montant invalide.']);
            break;
        }

        $stmt = $pdo->prepare("SELECT id FROM comptes WHERE utilisateur_id = ? AND statut = 'actif'");
        $stmt->execute([$userId]);
        $compte = $stmt->fetch();

        if (!$compte) {
            echo json_encode(['success' => false, 'message' => 'Compte introuvable.']);
            break;
        }

        // Vérifier pas de demande en attente
        $stmtChk = $pdo->prepare("SELECT id FROM demandes_depot WHERE compte_id = ? AND statut = 'en_attente'");
        $stmtChk->execute([$compte['id']]);
        if ($stmtChk->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Vous avez déjà une demande de dépôt en attente.']);
            break;
        }

        $pdo->prepare("INSERT INTO demandes_depot (compte_id, montant, motif) VALUES (?, ?, ?)")
            ->execute([$compte['id'], $montant, $motif]);

        ajouterNotification($userId, 'Demande envoyée', "Votre demande de dépôt de " . number_format($montant, 2) . " € a été soumise à l'administrateur.", 'info');

        echo json_encode(['success' => true, 'message' => 'Demande de dépôt envoyée. L\'administrateur la traitera prochainement.']);
        break;

    // ---- NOTIFICATIONS ----
    case 'notifications':
        $stmt = $pdo->prepare("SELECT * FROM notifications WHERE utilisateur_id = ? ORDER BY date_creation DESC LIMIT 20");
        $stmt->execute([$userId]);
        $notifs = $stmt->fetchAll();

        $pdo->prepare("UPDATE notifications SET lue = 1 WHERE utilisateur_id = ?")->execute([$userId]);

        echo json_encode(['success' => true, 'notifications' => $notifs]);
        break;

    // ---- HISTORIQUE COMPLET ----
    case 'historique':
        $stmt = $pdo->prepare("SELECT id FROM comptes WHERE utilisateur_id = ? AND statut = 'actif'");
        $stmt->execute([$userId]);
        $compte = $stmt->fetch();
        if (!$compte) { echo json_encode(['success' => false, 'message' => 'Compte introuvable.']); break; }

        $stmtTx = $pdo->prepare("
            SELECT t.*, 
                cs.numero_compte AS compte_source_numero,
                cd.numero_compte AS compte_dest_numero,
                us.prenom AS prenom_source, us.nom AS nom_source,
                ud.prenom AS prenom_dest,   ud.nom AS nom_dest
            FROM transactions t
            LEFT JOIN comptes cs ON t.compte_source_id = cs.id
            LEFT JOIN comptes cd ON t.compte_destination_id = cd.id
            LEFT JOIN utilisateurs us ON cs.utilisateur_id = us.id
            LEFT JOIN utilisateurs ud ON cd.utilisateur_id = ud.id
            WHERE t.compte_source_id = ? OR t.compte_destination_id = ?
            ORDER BY t.date_transaction DESC
        ");
        $stmtTx->execute([$compte['id'], $compte['id']]);
        echo json_encode(['success' => true, 'transactions' => $stmtTx->fetchAll()]);
        break;

    // ---- PROFIL ----
    case 'profil':
        $stmt = $pdo->prepare("SELECT u.nom, u.prenom, u.email, u.telephone, u.adresse, u.date_creation, u.derniere_connexion, c.numero_compte, c.type_compte, c.solde, c.devise FROM utilisateurs u LEFT JOIN comptes c ON c.utilisateur_id = u.id WHERE u.id = ?");
        $stmt->execute([$userId]);
        echo json_encode(['success' => true, 'profil' => $stmt->fetch()]);
        break;

    // ---- MODIFIER MOT DE PASSE ----
    case 'changer_mdp':
        $ancien = $_POST['ancien_mdp'] ?? '';
        $nouveau = $_POST['nouveau_mdp'] ?? '';
        if (strlen($nouveau) < 6) { echo json_encode(['success' => false, 'message' => 'Nouveau mot de passe trop court.']); break; }
        $stmt = $pdo->prepare("SELECT mot_de_passe FROM utilisateurs WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        if (!password_verify($ancien, $user['mot_de_passe'])) { echo json_encode(['success' => false, 'message' => 'Ancien mot de passe incorrect.']); break; }
        $pdo->prepare("UPDATE utilisateurs SET mot_de_passe = ? WHERE id = ?")->execute([password_hash($nouveau, PASSWORD_BCRYPT), $userId]);
        echo json_encode(['success' => true, 'message' => 'Mot de passe modifié avec succès.']);
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'Action inconnue.']);
}
