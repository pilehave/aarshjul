<?php
declare(strict_types=1);

/**
 * Skabeloner: begivenheder med kategorier, personer, links og afhængigheder som JSON, så strukturen kan
 * genbruges i et andet årshjul (eller bruges som backup). Filer, flueben og noter pr. forekomst er ikke med.
 * Afhængigheder angives med "ref", som er begivenhedens id i det årshjul, den blev eksporteret fra.
 */
final class Template
{
    public const FORMAT = 'aarshjul-skabelon';
    public const VERSION = 1;
    public const MAX_BYTES = 5 * 1024 * 1024;

    private const EVENT_FIELDS = ['title', 'description', 'start_date', 'end_date', 'duration_days', 'recurrence',
        'rec_interval', 'rule_month', 'rule_weekday', 'rule_nth'];

    /**
     * Skabelonen for $events (fra Events::all(), evt. filtreret). Med $onlyUsed kommer kun de kategorier og personer
     * med, som begivenhederne bruger, ellers alle. Afhængigheder af begivenheder uden for $events udelades.
     */
    public static function build(array $events, array $categories, array $people, bool $onlyUsed): array
    {
        if ($onlyUsed) {
            $categories = array_intersect_key($categories, array_flip(array_map(fn($e) => (int)$e['category_id'], $events)));
            $people = array_intersect_key($people, array_flip(array_merge([], ...array_map(fn($e) => array_column($e['people'], 'id'), array_values($events)))));
        }
        $int = fn($v) => $v === null || $v === '' ? null : (int)$v;
        $rows = [];
        foreach ($events as $e) {
            $row = ['ref' => (int)$e['id']] + array_intersect_key($e, array_flip(self::EVENT_FIELDS));
            $row = array_merge($row, [
                'description'   => ($e['description'] ?? '') === '' ? null : $e['description'],
                'end_date'      => $e['end_date'] ?: null,
                'duration_days' => (int)$e['duration_days'],
                'rec_interval'  => (int)$e['rec_interval'],
                'rule_month'    => $int($e['rule_month']),
                'rule_weekday'  => $int($e['rule_weekday']),
                'rule_nth'      => $int($e['rule_nth']),
                'category'      => $e['category_name'],
                'people'        => array_column($e['people'], 'name'),
                'links'         => array_map(fn($l) => ['url' => $l['url'], 'label' => $l['label'] ?: null], $e['links']),
                'depends_on'    => array_values(array_filter($e['depends_on'], fn($d) => isset($events[$d]))),
            ]);
            $rows[] = $row;
        }
        return [
            'format'     => self::FORMAT,
            'version'    => self::VERSION,
            'exported'   => date('c'),
            'categories' => array_values(array_map(fn($c) => ['name' => $c['name'], 'color' => $c['color'], 'ring_color' => $c['ring_color'] ?: null], $categories)),
            'people'     => array_values(array_column($people, 'name')),
            'events'     => $rows,
        ];
    }

    /**
     * Læser og tjekker opbygningen af en skabelon. Værdierne på den enkelte begivenhed valideres først
     * ved importen (af Events::save). @return array{0:?array,1:list<string>} [skabelon, fejl[]]
     */
    public static function parse(string $json): array
    {
        $tpl = json_decode($json, true, 32);
        if (!is_array($tpl)) {
            return [null, ['Filen er ikke gyldig JSON.']];
        }
        if (($tpl['format'] ?? null) !== self::FORMAT || !is_int($tpl['version'] ?? null)) {
            return [null, ['Filen er ikke en skabelon fra Årshjul.']];
        }
        if ($tpl['version'] > self::VERSION) {
            return [null, ['Skabelonen er lavet med en nyere version af Årshjul.']];
        }
        foreach (['categories', 'people', 'events'] as $key) {
            if (!array_is_list($tpl[$key] ?? null)) {
                return [null, ["Skabelonen mangler listen \"$key\"."]];
            }
        }

        $errors = [];
        $categories = [];
        foreach ($tpl['categories'] as $c) {
            if (!is_array($c) || !is_string($c['name'] ?? null) || trim($c['name']) === '') {
                $errors[] = 'En kategori i skabelonen mangler et navn.';
                continue;
            }
            if (!self::isColor($c['color'] ?? null) || (($c['ring_color'] ?? null) !== null && !self::isColor($c['ring_color']))) {
                $errors[] = 'Kategorien "' . $c['name'] . '" har en ugyldig farve.';
            }
            $categories[mb_strtolower(trim($c['name']))] = true;
        }
        foreach ($tpl['people'] as $p) {
            if (!is_string($p) || trim($p) === '') {
                $errors[] = 'En person i skabelonen mangler et navn.';
            }
        }

        $refs = [];
        foreach ($tpl['events'] as $e) {
            if (is_array($e) && is_int($e['ref'] ?? null)) {
                if (isset($refs[$e['ref']])) {
                    $errors[] = 'Flere begivenheder i skabelonen har ref ' . $e['ref'] . '.';
                }
                $refs[$e['ref']] = true;
            }
        }
        foreach ($tpl['events'] as $i => $e) {
            $name = is_array($e) && is_string($e['title'] ?? null) ? '"' . $e['title'] . '"' : 'Begivenhed nr. ' . ($i + 1);
            if (!is_array($e) || !is_int($e['ref'] ?? null) || !is_string($e['title'] ?? null)) {
                $errors[] = "$name mangler ref eller titel.";
                continue;
            }
            foreach (self::EVENT_FIELDS as $f) {
                if (isset($e[$f]) && !is_scalar($e[$f])) {
                    $errors[] = "$name har en ugyldig værdi i \"$f\".";
                }
            }
            if (!self::isDate($e['start_date'] ?? null)) {
                $errors[] = "$name har en ugyldig startdato.";
            }
            if (isset($e['end_date']) && !self::isDate($e['end_date'])) {
                $errors[] = "$name har en ugyldig slutdato for gentagelser.";
            }
            if (!is_string($e['category'] ?? null) || !isset($categories[mb_strtolower(trim($e['category']))])) {
                $errors[] = "$name har en kategori, der ikke findes i skabelonen.";
            }
            if (!array_is_list($e['people'] ?? []) || array_filter($e['people'] ?? [], fn($p) => !is_string($p) || trim($p) === '')) {
                $errors[] = "$name har en ugyldig liste af personer.";
            }
            if (!array_is_list($e['links'] ?? []) || array_filter($e['links'] ?? [], fn($l) => !is_array($l) || !is_string($l['url'] ?? null)
                    || !(is_string($l['label'] ?? null) || ($l['label'] ?? null) === null))) {
                $errors[] = "$name har en ugyldig liste af links.";
            }
            if (!array_is_list($e['depends_on'] ?? []) || array_filter($e['depends_on'] ?? [], fn($d) => !is_int($d) || !isset($refs[$d]) || $d === $e['ref'])) {
                $errors[] = "$name afhænger af en begivenhed, der ikke findes i skabelonen.";
            }
        }
        return $errors ? [null, $errors] : [$tpl, []];
    }

    /** Antal år, datoerne skal flyttes, for at den tidligste startdato falder i årshjulet for $year (se year_bounds). */
    public static function yearShift(array $tpl, int $year): int
    {
        $first = min(array_column($tpl['events'], 'start_date') ?: [year_bounds($year)[0]]);
        $d = new DateTimeImmutable($first);
        $wheelYear = (int)$d->format('Y') - ((int)$d->format('n') < Settings::startMonth() ? 1 : 0);
        return $year - $wheelYear;
    }

    /**
     * $ymd flyttet $years år. Den 29. februar bliver den 28. i et år, der ikke er skudår. Med $keepWeekday flyttes
     * datoen derefter op til tre dage til nærmeste dag med samme ugedag som før (bruges til ugentlige gentagelser).
     */
    public static function shiftDate(string $ymd, int $years, bool $keepWeekday = false): string
    {
        $d = new DateTimeImmutable($ymd);
        if ($years === 0) {
            return $d->format('Y-m-d');
        }
        $y = (int)$d->format('Y') + $years;
        $m = (int)$d->format('n');
        $day = min((int)$d->format('j'), (int)(new DateTimeImmutable(sprintf('%04d-%02d-01', $y, $m)))->format('t'));
        $new = new DateTimeImmutable(sprintf('%04d-%02d-%02d', $y, $m, $day));
        if ($keepWeekday) {
            $diff = (((int)$d->format('N') - (int)$new->format('N')) % 7 + 10) % 7 - 3;
            $new = $new->modify(sprintf('%+d days', $diff));
        }
        return $new->format('Y-m-d');
    }

    /**
     * Importerer en skabelon fra parse() i én transaktion, med datoerne flyttet $years år.
     * Kategorier og personer matches på navn eller oprettes. Begivenhederne valideres af Events::save,
     * og ved den mindste fejl ændres intet. @return array{0:array{events:int,categories:int,people:int},1:list<string>} [antal oprettede, fejl[]]
     */
    public static function import(array $tpl, int $years): array
    {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            [$stats, $errors] = self::write($tpl, $years);
            if ($errors) {
                $pdo->rollBack();
                return [$stats, $errors];
            }
            $pdo->commit();
        } catch (Throwable $t) {
            $pdo->rollBack();
            return [[], ['Kunne ikke importere: ' . $t->getMessage()]];
        }
        return [$stats, []];
    }

    private static function write(array $tpl, int $years): array
    {
        $pdo = db();
        $errors = [];
        $stats = ['events' => 0, 'categories' => 0, 'people' => 0];

        // Navne slås op i databasen, så de matches med samme sortering som de unikke nøgler (utf8mb4_danish_ci)
        $categoryIds = [];
        $find = $pdo->prepare('SELECT id FROM categories WHERE name = ?');
        $ins = $pdo->prepare('INSERT INTO categories (name, color, ring_color) VALUES (?, ?, ?)');
        foreach ($tpl['categories'] as $c) {
            $name = trim($c['name']);
            $find->execute([$name]);
            if (!$id = $find->fetchColumn()) {
                if (mb_strlen($name) > 100) {
                    $errors[] = 'Kategorinavnet "' . $name . '" er for langt (højst 100 tegn).';
                    continue;
                }
                $ins->execute([$name, strtolower($c['color']), isset($c['ring_color']) ? strtolower($c['ring_color']) : null]);
                $id = $pdo->lastInsertId();
                $stats['categories']++;
            }
            $categoryIds[mb_strtolower($name)] = (int)$id;
        }

        $personIds = [];
        $find = $pdo->prepare('SELECT id FROM people WHERE name = ?');
        $ins = $pdo->prepare('INSERT INTO people (name) VALUES (?)');
        $personNames = array_merge($tpl['people'], ...array_map(fn($e) => $e['people'] ?? [], $tpl['events']));
        foreach ($personNames as $name) {
            $name = trim($name);
            if (isset($personIds[mb_strtolower($name)])) {
                continue;
            }
            $find->execute([$name]);
            if (!$id = $find->fetchColumn()) {
                if (mb_strlen($name) > 150) {
                    $errors[] = 'Personnavnet "' . $name . '" er for langt (højst 150 tegn).';
                    continue;
                }
                $ins->execute([$name]);
                $id = $pdo->lastInsertId();
                $stats['people']++;
            }
            $personIds[mb_strtolower($name)] = (int)$id;
        }
        if ($errors) {
            return [$stats, $errors];
        }

        // Først begivenhederne uden afhængigheder, så alle har et id; derefter gemmes de med afhængigheder igen
        $ids = [];
        $inputs = [];
        $name = fn(int $i) => $tpl['events'][$i]['title'] !== '' ? '"' . $tpl['events'][$i]['title'] . '"' : 'Begivenhed nr. ' . ($i + 1);
        foreach ($tpl['events'] as $i => $e) {
            $in = [
                'title'        => $e['title'],
                'description'  => (string)($e['description'] ?? ''),
                'category_id'  => $categoryIds[mb_strtolower(trim($e['category']))],
                'start_date'   => self::shiftDate($e['start_date'], $years, ($e['recurrence'] ?? '') === 'weekly'),
                'end_date'     => isset($e['end_date']) ? self::shiftDate($e['end_date'], $years) : '',
                'duration_days' => $e['duration_days'] ?? 1,
                'recurrence'   => $e['recurrence'] ?? 'none',
                'rec_interval' => $e['rec_interval'] ?? 1,
                'rule_month'   => $e['rule_month'] ?? null,
                'rule_weekday' => $e['rule_weekday'] ?? null,
                'rule_nth'     => $e['rule_nth'] ?? null,
                'week_number'  => $e['rule_nth'] ?? null, // formularen sender ugenummeret i sit eget felt
                'people'       => array_map(fn($p) => $personIds[mb_strtolower(trim($p))], $e['people'] ?? []),
                'link_url'     => array_column($e['links'] ?? [], 'url'),
                'link_label'   => array_map(fn($l) => $l['label'] ?? '', $e['links'] ?? []),
            ];
            [$id, $errs] = Events::save(null, $in, []);
            foreach ($errs as $err) {
                $errors[] = $name($i) . ': ' . $err;
            }
            if ($id) {
                $ids[$e['ref']] = $id;
                $inputs[$e['ref']] = $in;
                $stats['events']++;
            }
        }
        if ($errors) {
            return [$stats, $errors];
        }
        foreach ($tpl['events'] as $i => $e) {
            if (!empty($e['depends_on'])) {
                $in = $inputs[$e['ref']] + ['depends_on' => array_map(fn($d) => $ids[$d], $e['depends_on'])];
                foreach (Events::save($ids[$e['ref']], $in, [])[1] as $err) {
                    $errors[] = $name($i) . ': ' . $err;
                }
            }
        }
        return [$stats, $errors];
    }

    /** Er $d en gyldig dato som "2026-03-15"? (createFromFormat alene godtager fx "2026-13-01" som januar året efter) */
    private static function isDate(mixed $d): bool
    {
        return is_string($d) && ($p = DateTimeImmutable::createFromFormat('!Y-m-d', $d)) && $p->format('Y-m-d') === $d;
    }

    private static function isColor(mixed $c): bool
    {
        return is_string($c) && preg_match('/^#[0-9a-fA-F]{6}$/', $c) === 1;
    }
}
