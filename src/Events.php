<?php
declare(strict_types=1);

final class Events
{
    /** Alle begivenheder med personer, links, filer og afhængigheder, nøglet på id. */
    public static function all(): array
    {
        $pdo = db();
        $events = [];
        foreach ($pdo->query('SELECT e.*, c.name AS category_name, c.color FROM events e
            JOIN categories c ON c.id = e.category_id ORDER BY e.start_date, e.title') as $e) {
            $e += ['people' => [], 'links' => [], 'files' => [], 'depends_on' => []];
            $events[(int)$e['id']] = $e;
        }
        foreach ($pdo->query('SELECT ep.event_id, p.id, p.name FROM event_people ep JOIN people p ON p.id = ep.person_id ORDER BY p.name') as $r) {
            $events[(int)$r['event_id']]['people'][] = ['id' => (int)$r['id'], 'name' => $r['name']];
        }
        foreach ($pdo->query('SELECT * FROM event_links ORDER BY id') as $r) {
            $events[(int)$r['event_id']]['links'][] = $r;
        }
        foreach ($pdo->query('SELECT * FROM event_files ORDER BY id') as $r) {
            $events[(int)$r['event_id']]['files'][] = $r;
        }
        foreach ($pdo->query('SELECT * FROM event_dependencies') as $r) {
            $events[(int)$r['event_id']]['depends_on'][] = (int)$r['depends_on_id'];
        }
        return $events;
    }

    public static function find(int $id): ?array
    {
        return self::all()[$id] ?? null;
    }

    public static function people(): array
    {
        return db()->query('SELECT id, name FROM people ORDER BY name')->fetchAll();
    }

    /** @return array<string,bool> nøgle "eventId|Y-m-d" */
    public static function completions(): array
    {
        $set = [];
        foreach (db()->query('SELECT event_id, occurrence_date FROM event_completions') as $r) {
            $set[$r['event_id'] . '|' . $r['occurrence_date']] = true;
        }
        return $set;
    }

    public static function setCompleted(int $eventId, string $date, bool $done): void
    {
        if ($done) {
            db()->prepare('INSERT IGNORE INTO event_completions (event_id, occurrence_date) VALUES (?, ?)')->execute([$eventId, $date]);
        } else {
            db()->prepare('DELETE FROM event_completions WHERE event_id = ? AND occurrence_date = ?')->execute([$eventId, $date]);
        }
    }

    private const NO_OCCURRENCE_DATA = ['note' => null, 'links' => [], 'files' => []];

    /** Note, links og filer, der kun gælder én forekomst. @return array<string,array{note:?string,links:array,files:array}> nøgle "eventId|Y-m-d" */
    public static function occurrenceData(): array
    {
        $pdo = db();
        $data = [];
        try {
            foreach ($pdo->query('SELECT event_id, occurrence_date, note FROM occurrence_notes') as $r) {
                $data[$r['event_id'] . '|' . $r['occurrence_date']]['note'] = $r['note'];
            }
            foreach (['links' => 'occurrence_links', 'files' => 'occurrence_files'] as $field => $table) {
                foreach ($pdo->query("SELECT * FROM $table ORDER BY id") as $r) {
                    $data[$r['event_id'] . '|' . $r['occurrence_date']][$field][] = $r;
                }
            }
        } catch (PDOException) {
            return []; // Tabellerne findes ikke endnu (migrationen er ikke kørt)
        }
        return array_map(fn($d) => $d + self::NO_OCCURRENCE_DATA, $data);
    }

    /**
     * Gemmer note, links og filer for én forekomst. En tom note slettes. Returnerer fejl[].
     * Forekomsten skal være valideret af kalderen (se action.php).
     */
    public static function saveOccurrence(int $eventId, string $date, array $in, array $uploads): array
    {
        $errors = [];
        $note = trim((string)($in['note'] ?? ''));
        if (mb_strlen($note) > 5000) {
            $errors[] = 'Noten må højst være 5.000 tegn.';
        }
        $links = self::parseLinks($in, $errors);
        $newFiles = self::parseUploads($uploads, $errors);
        if ($errors) {
            return $errors;
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            if ($note === '') {
                $pdo->prepare('DELETE FROM occurrence_notes WHERE event_id=? AND occurrence_date=?')->execute([$eventId, $date]);
            } else {
                $pdo->prepare('INSERT INTO occurrence_notes (event_id, occurrence_date, note) VALUES (?,?,?) ON DUPLICATE KEY UPDATE note = VALUES(note)')
                    ->execute([$eventId, $date, $note]);
            }

            $pdo->prepare('DELETE FROM occurrence_links WHERE event_id=? AND occurrence_date=?')->execute([$eventId, $date]);
            $ins = $pdo->prepare('INSERT INTO occurrence_links (event_id, occurrence_date, url, label) VALUES (?,?,?,?)');
            foreach ($links as $l) {
                $ins->execute([$eventId, $date, $l['url'], $l['label']]);
            }

            foreach (array_map('intval', (array)($in['delete_file'] ?? [])) as $fid) {
                self::removeFiles('occurrence_files', 'id=? AND event_id=? AND occurrence_date=?', [$fid, $eventId, $date]);
            }
            $ins = $pdo->prepare('INSERT INTO occurrence_files (event_id, occurrence_date, original_name, stored_name, mime_type, size_bytes) VALUES (?,?,?,?,?,?)');
            foreach ($newFiles as $f) {
                [$stored, $mime] = self::storeUpload($f);
                $ins->execute([$eventId, $date, $f['name'], $stored, $mime, $f['size']]);
            }

            $pdo->commit();
        } catch (Throwable $t) {
            $pdo->rollBack();
            return ['Kunne ikke gemme: ' . $t->getMessage()];
        }
        return [];
    }

    /**
     * Status for forudsætningerne til én forekomst: for hver begivenhed, der afhænges af,
     * findes dens seneste forekomst på eller før datoen (op til ét år tilbage).
     * Har forudsætningen ingen forekomst i perioden, regnes den som opfyldt.
     */
    public static function prerequisites(array $event, string $date, array $all, array $done): array
    {
        $res = [];
        foreach ($event['depends_on'] as $depId) {
            if (!isset($all[$depId])) {
                continue;
            }
            $from = (new DateTimeImmutable($date))->modify('-1 year')->format('Y-m-d');
            $occ = Recurrence::occurrences($all[$depId], $from, $date);
            $occ = array_filter($occ, fn($d) => $d <= $date);
            $depDate = $occ ? end($occ) : null;
            $res[] = [
                'event' => $all[$depId],
                'date'  => $depDate,
                'done'  => $depDate === null || isset($done[$depId . '|' . $depDate]),
            ];
        }
        return $res;
    }

    /**
     * Begivenheder, der afhænger af $event, med datoen for den første af deres forekomster, som venter
     * på netop forekomsten $date (dvs. hvor $date er den seneste forekomst af $event; se prerequisites).
     * Datoen er null, hvis ingen af deres forekomster det næste år venter på den.
     */
    public static function dependents(array $event, string $date, array $all): array
    {
        $res = [];
        $to = (new DateTimeImmutable($date))->modify('+1 year')->format('Y-m-d');
        $dayAfter = (new DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d');
        foreach ($all as $e) {
            if (!in_array((int)$event['id'], $e['depends_on'], true)) {
                continue;
            }
            $next = array_values(array_filter(Recurrence::occurrences($e, $date, $to), fn($d) => $d >= $date));
            $depDate = $next[0] ?? null;
            // Kommer der en nyere forekomst af $event først, venter den afhængige forekomst på den i stedet
            if ($depDate !== null && array_filter(Recurrence::occurrences($event, $dayAfter, $depDate), fn($d) => $d >= $dayAfter && $d <= $depDate)) {
                $depDate = null;
            }
            $res[] = ['event' => $e, 'date' => $depDate];
        }
        return $res;
    }

    /**
     * Alle forekomster i årshjulet for $year (se year_bounds), sorteret efter dato, med status for flueben og afhængigheder.
     * En forekomst er overskredet, når slutdatoen er passeret uden flueben. $onlyMissing udelader dem med flueben.
     * Hver forekomst har også sin egen note, links og filer (se occurrenceData).
     */
    public static function occurrencesForYear(int $year, ?int $personId = null, ?int $categoryId = null, ?array $all = null, bool $onlyMissing = false): array
    {
        $all ??= self::all();
        $done = self::completions();
        $extra = self::occurrenceData();
        $today = date('Y-m-d');
        $rows = [];
        [$from, $to] = year_bounds($year);
        foreach ($all as $e) {
            if ($personId && !in_array($personId, array_column($e['people'], 'id'), true)) {
                continue;
            }
            if ($categoryId && (int)$e['category_id'] !== $categoryId) {
                continue;
            }
            foreach (Recurrence::occurrences($e, $from, $to) as $date) {
                $isDone = isset($done[$e['id'] . '|' . $date]);
                if ($onlyMissing && $isDone) {
                    continue;
                }
                $prereqs = self::prerequisites($e, $date, $all, $done);
                $end = (new DateTimeImmutable($date))->modify('+' . (max(1, (int)$e['duration_days']) - 1) . ' days')->format('Y-m-d');
                $rows[] = [
                    'event'   => $e,
                    'date'    => $date,
                    'end'     => $end,
                    'done'    => $isDone,
                    'overdue' => !$isDone && $end < $today,
                    'prereqs' => $prereqs,
                    'blocked' => (bool)array_filter($prereqs, fn($p) => !$p['done']),
                ] + ($extra[$e['id'] . '|' . $date] ?? self::NO_OCCURRENCE_DATA);
            }
        }
        usort($rows, fn($a, $b) => [$a['date'], $a['event']['title']] <=> [$b['date'], $b['event']['title']]);
        return $rows;
    }

    /** Ville det give en cirkel, hvis $eventId afhænger af $dependsOn? */
    public static function wouldCreateCycle(int $eventId, array $dependsOn, array $all): bool
    {
        $stack = $dependsOn;
        $seen = [];
        while ($stack) {
            $id = array_pop($stack);
            if ($id === $eventId) {
                return true;
            }
            if (isset($seen[$id]) || !isset($all[$id])) {
                continue;
            }
            $seen[$id] = true;
            foreach ($all[$id]['depends_on'] as $next) {
                $stack[] = $next;
            }
        }
        return false;
    }

    /**
     * Validerer og gemmer en begivenhed fra et formular-array. Returnerer [id, fejl[]].
     */
    public static function save(?int $id, array $in, array $uploads): array
    {
        $errors = [];
        $title = trim((string)($in['title'] ?? ''));
        if ($title === '') {
            $errors[] = 'Titel skal udfyldes.';
        }
        $startDate = (string)($in['start_date'] ?? '');
        if (!DateTimeImmutable::createFromFormat('!Y-m-d', $startDate)) {
            $errors[] = 'Startdato er ugyldig.';
        }
        $endDate = trim((string)($in['end_date'] ?? '')) ?: null;
        if ($endDate !== null && (!DateTimeImmutable::createFromFormat('!Y-m-d', $endDate) || $endDate < $startDate)) {
            $errors[] = 'Slutdato for gentagelser skal være en gyldig dato efter startdatoen.';
        }
        $rec = (string)($in['recurrence'] ?? 'none');
        if (!isset(Recurrence::RULES[$rec])) {
            $errors[] = 'Ukendt gentagelsesregel.';
        }
        $categoryId = (int)($in['category_id'] ?? 0);
        if (!isset(Categories::all()[$categoryId])) {
            $errors[] = 'Vælg en kategori.';
        }
        $duration = max(1, min(366, (int)($in['duration_days'] ?? 1)));
        // Er en slutdato angivet, bestemmer den varigheden (begge datoer medregnes)
        $periodEnd = trim((string)($in['period_end'] ?? ''));
        if ($periodEnd !== '') {
            $s = DateTimeImmutable::createFromFormat('!Y-m-d', $startDate);
            $pe = DateTimeImmutable::createFromFormat('!Y-m-d', $periodEnd);
            if (!$s || !$pe || $pe < $s) {
                $errors[] = 'Slutdatoen skal være en gyldig dato på eller efter startdatoen.';
            } elseif ($s->diff($pe)->days + 1 > 366) {
                $errors[] = 'En begivenhed kan højst vare 366 dage.';
            } else {
                $duration = $s->diff($pe)->days + 1;
            }
        }
        $interval = max(1, min(99, (int)($in['rec_interval'] ?? 1)));
        $ruleMonth = (int)($in['rule_month'] ?? 0) ?: null;
        $ruleWeekday = null;
        $ruleNth = null;
        if ($rec === 'nth_weekday') {
            $ruleWeekday = max(1, min(7, (int)($in['rule_weekday'] ?? 1)));
            $ruleNth = (int)($in['rule_nth'] ?? 1);
            if (!in_array($ruleNth, [1, 2, 3, 4, -1], true)) {
                $errors[] = 'Vælg 1., 2., 3., 4. eller sidste.';
            }
        } elseif ($rec === 'week_number') {
            $ruleWeekday = max(1, min(7, (int)($in['rule_weekday'] ?? 1)));
            $ruleNth = (int)($in['week_number'] ?? 0);
            if ($ruleNth < 1 || $ruleNth > 53) {
                $errors[] = 'Ugenummer skal være mellem 1 og 53.';
            }
            $ruleMonth = null;
        } elseif ($rec !== 'last_week') {
            $ruleMonth = null;
        }

        // Personer: id'er på eksisterende personer
        $people = People::all();
        $personIds = array_values(array_unique(array_filter(array_map('intval', (array)($in['people'] ?? [])),
            fn($p) => isset($people[$p]))));

        $links = self::parseLinks($in, $errors);

        // Afhængigheder
        $all = self::all();
        $deps = array_values(array_unique(array_filter(array_map('intval', (array)($in['depends_on'] ?? [])),
            fn($d) => $d !== $id && isset($all[$d]))));
        if ($id && $deps && self::wouldCreateCycle($id, $deps, $all)) {
            $errors[] = 'Afhængighederne giver en cirkel (A afhænger af B, som afhænger af A).';
        }

        // Filer: tjek før vi skriver noget
        $newFiles = self::parseUploads($uploads, $errors);

        if ($errors) {
            return [$id, $errors];
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $vals = [$title, trim((string)($in['description'] ?? '')) ?: null, $categoryId, $startDate, $endDate,
                $duration, $rec, $interval, $ruleMonth, $ruleWeekday, $ruleNth];
            if ($id) {
                $pdo->prepare('UPDATE events SET title=?, description=?, category_id=?, start_date=?, end_date=?, duration_days=?,
                    recurrence=?, rec_interval=?, rule_month=?, rule_weekday=?, rule_nth=? WHERE id=?')
                    ->execute([...$vals, $id]);
            } else {
                $pdo->prepare('INSERT INTO events (title, description, category_id, start_date, end_date, duration_days,
                    recurrence, rec_interval, rule_month, rule_weekday, rule_nth) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
                    ->execute($vals);
                $id = (int)$pdo->lastInsertId();
            }

            $pdo->prepare('DELETE FROM event_people WHERE event_id=?')->execute([$id]);
            $link = $pdo->prepare('INSERT INTO event_people (event_id, person_id) VALUES (?,?)');
            foreach ($personIds as $pid) {
                $link->execute([$id, $pid]);
            }

            $pdo->prepare('DELETE FROM event_links WHERE event_id=?')->execute([$id]);
            $ins = $pdo->prepare('INSERT INTO event_links (event_id, url, label) VALUES (?,?,?)');
            foreach ($links as $l) {
                $ins->execute([$id, $l['url'], $l['label']]);
            }

            $pdo->prepare('DELETE FROM event_dependencies WHERE event_id=?')->execute([$id]);
            $ins = $pdo->prepare('INSERT INTO event_dependencies (event_id, depends_on_id) VALUES (?,?)');
            foreach ($deps as $d) {
                $ins->execute([$id, $d]);
            }

            // Slet markerede filer
            foreach (array_map('intval', (array)($in['delete_file'] ?? [])) as $fid) {
                self::deleteFile($id, $fid);
            }

            // Gem nye filer
            $ins = $pdo->prepare('INSERT INTO event_files (event_id, original_name, stored_name, mime_type, size_bytes) VALUES (?,?,?,?,?)');
            foreach ($newFiles as $f) {
                [$stored, $mime] = self::storeUpload($f);
                $ins->execute([$id, $f['name'], $stored, $mime, $f['size']]);
            }

            $pdo->commit();
        } catch (Throwable $t) {
            $pdo->rollBack();
            return [$id, ['Kunne ikke gemme: ' . $t->getMessage()]];
        }
        return [$id, []];
    }

    public static function deleteFile(int $eventId, int $fileId): void
    {
        self::removeFiles('event_files', 'id=? AND event_id=?', [$fileId, $eventId]);
    }

    /** Sletter begivenheden med alle dens filer, også dem på de enkelte forekomster. Resten fjernes af ON DELETE CASCADE. */
    public static function delete(int $id): void
    {
        self::removeFiles('event_files', 'event_id=?', [$id]);
        try {
            self::removeFiles('occurrence_files', 'event_id=?', [$id]);
        } catch (PDOException) {
            // Tabellen findes ikke endnu (migrationen er ikke kørt)
        }
        db()->prepare('DELETE FROM events WHERE id=?')->execute([$id]);
    }

    /** Sletter filer fra $table (event_files eller occurrence_files), både rækkerne og filerne i upload-mappen. */
    private static function removeFiles(string $table, string $where, array $params): void
    {
        $st = db()->prepare("SELECT id, stored_name FROM $table WHERE $where");
        $st->execute($params);
        foreach ($st->fetchAll(PDO::FETCH_KEY_PAIR) as $fileId => $stored) {
            @unlink(config('upload_dir') . '/' . $stored);
            db()->prepare("DELETE FROM $table WHERE id=?")->execute([$fileId]);
        }
    }

    /** Links fra formularens link_url[]/link_label[]. Fejl tilføjes $errors. @return list<array{url:string,label:?string}> */
    private static function parseLinks(array $in, array &$errors): array
    {
        $links = [];
        foreach ((array)($in['link_url'] ?? []) as $i => $url) {
            $url = trim((string)$url);
            if ($url === '') {
                continue;
            }
            if (!preg_match('~^https?://~i', $url) || !filter_var($url, FILTER_VALIDATE_URL)) {
                $errors[] = 'Ugyldigt link: ' . $url . ' (skal starte med http:// eller https://)';
                continue;
            }
            $links[] = ['url' => $url, 'label' => trim((string)($in['link_label'][$i] ?? '')) ?: null];
        }
        return $links;
    }

    /** Uploadede filer fra $_FILES['files'], tjekket for fejl og størrelse. Fejl tilføjes $errors. */
    private static function parseUploads(array $uploads, array &$errors): array
    {
        $maxBytes = (int)config('max_upload_mb') * 1024 * 1024;
        $files = [];
        if (!empty($uploads['name']) && is_array($uploads['name'])) {
            foreach ($uploads['name'] as $i => $name) {
                $err = $uploads['error'][$i];
                if ($err === UPLOAD_ERR_NO_FILE) {
                    continue;
                }
                if ($err !== UPLOAD_ERR_OK) {
                    $errors[] = "Upload af \"$name\" fejlede (kode $err).";
                    continue;
                }
                if ($uploads['size'][$i] > $maxBytes) {
                    $errors[] = "\"$name\" er større end " . config('max_upload_mb') . ' MB.';
                    continue;
                }
                $files[] = ['name' => basename($name), 'tmp' => $uploads['tmp_name'][$i], 'size' => (int)$uploads['size'][$i]];
            }
        }
        return $files;
    }

    /** Flytter en uploadet fil til upload-mappen under et tilfældigt navn. @return array{0:string,1:string} [gemt navn, MIME-type] */
    private static function storeUpload(array $f): array
    {
        $dir = config('upload_dir');
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $stored = bin2hex(random_bytes(16));
        if (!move_uploaded_file($f['tmp'], "$dir/$stored")) {
            throw new RuntimeException('Kunne ikke gemme filen ' . $f['name']);
        }
        return [$stored, (new finfo(FILEINFO_MIME_TYPE))->file("$dir/$stored") ?: 'application/octet-stream'];
    }
}
