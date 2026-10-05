-- Eksempeldata til årshjulet. Kør efter schema.sql:  mysql -u root -p aarshjul < sql/seed.sql
SET NAMES utf8mb4;

INSERT INTO people (id, name) VALUES
 (1,'Anne Holm'), (2,'Jonas Berg'), (3,'Mette Lund'), (4,'Peter Krog');

INSERT INTO categories (id, name, color) VALUES
 (1,'Økonomi','#2f6fde'), (2,'Personale','#d9822b'), (3,'Møder','#3a9d5d'),
 (4,'Ferie','#8e5bd6'), (5,'Bestyrelse','#e05a8a');

INSERT INTO events (id, title, description, category_id, start_date, end_date, duration_days, recurrence, rec_interval, rule_month, rule_weekday, rule_nth) VALUES
 (1,'Budgetudkast','Første udkast til næste års budget sendes til ledelsen.',1,'2025-08-15',NULL,10,'yearly',1,NULL,NULL,NULL),
 (2,'Budgetgodkendelse','Bestyrelsen godkender budgettet. Kræver at udkastet er færdigt.',1,'2025-09-24',NULL,1,'yearly',1,NULL,NULL,NULL),
 (3,'Medarbejderudviklingssamtaler','MUS for alle medarbejdere.',2,'2025-01-01',NULL,5,'last_week',1,5,NULL,NULL),
 (4,'Personalemøde','Fast møde for hele huset.',3,'2025-01-01',NULL,1,'nth_weekday',1,NULL,2,1),
 (5,'Vinterferie','Skolernes vinterferie (Østdanmark).',4,'2025-01-01',NULL,7,'week_number',1,NULL,1,8),
 (6,'Årsregnskab','Regnskabet afsluttes og sendes til revisor.',1,'2025-03-31',NULL,1,'yearly',1,NULL,NULL,NULL),
 (7,'Generalforsamling','Afholdes når regnskabet er klar.',5,'2025-01-01',NULL,1,'nth_weekday',1,4,4,-1),
 (8,'Sprint-review','Hver anden uge.',3,'2026-01-09',NULL,1,'weekly',2,NULL,NULL,NULL),
 (9,'Sommerferie','Kontoret holder lukket.',4,'2025-01-01',NULL,14,'week_number',1,NULL,1,29);

INSERT INTO event_people (event_id, person_id) VALUES
 (1,1),(1,2),(2,1),(3,3),(3,4),(4,3),(6,2),(7,1),(7,4),(8,2),(8,4);

INSERT INTO event_links (event_id, url, label) VALUES
 (1,'https://example.com/budget-skabelon','Budgetskabelon'),
 (3,'https://example.com/mus-guide','MUS-guide'),
 (7,'https://example.com/vedtaegter','Vedtægter');

INSERT INTO event_dependencies (event_id, depends_on_id) VALUES
 (2,1),   -- Budgetgodkendelse afhænger af Budgetudkast
 (7,6);   -- Generalforsamling afhænger af Årsregnskab

INSERT INTO event_completions (event_id, occurrence_date) VALUES
 (6,'2026-03-31');

INSERT INTO occurrence_notes (event_id, occurrence_date, note) VALUES
 (4,'2026-11-03','Mødet holdes på Teams.');

INSERT INTO occurrence_links (event_id, occurrence_date, url, label) VALUES
 (4,'2026-11-03','https://teams.microsoft.com/','Teams-møde');
