PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS settings (
    key TEXT PRIMARY KEY,
    value TEXT
);

CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    role TEXT NOT NULL DEFAULT 'teacher' CHECK(role IN ('admin','teacher')),
    active INTEGER NOT NULL DEFAULT 1 CHECK(active IN (0,1)),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS students (
    -- Stable identifier imported from the local MySQL database.
    -- Tech4U-QUEST must never generate this value itself.
    id INTEGER PRIMARY KEY,
    class_code TEXT NOT NULL,
    student_number INTEGER NOT NULL CHECK(student_number > 0),
    login_code TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    must_change_password INTEGER NOT NULL DEFAULT 1 CHECK(must_change_password IN (0,1)),
    active INTEGER NOT NULL DEFAULT 1 CHECK(active IN (0,1)),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT,
    UNIQUE(class_code, student_number)
);

CREATE TABLE IF NOT EXISTS student_login_history (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    student_id INTEGER NOT NULL,
    old_login_code TEXT NOT NULL,
    new_login_code TEXT NOT NULL,
    changed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(student_id) REFERENCES students(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS modules (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL UNIQUE,
    description TEXT,
    icon TEXT,
    recommended_bank_size INTEGER NOT NULL DEFAULT 0 CHECK(recommended_bank_size >= 0),
    display_order INTEGER NOT NULL DEFAULT 0,
    active INTEGER NOT NULL DEFAULT 1 CHECK(active IN (0,1)),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS categories (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    module_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    description TEXT,
    recommended_bank_size INTEGER NOT NULL DEFAULT 0 CHECK(recommended_bank_size >= 0),
    display_order INTEGER NOT NULL DEFAULT 0,
    active INTEGER NOT NULL DEFAULT 1 CHECK(active IN (0,1)),
    FOREIGN KEY(module_id) REFERENCES modules(id) ON DELETE CASCADE,
    UNIQUE(module_id, name)
);

CREATE TABLE IF NOT EXISTS questions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    category_id INTEGER NOT NULL,
    question TEXT NOT NULL,
    type TEXT NOT NULL DEFAULT 'qcm' CHECK(type IN ('qcm','true_false','multiple','short')),
    difficulty INTEGER NOT NULL DEFAULT 1 CHECK(difficulty BETWEEN 1 AND 5),
    explanation TEXT,
    lesson TEXT,
    topic TEXT,
    active INTEGER NOT NULL DEFAULT 1 CHECK(active IN (0,1)),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT,
    FOREIGN KEY(category_id) REFERENCES categories(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS question_answers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    question_id INTEGER NOT NULL,
    answer TEXT NOT NULL,
    is_correct INTEGER NOT NULL DEFAULT 0 CHECK(is_correct IN (0,1)),
    display_order INTEGER NOT NULL DEFAULT 0,
    FOREIGN KEY(question_id) REFERENCES questions(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS module_settings (
    module_id INTEGER PRIMARY KEY,
    question_count INTEGER NOT NULL DEFAULT 8 CHECK(question_count > 0),
    initial_lives INTEGER NOT NULL DEFAULT 3 CHECK(initial_lives > 0),
    badge_enabled INTEGER NOT NULL DEFAULT 1 CHECK(badge_enabled IN (0,1)),
    FOREIGN KEY(module_id) REFERENCES modules(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS module_category_settings (
    module_id INTEGER NOT NULL,
    category_id INTEGER NOT NULL,
    question_count INTEGER NOT NULL DEFAULT 1 CHECK(question_count >= 0),
    PRIMARY KEY(module_id, category_id),
    FOREIGN KEY(module_id) REFERENCES modules(id) ON DELETE CASCADE,
    FOREIGN KEY(category_id) REFERENCES categories(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS attempts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    student_id INTEGER NOT NULL,
    module_id INTEGER NOT NULL,
    attempt_number INTEGER NOT NULL DEFAULT 1 CHECK(attempt_number > 0),
    total_questions INTEGER NOT NULL CHECK(total_questions > 0),
    current_position INTEGER NOT NULL DEFAULT 1 CHECK(current_position > 0),
    lives INTEGER NOT NULL CHECK(lives >= 0),
    score INTEGER NOT NULL DEFAULT 0 CHECK(score >= 0),
    status TEXT NOT NULL DEFAULT 'in_progress' CHECK(status IN ('in_progress','completed','game_over')),
    started_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at TEXT,
    FOREIGN KEY(student_id) REFERENCES students(id),
    FOREIGN KEY(module_id) REFERENCES modules(id)
);

CREATE TABLE IF NOT EXISTS attempt_questions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    attempt_id INTEGER NOT NULL,
    position INTEGER NOT NULL CHECK(position > 0),
    question_id INTEGER NOT NULL,
    wrong_answers INTEGER NOT NULL DEFAULT 0 CHECK(wrong_answers >= 0),
    answered INTEGER NOT NULL DEFAULT 0 CHECK(answered IN (0,1)),
    completed INTEGER NOT NULL DEFAULT 0 CHECK(completed IN (0,1)),
    FOREIGN KEY(attempt_id) REFERENCES attempts(id) ON DELETE CASCADE,
    FOREIGN KEY(question_id) REFERENCES questions(id),
    UNIQUE(attempt_id, position)
);

CREATE TABLE IF NOT EXISTS attempt_answers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    attempt_id INTEGER NOT NULL,
    attempt_question_id INTEGER NOT NULL,
    answer_id INTEGER,
    short_answer TEXT,
    is_correct INTEGER NOT NULL CHECK(is_correct IN (0,1)),
    answered_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(attempt_id) REFERENCES attempts(id) ON DELETE CASCADE,
    FOREIGN KEY(attempt_question_id) REFERENCES attempt_questions(id) ON DELETE CASCADE,
    FOREIGN KEY(answer_id) REFERENCES question_answers(id)
);

CREATE TABLE IF NOT EXISTS badges (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    module_id INTEGER NOT NULL UNIQUE,
    name TEXT NOT NULL,
    description TEXT,
    icon TEXT,
    FOREIGN KEY(module_id) REFERENCES modules(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS student_badges (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    student_id INTEGER NOT NULL,
    badge_id INTEGER NOT NULL,
    attempt_id INTEGER,
    obtained_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY(badge_id) REFERENCES badges(id),
    FOREIGN KEY(attempt_id) REFERENCES attempts(id),
    UNIQUE(student_id, badge_id)
);

CREATE INDEX IF NOT EXISTS idx_students_class ON students(class_code);
CREATE INDEX IF NOT EXISTS idx_students_login ON students(login_code);
CREATE INDEX IF NOT EXISTS idx_login_history_student ON student_login_history(student_id);
CREATE INDEX IF NOT EXISTS idx_categories_module ON categories(module_id);
CREATE INDEX IF NOT EXISTS idx_questions_category ON questions(category_id);
CREATE INDEX IF NOT EXISTS idx_questions_difficulty ON questions(difficulty);
CREATE INDEX IF NOT EXISTS idx_answers_question ON question_answers(question_id);
CREATE INDEX IF NOT EXISTS idx_attempts_student ON attempts(student_id);
CREATE INDEX IF NOT EXISTS idx_attempts_module ON attempts(module_id);
CREATE INDEX IF NOT EXISTS idx_attempts_status ON attempts(status);
CREATE INDEX IF NOT EXISTS idx_attempt_questions_attempt ON attempt_questions(attempt_id);
CREATE INDEX IF NOT EXISTS idx_attempt_answers_attempt ON attempt_answers(attempt_id);
CREATE INDEX IF NOT EXISTS idx_student_badges_student ON student_badges(student_id);

-- -----------------------------------------------------------------------------
-- Global settings
-- -----------------------------------------------------------------------------
INSERT OR IGNORE INTO settings(key,value) VALUES ('site_name','Tech4U-QUEST');
INSERT OR IGNORE INTO settings(key,value) VALUES ('school_year','2026-2027');
INSERT OR IGNORE INTO settings(key,value) VALUES ('default_question_type','qcm');
INSERT OR IGNORE INTO settings(key,value) VALUES ('difficulty_1_weight','20');
INSERT OR IGNORE INTO settings(key,value) VALUES ('difficulty_2_weight','30');
INSERT OR IGNORE INTO settings(key,value) VALUES ('difficulty_3_weight','30');
INSERT OR IGNORE INTO settings(key,value) VALUES ('difficulty_4_weight','15');
INSERT OR IGNORE INTO settings(key,value) VALUES ('difficulty_5_weight','5');

-- -----------------------------------------------------------------------------
-- Modules
-- -----------------------------------------------------------------------------
INSERT OR IGNORE INTO modules(id,title,description,icon,recommended_bank_size,display_order,active) VALUES
(1,'Généralités sur les systèmes informatiques','Vocabulaire informatique, représentation de l''information, architecture de l''ordinateur, mémoires, périphériques, logiciels et domaines d''application.','🖥️',60,1,1),
(2,'Les logiciels','Systèmes d''exploitation, environnement Windows, gestion des fichiers, traitement de texte et tableur.','🧩',80,2,1),
(3,'Algorithmique et programmation','Algorithmique, données, instructions, structures conditionnelles, Pascal et Python.','💻',120,3,1),
(4,'Réseaux et Internet','Réseaux informatiques, équipements, topologies, protocoles, Internet, adressage et services.','🌐',70,4,1);

-- -----------------------------------------------------------------------------
-- Categories - Module I
-- -----------------------------------------------------------------------------
INSERT OR IGNORE INTO categories(id,module_id,name,description,recommended_bank_size,display_order,active) VALUES
(101,1,'Concepts informatiques','Informatique, information, traitement, automatique et système informatique.',8,1,1),
(102,1,'Représentation de l''information','Nombres, conversions décimal/binaire, textes et images.',12,2,1),
(103,1,'Processeur et architecture','Schéma fonctionnel, CPU, UAL, unité de commande et registres.',10,3,1),
(104,1,'Mémoire et stockage','Mémoire centrale, RAM, ROM et unités de stockage.',8,4,1),
(105,1,'Entrées, sorties et périphériques','Unités et périphériques d''entrée et de sortie.',6,5,1),
(106,1,'Carte mère et bus','Carte mère et bus de communication entre composants.',6,6,1),
(107,1,'Logiciels','Logiciels de base et logiciels d''application.',5,7,1),
(108,1,'Domaines d''application','Gestion, industrie, science, éducation, téléphonie, communication et multimédia.',5,8,1);

-- -----------------------------------------------------------------------------
-- Categories - Module II
-- -----------------------------------------------------------------------------
INSERT OR IGNORE INTO categories(id,module_id,name,description,recommended_bank_size,display_order,active) VALUES
(201,2,'Systèmes d''exploitation','Rôle et principaux types de systèmes d''exploitation.',8,1,1),
(202,2,'Environnement Windows','Bureau, barre des tâches, menu Démarrer, fenêtres, boîtes de dialogue et menus contextuels.',8,2,1),
(203,2,'Fichiers et dossiers','Notions, caractéristiques et organisation des fichiers et dossiers.',9,3,1),
(204,2,'Traitement de texte - bases','Fonctionnalités, environnement Word, saisie, déplacement et sélection.',8,4,1),
(205,2,'Traitement de texte - mise en forme','Copie, déplacement, caractères et paragraphes.',8,5,1),
(206,2,'Traitement de texte - objets et impression','Tableaux, images, mise en page et impression.',9,6,1),
(207,2,'Tableur - bases','Environnement Excel, cellules, déplacement et sélection.',7,7,1),
(208,2,'Tableur - calculs','Saisie des données, formules et fonctions prédéfinies.',9,8,1),
(209,2,'Tableur - références','Références relatives et références absolues.',7,9,1),
(210,2,'Tableur - présentation','Mise en forme, graphiques, mise en page et impression.',7,10,1);

-- -----------------------------------------------------------------------------
-- Categories - Module III
-- -----------------------------------------------------------------------------
INSERT OR IGNORE INTO categories(id,module_id,name,description,recommended_bank_size,display_order,active) VALUES
(301,3,'Introduction à l''algorithmique','Algorithme, problème, schéma de résolution et résolution informatique.',10,1,1),
(302,3,'Données et types','Identifiants, types, nature et types simples de données.',10,2,1),
(303,3,'Variables et affectation','Affectation et manipulation des données.',10,3,1),
(304,3,'Opérateurs et expressions','Expressions arithmétiques et logiques, comparaisons et priorités.',10,4,1),
(305,3,'Entrées et sorties','Lecture, écriture et principes des entrées/sorties.',10,5,1),
(306,3,'Séquence et blocs','Enchaînement séquentiel, structure d''un algorithme et notion de bloc.',10,6,1),
(307,3,'Structures conditionnelles','Alternative simple, complète, imbriquée et choix multiple.',15,7,1),
(308,3,'Pascal','Structure d''un programme Pascal, types, affectation, entrées/sorties et conditions.',15,8,1),
(309,3,'Python - fondamentaux','Structure d''un programme Python, données, types, mutabilité et affectation.',10,9,1),
(310,3,'Python - expressions','Opérateurs arithmétiques, fonctions mathématiques, comparaisons et expressions logiques.',10,10,1),
(311,3,'Python - entrées et sorties','print(), concaténation, sep, end, input() et conversions de types.',10,11,1),
(312,3,'Python - conditions','if, else, elif, blocs et indentation.',10,12,1);

-- -----------------------------------------------------------------------------
-- Categories - Module IV
-- -----------------------------------------------------------------------------
INSERT OR IGNORE INTO categories(id,module_id,name,description,recommended_bank_size,display_order,active) VALUES
(401,4,'Notions de réseau','Définition, objectifs, avantages et déficiences d''un réseau informatique.',7,1,1),
(402,4,'Matériel réseau','Carte réseau et supports de transmission.',7,2,1),
(403,4,'Équipements d''interconnexion','Équipements permettant l''interconnexion des machines et réseaux.',7,3,1),
(404,4,'Protocoles et logiciels réseau','Systèmes d''exploitation et protocoles de communication.',7,4,1),
(405,4,'Topologies','Topologies en bus, étoile et anneau.',7,5,1),
(406,4,'Types de réseaux','Classification par taille : LAN, MAN et WAN.',7,6,1),
(407,4,'Internet','Définition, conditions de connexion et fonctionnement général d''Internet.',7,7,1),
(408,4,'Adressage Internet','Adresse IP, adresse Internet, adresse électronique et URL.',7,8,1),
(409,4,'Services Internet','Web, transfert de fichiers, messagerie électronique et communication en temps réel.',7,9,1),
(410,4,'Avantages et inconvénients d''Internet','Avantages et inconvénients liés à l''utilisation d''Internet.',7,10,1);

-- -----------------------------------------------------------------------------
-- Module configuration
-- -----------------------------------------------------------------------------
INSERT OR IGNORE INTO module_settings(module_id,question_count,initial_lives,badge_enabled) VALUES
(1,10,3,1),
(2,12,3,1),
(3,12,3,1),
(4,10,3,1);

-- Module I: 10 questions
INSERT OR IGNORE INTO module_category_settings(module_id,category_id,question_count) VALUES
(1,101,1),(1,102,2),(1,103,1),(1,104,1),(1,105,1),(1,106,1),(1,107,1),(1,108,2);

-- Module II: 12 questions
INSERT OR IGNORE INTO module_category_settings(module_id,category_id,question_count) VALUES
(2,201,1),(2,202,1),(2,203,1),(2,204,1),(2,205,1),(2,206,1),(2,207,1),(2,208,2),(2,209,1),(2,210,2);

-- Module III: 12 questions (one from each pedagogical category)
INSERT OR IGNORE INTO module_category_settings(module_id,category_id,question_count) VALUES
(3,301,1),(3,302,1),(3,303,1),(3,304,1),(3,305,1),(3,306,1),(3,307,1),(3,308,1),(3,309,1),(3,310,1),(3,311,1),(3,312,1);

-- Module IV: 10 questions
INSERT OR IGNORE INTO module_category_settings(module_id,category_id,question_count) VALUES
(4,401,1),(4,402,1),(4,403,1),(4,404,1),(4,405,1),(4,406,1),(4,407,1),(4,408,1),(4,409,1),(4,410,1);

-- -----------------------------------------------------------------------------
-- Badges
-- -----------------------------------------------------------------------------
INSERT OR IGNORE INTO badges(id,module_id,name,description,icon) VALUES
(1,1,'Maître du système','Module Généralités sur les systèmes informatiques terminé.','🖥️'),
(2,2,'Expert logiciel','Module Les logiciels terminé.','🧩'),
(3,3,'Codeur en herbe','Module Algorithmique et programmation terminé.','💻'),
(4,4,'Explorateur du réseau','Module Réseaux et Internet terminé.','🌐');
