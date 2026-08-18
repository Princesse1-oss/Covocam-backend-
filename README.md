# 🚗 CovoCam - Application de Covoiturage au Cameroun

CovoCam est une application web de covoiturage conçue spécifiquement pour le contexte camerounais. Elle permet à des conducteurs de proposer des places dans leur véhicule et à des passagers de trouver et réserver ces places facilement.

## 📋 Table des matières

- [Fonctionnalités](#-fonctionnalités)
- [Stack Technique](#-stack-technique)
- [Installation](#-installation)
- [Configuration](#-configuration)
- [Base de Données](#-base-de-données)
- [Authentification JWT](#-authentification-jwt)
- [Structure du Projet](#-structure-du-projet)
- [Endpoints API](#-endpoints-api)
- [Tests](#-tests)
- [Contribuer](#-contribuer)
- [Auteurs](#-auteurs)

---

## 🎯 Fonctionnalités

### 👤 Gestion des Utilisateurs
- Inscription avec nom, prénom, email, téléphone, type d'utilisateur
- Authentification sécurisée avec JWT
- Modification du profil (photo, biographie, préférences)
- Système de notation et d'avis
- Rôles utilisateur (Passager, Conducteur, Les deux)

### 🚗 Gestion des Trajets
- Publication de trajets (ville départ/arrivée, date, heure, places, prix)
- Modification et annulation de trajets
- Recherche de trajets par ville et date
- Gestion des réservations

### 🚙 Gestion des Véhicules
- Ajout de plusieurs véhicules
- Véhicule par défaut
- Photos de véhicules

### 📅 Réservations
- Réservation de places
- Acceptation/Refus des réservations
- Historique des réservations

### ⭐ Évaluations
- Notation des conducteurs par les passagers (1-5 étoiles)
- Notation des passagers par les conducteurs
- Commentaires et avis
- Note moyenne automatique

### 💬 Messagerie (Chat)
- Envoi de messages entre utilisateurs
- Messages en temps réel
- Notifications de nouveaux messages

### 🔔 Notifications
- Notifications pour les événements (réservation, annulation, etc.)
- Marquer comme lu
- Compteur de notifications

### 💰 Paiements
- Paiements sécurisés
- Moyens de paiement (Mobile Money, Orange Money, MTN Money)
- Remboursements

### 📍 Lieux
- Gestion des villes, quartiers, points de rendez-vous
- Coordonnées GPS

### 👑 Administration
- Tableau de bord admin
- Statistiques (utilisateurs, trajets, réservations)
- Gestion des utilisateurs (suspendre/activer)
- Modération des trajets

---

## 🛠️ Stack Technique

### Backend
| Technologie | Version | Description |
|-------------|---------|-------------|
| Symfony | 7.1 | Framework PHP |
| PHP | 8.2+ | Langage de programmation |
| PostgreSQL | 15+ | Base de données relationnelle |
| Doctrine | 3.0 | ORM pour la base de données |
| Lexik JWT | 3.0 | Authentification JWT |
| Nelmio CORS | 2.5 | Gestion des CORS |

### Frontend (à venir)
| Technologie | Version | Description |
|-------------|---------|-------------|
| ReactJS | 18+ | Bibliothèque JavaScript |
| Tailwind CSS | 3+ | Framework CSS |
| Axios | 1+ | Client HTTP |

### Outils de Développement
| Outil | Version | Description |
|-------|---------|-------------|
| Composer | 2.0+ | Gestionnaire de dépendances PHP |
| Postman | 10+ | Tests d'API |
| Git | 2.0+ | Contrôle de version |

---

## 🚀 Installation

### Prérequis

- PHP 8.2 ou supérieur
- Composer
- PostgreSQL 15 ou supérieur
- Node.js (pour React)
- Git

### Étapes d'installation

#### 1. Cloner le projet

```bash
git clone https://github.com/votre-username/covocam-backend.git
cd covocam-backend

# Installer le bundle ORM pour la base de données
composer require symfony/orm-pack

# Installer les migrations Doctrine
composer require symfony/doctrine-migrations-bundle

# Installer le bundle de sécurité
composer require symfony/security-bundle

# Installer le bundle JWT pour l'authentification
composer require lexik/jwt-authentication-bundle

# Installer le bundle CORS (pour React)
composer require nelmio/cors-bundle

# Installer le bundle de validation
composer require symfony/validator

# Installer le bundle de sérialisation
composer require symfony/serializer-pack

# Installer le bundle d'emails
composer require symfony/mailer

# Installer le bundle d'assets
composer require symfony/asset

# Installer le bundle Twig (pour les templates)
composer require symfony/twig-bundle

# Installer le bundle API Platform (pour la documentation API)
composer require api-platform/core

# Installer le bundle de dev (Maker)
composer require symfony/maker-bundle --dev

# Installer le bundle de debug
composer require symfony/debug-bundle --dev

# Installer le bundle de fixtures (données de test)
composer require doctrine/doctrine-fixtures-bundle --dev
# Vérifier toutes les dépendances installées
composer show

# Mettre à jour toutes les dépendances
composer update

# Voir les packages disponibles
composer list
# Créer le dossier des clés
mkdir config/jwt

# Générer les clés JWT
php bin/console lexik:jwt:generate-keypair

# OU utiliser le script alternatif (si OpenSSL pose problème)
php generate_jwt_keys.php
# Créer la base de données
php bin/console doctrine:database:create

# Créer la migration
php bin/console make:migration

# Exécuter la migration
php bin/console doctrine:migrations:migrate

# Valider le schéma
php bin/console doctrine:schema:validate
# Créer un administrateur
php bin/console app:create-user
 ------------------ -------------- ------------ 
  Email              Mot de passe    Rôle        
 ------------------ -------------- ------------ 
  admin@covocam.cm   Admin1234!     ROLE_ADMIN  
 ------------------ -------------- ------------ 

#  Démarrer le serveur de développement
symfony server


Installer les dépendances React

# Installer React Router
npm install react-router-dom

# Installer Axios (appels API)
npm install axios

# Installer Tailwind CSS
npm install -D tailwindcss postcss autoprefixer

# Installer React Query (gestion d'état)
npm install @tanstack/react-query

# Installer React Hook Form
npm install react-hook-form

# Installer JWT Decode
npm install jwt-decode

# Installer des icônes
npm install react-icons

# Installer date-fns (gestion des dates)
npm install date-fns

# Installer React Toastify (notifications)
npm install react-toastify