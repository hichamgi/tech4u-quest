# 🎮 Tech4U-QUEST

**Tech4U-QUEST** est une application web éducative gamifiée destinée à l'évaluation et à l'entraînement des élèves en informatique.

> **Joue. Apprends. Progresse.**  
> Chaque question est un défi. Chaque module est une victoire.

L'application propose un parcours par modules avec questions aléatoires, vies, progression, scores, Game Over et badges. Elle comprend également une interface d'administration pour gérer les modules, catégories, questions, groupes d'exclusion et élèves.

---

## ✨ Fonctionnalités

### 👨‍🎓 Espace élève

- Connexion avec un identifiant de type `CLASSE-NUMERO`.
- Changement obligatoire du mot de passe provisoire, sauf comptes exemptés comme la classe `DEMO`.
- Tableau de bord avec progression par module.
- Parcours de questions généré côté serveur.
- Une question à la fois.
- Passage à la question suivante uniquement après une bonne réponse.
- Nombre de vies configurable par module.
- Une mauvaise réponse fait perdre une vie.
- Game Over lorsque toutes les vies sont perdues.
- Score correspondant au nombre de questions terminées.
- Nouvelle tentative avec renouvellement des questions déjà rencontrées lorsque des alternatives existent.
- Attribution d'un badge après réussite complète d'un module.
- Reprise d'une tentative en cours.

### 🛠️ Administration

- Tableau de bord d'administration.
- Configuration des modules.
- Configuration du quota de questions par catégorie.
- Calcul automatique du nombre total de questions d'une tentative à partir des quotas.
- Gestion de la banque de questions.
- Ajout, modification, duplication, activation et désactivation des questions.
- Types pris en charge : `qcm`, `multiple`, `true_false` et `short`.
- Difficulté de 1 à 5.
- Import et export CSV.
- Gestion des groupes d'exclusion pédagogiques.
- Import des élèves par CSV.
- Conservation d'un identifiant numérique stable pour chaque élève.
- Réinitialisation des résultats du compte de démonstration sans supprimer son compte.

---

## 🔀 Groupes d'exclusion de questions

Deux ou plusieurs questions pédagogiquement trop proches peuvent recevoir le même `exclusion_group`.

Exemple :

```text
binary-255
```

Une tentative ne sélectionne qu'une seule question appartenant à un même groupe d'exclusion.

Les groupes sont valables **à l'intérieur d'une tentative uniquement**. Après un Game Over, un groupe déjà rencontré peut donc être sélectionné de nouveau, mais le moteur essaie de choisir une autre question du groupe lorsqu'une alternative compatible existe.

Gestion depuis :

```text
/admin/question-exclusions.php
```

Le script suivant permet également d'appliquer les groupes pédagogiques prédéfinis à une base existante :

```bash
php scripts/apply-question-exclusion-groups.php --dry-run
php scripts/apply-question-exclusion-groups.php
```

Le mode `--dry-run` affiche les modifications prévues sans écrire dans la base.

---

## 🧠 Règles du jeu

Lors du démarrage d'une tentative, le serveur construit et enregistre le parcours complet dans la base.

Le navigateur n'est jamais considéré comme source fiable pour le score, les vies ou la validation des réponses : ces informations sont calculées côté PHP.

Pour chaque tentative :

1. les catégories sont sélectionnées selon leurs quotas configurés ;
2. les questions sont tirées dans les catégories correspondantes ;
3. les groupes d'exclusion empêchent la présence simultanée de questions pédagogiquement équivalentes ;
4. chaque mauvaise réponse est enregistrée et retire une vie ;
5. chaque bonne réponse permet d'avancer ;
6. à zéro vie, la tentative passe en `game_over` ;
7. lorsque toutes les questions sont terminées, la tentative passe en `completed` et le badge du module peut être attribué.

---

## 📚 Modules pédagogiques

La configuration initiale comprend quatre modules :

1. **Généralités sur les systèmes informatiques**
2. **Les logiciels**
3. **Algorithmique et programmation**
4. **Réseaux et Internet**

Chaque module contient plusieurs catégories utilisées pour équilibrer le tirage des questions.

---

## 🧱 Stack technique

- **PHP 8.1+**
- **Apache HTTP Server**
- **SQLite**
- **PDO SQLite**
- HTML / CSS / JavaScript

L'architecture reste volontairement légère afin de pouvoir fonctionner sur un hébergement PHP classique sans serveur MySQL/MariaDB.

---

## 📁 Structure principale

```text
tech4u-quest/
├── app/
│   ├── Core/
│   │   ├── Auth.php
│   │   └── Database.php
│   └── Services/
│       ├── GameService.php
│       ├── QuestionBankService.php
│       └── StudentCsvImportService.php
├── config/
├── database/
│   ├── schema.sql
│   ├── seeds/
│   └── current.sqlite       # non versionné
├── public/
│   ├── admin/
│   ├── assets/
│   ├── dashboard.php
│   ├── module.php
│   ├── question.php
│   ├── game-over.php
│   └── module-complete.php
├── scripts/
└── README.md
```

---

## 🗄️ Base SQLite

La base active est :

```text
database/current.sqlite
```

Elle n'est volontairement **pas versionnée dans Git**.

Au premier accès, si la base n'existe pas, l'application peut la créer à partir de :

```text
database/schema.sql
```

`Database.php` applique également les migrations légères nécessaires à une base existante. Il n'est donc pas nécessaire de supprimer `current.sqlite` lorsqu'une colonne prise en charge par une migration est ajoutée.

SQLite est configuré avec notamment :

```text
foreign_keys = ON
journal_mode = WAL
busy_timeout = 5000
```

Cela améliore l'intégrité référentielle et la gestion des accès concurrents.

### Initialisation manuelle

Si nécessaire :

```bash
sqlite3 database/current.sqlite < database/schema.sql
```

### Vérification

```bash
sqlite3 database/current.sqlite ".tables"
```

---

## 👥 Données des élèves et confidentialité

La base publique Tech4U-QUEST ne doit pas contenir les noms et prénoms des élèves.

Un élève est représenté notamment par :

```text
id
class_code
student_number
login_code
password_hash
must_change_password
active
```

L'identifiant `id` peut être importé depuis le système local de gestion des élèves afin de conserver une correspondance stable sans publier leur identité.

Exemple :

```text
id             = 157
class_code     = TCT1
student_number = 12
login_code     = TCT1-12
```

Si le numéro de l'élève change, son `id` reste identique afin de conserver son historique, ses scores et ses badges.

Les mots de passe sont stockés sous forme de hash PHP et jamais en clair dans SQLite.

---

## 📥 Import des élèves

Format CSV recommandé :

```text
id,class_code,student_number,password,active,must_change_password
```

Lors d'une mise à jour, un mot de passe vide permet de conserver le hash déjà enregistré pour l'élève existant.

**Ne jamais placer de fichier contenant des données personnelles réelles d'élèves dans le dépôt Git.**

---

## 🔐 Sécurité et Git

Le `.gitignore` doit exclure notamment :

- les bases SQLite réelles ;
- les fichiers WAL/SHM SQLite ;
- les archives annuelles ;
- les logs ;
- les uploads ;
- les fichiers `.env` ;
- les configurations locales contenant des secrets.

Ne jamais committer :

```text
current.sqlite
current.sqlite-wal
current.sqlite-shm
.env
```

ni aucune liste réelle d'élèves contenant des informations personnelles ou des mots de passe en clair.

---

## 🚀 Installation

Cloner le dépôt :

```bash
git clone https://github.com/hichamgi/tech4u-quest.git
cd tech4u-quest
```

Vérifier que PHP dispose du pilote SQLite :

```bash
php -m | grep -i sqlite
```

Le résultat doit notamment contenir `pdo_sqlite`.

Le serveur web doit avoir les droits d'écriture sur le dossier `database/`, car SQLite doit pouvoir créer la base ainsi que les fichiers WAL et SHM.

Exemple sous Debian avec Apache :

```bash
sudo chown -R $USER:www-data database
sudo find database -type d -exec chmod 2775 {} \;
sudo find database -type f -exec chmod 664 {} \;
```

---

## 🌐 Exemple de configuration Apache

```apache
Alias /tech4u-quest/ /var/www/html/tech4u-quest/public/

<Directory /var/www/html/tech4u-quest/public>
    Options -Indexes +FollowSymLinks
    AllowOverride All
    Require all granted
</Directory>
```

Puis :

```bash
sudo a2enmod rewrite
sudo a2enconf tech4u-quest
sudo apache2ctl configtest
sudo systemctl reload apache2
```

Exemple d'accès local :

```text
http://SERVEUR/tech4u-quest/
```

---

## 🔄 Mise à jour

Sur une installation existante :

```bash
cd /var/www/html/tech4u-quest
git pull --rebase origin main
```

Il ne faut pas supprimer `database/current.sqlite` lors d'une mise à jour normale.

Après une modification importante, il est conseillé de vérifier la syntaxe PHP :

```bash
php -l app/Core/Database.php
php -l app/Services/GameService.php
php -l app/Services/QuestionBankService.php
```

---

## 🧪 Compte de démonstration

Une installation peut utiliser un compte élève de classe `DEMO` pour les démonstrations et inspections. Les données de progression de ce compte peuvent être réinitialisées depuis l'administration sans supprimer son identité ni son accès.

Les identifiants et mots de passe réels de production ne doivent pas être documentés dans ce README.

---

## 🗃️ Archivage annuel

Le principe prévu est d'utiliser une base SQLite active par année scolaire et d'archiver l'ancienne base avant de commencer la suivante.

Exemple :

```text
database/current.sqlite
archives/2026-2027.sqlite
archives/2027-2028.sqlite
```

Les archives contenant des données réelles ne doivent pas être ajoutées au dépôt Git.

---

## 📌 État du projet

Tech4U-QUEST est en développement actif. Les fonctionnalités, le schéma SQLite et les règles pédagogiques peuvent encore évoluer.

Lors d'une évolution du schéma, privilégier une migration de la base existante plutôt que la suppression de `current.sqlite`, afin de conserver les élèves, tentatives, scores et badges.

---

## 📄 Licence

Aucune licence n'est actuellement spécifiée dans le dépôt. L'ajout d'un fichier `LICENSE` est recommandé avant toute redistribution publique du projet.
