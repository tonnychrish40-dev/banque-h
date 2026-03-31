-- ==============================================
-- BANQUE CHRISTOPHE - Base de données MySQL
-- ==============================================

CREATE DATABASE IF NOT EXISTS banque_christophe CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE banque_christophe;

-- Table des utilisateurs
CREATE TABLE IF NOT EXISTS utilisateurs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nom VARCHAR(100) NOT NULL,
    prenom VARCHAR(100) NOT NULL,
    email VARCHAR(150) UNIQUE NOT NULL,
    mot_de_passe VARCHAR(255) NOT NULL,
    telephone VARCHAR(20),
    adresse TEXT,
    role ENUM('client', 'admin') DEFAULT 'client',
    statut ENUM('actif', 'suspendu', 'ferme') DEFAULT 'actif',
    date_creation DATETIME DEFAULT CURRENT_TIMESTAMP,
    derniere_connexion DATETIME NULL
);

-- Table des comptes bancaires
CREATE TABLE IF NOT EXISTS comptes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    utilisateur_id INT NOT NULL,
    numero_compte VARCHAR(20) UNIQUE NOT NULL,
    type_compte ENUM('courant', 'epargne') DEFAULT 'courant',
    solde DECIMAL(15,2) DEFAULT 0.00,
    devise VARCHAR(3) DEFAULT 'EUR',
    statut ENUM('actif', 'suspendu', 'ferme') DEFAULT 'actif',
    date_creation DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (utilisateur_id) REFERENCES utilisateurs(id) ON DELETE CASCADE
);

-- Table des transactions
CREATE TABLE IF NOT EXISTS transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    compte_source_id INT NULL,
    compte_destination_id INT NULL,
    type_transaction ENUM('depot', 'retrait', 'virement', 'frais') NOT NULL,
    montant DECIMAL(15,2) NOT NULL,
    solde_avant DECIMAL(15,2) NOT NULL,
    solde_apres DECIMAL(15,2) NOT NULL,
    description TEXT,
    statut ENUM('en_attente', 'complete', 'echoue', 'annule') DEFAULT 'complete',
    reference VARCHAR(50) UNIQUE NOT NULL,
    effectue_par INT NULL,
    date_transaction DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (compte_source_id) REFERENCES comptes(id),
    FOREIGN KEY (compte_destination_id) REFERENCES comptes(id),
    FOREIGN KEY (effectue_par) REFERENCES utilisateurs(id)
);

-- Table des notifications
CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    utilisateur_id INT NOT NULL,
    titre VARCHAR(200) NOT NULL,
    message TEXT NOT NULL,
    type ENUM('info', 'succes', 'avertissement', 'erreur') DEFAULT 'info',
    lue BOOLEAN DEFAULT FALSE,
    date_creation DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (utilisateur_id) REFERENCES utilisateurs(id) ON DELETE CASCADE
);

-- Table des demandes (dépôts demandés par clients)
CREATE TABLE IF NOT EXISTS demandes_depot (
    id INT AUTO_INCREMENT PRIMARY KEY,
    compte_id INT NOT NULL,
    montant DECIMAL(15,2) NOT NULL,
    motif TEXT,
    statut ENUM('en_attente', 'approuve', 'refuse') DEFAULT 'en_attente',
    traite_par INT NULL,
    date_demande DATETIME DEFAULT CURRENT_TIMESTAMP,
    date_traitement DATETIME NULL,
    FOREIGN KEY (compte_id) REFERENCES comptes(id),
    FOREIGN KEY (traite_par) REFERENCES utilisateurs(id)
);

-- Insertion de l'administrateur
INSERT INTO utilisateurs (nom, prenom, email, mot_de_passe, role, statut) VALUES
('Admin', 'Christophe', 'Christophe@gmail.com', '$2y$10$YourHashedPasswordHere', 'admin', 'actif');

-- Note: Le mot de passe "Christophe 2026" sera haché lors de la première configuration
-- Exécutez ce script PHP pour générer le hash: echo password_hash('Christophe 2026', PASSWORD_BCRYPT);

-- Index pour les performances
CREATE INDEX idx_transactions_compte_source ON transactions(compte_source_id);
CREATE INDEX idx_transactions_compte_dest ON transactions(compte_destination_id);
CREATE INDEX idx_transactions_date ON transactions(date_transaction);
CREATE INDEX idx_comptes_utilisateur ON comptes(utilisateur_id);
CREATE INDEX idx_notifications_utilisateur ON notifications(utilisateur_id);
