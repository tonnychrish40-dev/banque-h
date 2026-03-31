# 🏦 Banque Christophe — Guide d'installation

## Prérequis
- PHP 7.4+ (avec PDO et PDO_MySQL)
- MySQL 5.7+ ou MariaDB 10+
- Serveur web : Apache / Nginx / XAMPP / WAMP / Laragon

---

## Installation en 5 étapes

### 1. Copier les fichiers
Placez le dossier `banque/` dans votre répertoire web :
- XAMPP : `C:/xampp/htdocs/banque/`
- WAMP  : `C:/wamp64/www/banque/`
- Linux : `/var/www/html/banque/`

### 2. Créer la base de données
Ouvrez **phpMyAdmin** ou un terminal MySQL et exécutez :
```sql
SOURCE /chemin/vers/banque/sql/database.sql;
```
Ou copiez-collez le contenu du fichier `sql/database.sql` dans phpMyAdmin.

### 3. Configurer la base de données
Ouvrez `php/config.php` et modifiez :
```php
define('DB_HOST', 'localhost');   // Hôte MySQL
define('DB_USER', 'root');        // Votre utilisateur MySQL
define('DB_PASS', '');            // Votre mot de passe MySQL
define('DB_NAME', 'banque_christophe');
```

### 4. Lancer l'application
Accédez à : `http://localhost/banque/`

### 5. Connexion initiale
L'administrateur est créé automatiquement au premier accès.

**Compte Administrateur :**
- Email    : `Christophe@gmail.com`
- Mot de passe : `Christophe 2026`

---

## Structure des fichiers
```
banque/
├── index.html          → Page de connexion / inscription
├── dashboard.html      → Espace client
├── admin.html          → Panneau administrateur
├── css/
│   └── style.css       → Styles
├── js/                 → (réservé pour extensions futures)
├── php/
│   ├── config.php      → Configuration + fonctions utilitaires
│   ├── auth.php        → Authentification (connexion, inscription)
│   ├── client.php      → Opérations client (retrait, virement, dépôt)
│   └── admin.php       → Opérations admin (dépôts, gestion clients)
└── sql/
    └── database.sql    → Schéma base de données
```

---

## Fonctionnalités

### Client
- ✅ Inscription avec création automatique de compte (courant ou épargne)
- ✅ Connexion sécurisée (session PHP)
- ✅ Tableau de bord avec solde en temps réel
- ✅ **Retrait** (max 5 000 €/opération)
- ✅ **Virement** vers tout compte existant (max 10 000 €/opération)
- ✅ **Demande de dépôt** soumise à l'administrateur
- ✅ Historique complet des transactions avec filtres
- ✅ Système de notifications en temps réel
- ✅ Profil avec changement de mot de passe
- ✅ Numéros de compte uniques (format FR + 14 chiffres)
- ✅ Références de transaction uniques

### Administrateur
- ✅ Dashboard avec statistiques en temps réel
- ✅ **Dépôt direct** sur n'importe quel compte
- ✅ **Traitement des demandes** de dépôt (approuver / refuser)
- ✅ Liste complète de tous les clients avec soldes
- ✅ Suspendre / réactiver un compte client
- ✅ Consultation de toutes les transactions (dépôts, retraits, virements)
- ✅ Rapports mensuels par type d'opération
- ✅ Recherche rapide de clients

### Sécurité
- ✅ Mots de passe hachés avec BCrypt (password_hash)
- ✅ Requêtes préparées PDO (protection injection SQL)
- ✅ Sanitisation de toutes les entrées
- ✅ Vérification de session côté serveur
- ✅ Protection CSRF basique
- ✅ Séparation des rôles admin / client

---

## Base de données — Tables principales
| Table | Description |
|-------|-------------|
| `utilisateurs` | Clients + administrateur |
| `comptes` | Comptes bancaires |
| `transactions` | Toutes les opérations financières |
| `notifications` | Alertes pour les utilisateurs |
| `demandes_depot` | Demandes de dépôt des clients |

---

## Support
Application développée pour usage interne — Banque Christophe © 2026
