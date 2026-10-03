-- Årshjul: databaseskema (MySQL 8 / MariaDB 10.5+)
-- Kør: mysql -u root -p < sql/schema.sql   (opretter databasen 'aarshjul')

CREATE DATABASE IF NOT EXISTS aarshjul CHARACTER SET utf8mb4 COLLATE utf8mb4_danish_ci;
USE aarshjul;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS event_completions, event_dependencies, event_files, event_links, event_people, people, events, categories;
SET FOREIGN_KEY_CHECKS = 1;

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
