-- Migration: brugere og login (issue #6).
-- Kør på en eksisterende database, se README.
-- Brugere med login. Rollerne er ordnet: reader < contributor < admin (se src/Auth.php).
-- person_id kobler evt. brugeren til en person, så "mine begivenheder" kan vises.
CREATE TABLE IF NOT EXISTS users (
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
CREATE TABLE IF NOT EXISTS login_attempts (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email         VARCHAR(254) NOT NULL,
    ip            VARCHAR(45) NOT NULL,
    attempted_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_login_attempts_email (email, attempted_at),
    KEY idx_login_attempts_ip (ip, attempted_at)
) ENGINE=InnoDB;
