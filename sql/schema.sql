-- Årshjul: databaseskema (MySQL 8 / MariaDB 10.5+)
-- Kør: mysql -u root -p < sql/schema.sql   (opretter databasen 'aarshjul')

CREATE DATABASE IF NOT EXISTS aarshjul CHARACTER SET utf8mb4 COLLATE utf8mb4_danish_ci;
USE aarshjul;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS password_resets, login_attempts, users, settings, occurrence_files, occurrence_links, occurrence_notes, event_completions, event_dependencies, event_files, event_links, event_people, people, events, categories;
SET FOREIGN_KEY_CHECKS = 1;

-- Indstillinger for hele årshjulet som navn/værdi-par (fx start_month = 8 for august).
CREATE TABLE settings (
    name   VARCHAR(50) NOT NULL PRIMARY KEY,
    value  VARCHAR(255) NOT NULL
) ENGINE=InnoDB;

-- Kategorier giver begivenhederne et navn og en farve i hjulet og listen.
CREATE TABLE categories (
    id     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name   VARCHAR(100) NOT NULL,
    color  CHAR(7) NOT NULL,
    UNIQUE KEY uq_categories_name (name)
) ENGINE=InnoDB;

-- En begivenhed er en "serie". Datoerne i et givet år udregnes ud fra gentagelsesreglen,
-- så de gemmes ikke enkeltvis. Kun afkrydsninger (event_completions) gemmes pr. forekomst.
CREATE TABLE events (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title           VARCHAR(200) NOT NULL,
    description     TEXT NULL,
    category_id     INT UNSIGNED NOT NULL,
    start_date      DATE NOT NULL,              -- første forekomst / gælder fra
    end_date        DATE NULL,                  -- gentagelser stopper efter denne dato (NULL = ingen slutdato)
    duration_days   SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    recurrence      ENUM('none','weekly','monthly','yearly','nth_weekday','last_week','week_number')
                    NOT NULL DEFAULT 'none',
    rec_interval    SMALLINT UNSIGNED NOT NULL DEFAULT 1,  -- hver N. uge/måned/år
    rule_month      TINYINT UNSIGNED NULL,      -- 1-12, NULL = hver måned (nth_weekday, last_week)
    rule_weekday    TINYINT UNSIGNED NULL,      -- 1=mandag ... 7=søndag (nth_weekday, week_number)
    rule_nth        TINYINT NULL,               -- 1-4 eller -1 = sidste (nth_weekday); ugenummer 1-53 (week_number)
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_events_category FOREIGN KEY (category_id) REFERENCES categories(id)
) ENGINE=InnoDB;

CREATE TABLE people (
    id    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name  VARCHAR(150) NOT NULL,
    UNIQUE KEY uq_people_name (name)
) ENGINE=InnoDB;

CREATE TABLE event_people (
    event_id   INT UNSIGNED NOT NULL,
    person_id  INT UNSIGNED NOT NULL,
    PRIMARY KEY (event_id, person_id),
    FOREIGN KEY (event_id)  REFERENCES events(id) ON DELETE CASCADE,
    FOREIGN KEY (person_id) REFERENCES people(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE event_links (
    id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_id  INT UNSIGNED NOT NULL,
    url       VARCHAR(2000) NOT NULL,
    label     VARCHAR(200) NULL,
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE event_files (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_id       INT UNSIGNED NOT NULL,
    original_name  VARCHAR(255) NOT NULL,
    stored_name    VARCHAR(100) NOT NULL,   -- tilfældigt filnavn i storage/uploads
    mime_type      VARCHAR(150) NOT NULL,
    size_bytes     INT UNSIGNED NOT NULL,
    uploaded_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- event_id kan først finde sted, når depends_on_id er krydset af som opfyldt.
CREATE TABLE event_dependencies (
    event_id       INT UNSIGNED NOT NULL,
    depends_on_id  INT UNSIGNED NOT NULL,
    PRIMARY KEY (event_id, depends_on_id),
    FOREIGN KEY (event_id)      REFERENCES events(id) ON DELETE CASCADE,
    FOREIGN KEY (depends_on_id) REFERENCES events(id) ON DELETE CASCADE
    -- (event_id <> depends_on_id og ingen cirkler håndhæves i PHP)
) ENGINE=InnoDB;

-- Flueben: en konkret forekomst af en begivenhed er opfyldt/gennemført.
CREATE TABLE event_completions (
    event_id         INT UNSIGNED NOT NULL,
    occurrence_date  DATE NOT NULL,
    completed_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (event_id, occurrence_date),
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Note, links og filer, der kun gælder én forekomst. Nøglen er den samme som i event_completions,
-- så næste forekomst af samme begivenhed starter uden note.
CREATE TABLE occurrence_notes (
    event_id         INT UNSIGNED NOT NULL,
    occurrence_date  DATE NOT NULL,
    note             TEXT NOT NULL,
    updated_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (event_id, occurrence_date),
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE occurrence_links (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_id         INT UNSIGNED NOT NULL,
    occurrence_date  DATE NOT NULL,
    url              VARCHAR(2000) NOT NULL,
    label            VARCHAR(200) NULL,
    KEY idx_occurrence_links (event_id, occurrence_date),
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE occurrence_files (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_id         INT UNSIGNED NOT NULL,
    occurrence_date  DATE NOT NULL,
    original_name    VARCHAR(255) NOT NULL,
    stored_name      VARCHAR(100) NOT NULL,   -- tilfældigt filnavn i storage/uploads
    mime_type        VARCHAR(150) NOT NULL,
    size_bytes       INT UNSIGNED NOT NULL,
    uploaded_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_occurrence_files (event_id, occurrence_date),
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Brugere med login. Rollerne er ordnet: reader < contributor < admin (se src/Auth.php).
-- person_id kobler evt. brugeren til en person, så "mine begivenheder" kan vises.
CREATE TABLE users (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email          VARCHAR(254) NOT NULL,
    name           VARCHAR(150) NOT NULL,
    password_hash  VARCHAR(255) NULL,           -- NULL, indtil brugeren har valgt en adgangskode
    role           ENUM('reader','contributor','admin') NOT NULL DEFAULT 'reader',
    person_id      INT UNSIGNED NULL,
    active         TINYINT(1) NOT NULL DEFAULT 1, -- brugere deaktiveres i stedet for at blive slettet
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_login_at  TIMESTAMP NULL,
    UNIQUE KEY uq_users_email (email),
    UNIQUE KEY uq_users_person (person_id),
    FOREIGN KEY (person_id) REFERENCES people(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Mislykkede loginforsøg, så gætteri kan bremses. Ryddes op ved login.
CREATE TABLE login_attempts (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email         VARCHAR(254) NOT NULL,
    ip            VARCHAR(45) NOT NULL,
    attempted_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_login_attempts_email (email, attempted_at),
    KEY idx_login_attempts_ip (ip, attempted_at)
) ENGINE=InnoDB;

-- Engangslinks til at vælge adgangskode: "Glemt adgangskode" (reset) og invitation af nye brugere (invite).
-- Kun sha256 af nøglen gemmes, så en kopi af databasen ikke kan bruges til at overtage brugere.
CREATE TABLE password_resets (
    token_hash  CHAR(64) NOT NULL PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    purpose     ENUM('reset','invite') NOT NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at  DATETIME NOT NULL,
    KEY idx_password_resets_user (user_id, created_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
