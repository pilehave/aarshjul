<?php
declare(strict_types=1);

// --- Wheel::rings: faste ringe pr. kategori eller person (issue #7) ---

/** En forekomst som fra Events::occurrencesForYear() */
function occ_row(int $id, string $date, string $end, string $category, array $people = [], ?string $ringColor = null): array
{
    return [
        'event' => ev([
            'id' => $id, 'title' => "Begivenhed $id", 'color' => '#2f6fde', 'description' => '',
            'category_id' => crc32($category), 'category_name' => $category, 'category_ring_color' => $ringColor,
            'people' => array_map(fn($p) => ['id' => crc32($p), 'name' => $p], $people),
        ]),
        'date' => $date, 'end' => $end, 'done' => false, 'completed' => null, 'overdue' => false, 'blocked' => false, 'prereqs' => [],
        'note' => null, 'note_at' => null, 'note_by' => null, 'links' => [], 'files' => [],
    ];
}

/** Ringenes navne og antal baner */
function ring_summary(array $rings): array
{
    return array_map(fn($r) => [$r['name'], count($r['lanes'])], $rings);
}

test('Én ring pr. kategori, sorteret efter navn med dansk alfabet, den første yderst', function () {
    $occ = [
        occ_row(1, '2026-03-01', '2026-03-01', 'Økonomi'),
        occ_row(2, '2026-04-01', '2026-04-01', 'HR'),
        occ_row(3, '2026-05-01', '2026-05-01', 'Zoner'),
        occ_row(4, '2026-06-01', '2026-06-01', 'Administration'),
    ];
    assert_same([['Administration', 1], ['HR', 1], ['Zoner', 1], ['Økonomi', 1]],
        ring_summary(Wheel::rings($occ, 'category', '2026-01-01', '2026-12-31')));
});

test('Overlappende begivenheder i samme ring deles i baner', function () {
    $occ = [
        'a' => occ_row(1, '2026-03-01', '2026-03-20', 'HR'),
        'b' => occ_row(2, '2026-03-10', '2026-03-30', 'HR'), // overlapper a
        'c' => occ_row(3, '2026-06-01', '2026-06-05', 'HR'), // overlapper ingen: deler bane med a
        'd' => occ_row(4, '2026-03-10', '2026-03-30', 'IT'), // anden ring
    ];
    $rings = Wheel::rings($occ, 'category', '2026-01-01', '2026-12-31');
    assert_same([['HR', 2], ['IT', 1]], ring_summary($rings));
    assert_same(['a', 'c'], array_column($rings[0]['lanes'][0], 0));
    assert_same(['b'], array_column($rings[0]['lanes'][1], 0));
});

test('Baggrundsfarven følger kategoriens ring', function () {
    $rings = Wheel::rings([occ_row(1, '2026-03-01', '2026-03-01', 'HR', [], '#ffeecc'), occ_row(2, '2026-03-01', '2026-03-01', 'IT')],
        'category', '2026-01-01', '2026-12-31');
    assert_same(['#ffeecc', null], array_column($rings, 'bg'));
});

test('Én ring pr. person: flere personer giver flere ringe, uden person samles til sidst', function () {
    $occ = [
        'x' => occ_row(1, '2026-03-01', '2026-03-01', 'HR', ['Bo', 'Anne']),
        'y' => occ_row(2, '2026-04-01', '2026-04-01', 'HR'),
        'z' => occ_row(3, '2026-05-01', '2026-05-01', 'HR', ['Æsel']),
    ];
    $rings = Wheel::rings($occ, 'person', '2026-01-01', '2026-12-31');
    assert_same([['Anne', 1], ['Bo', 1], ['Æsel', 1], ['Ingen person', 1]], ring_summary($rings));
    assert_same(['x'], array_column($rings[0]['lanes'][0], 0));
    assert_same(['x'], array_column($rings[1]['lanes'][0], 0), 'Samme forekomst i begge personers ringe');
    assert_same(['y'], array_column($rings[3]['lanes'][0], 0));
});

test('Automatisk: én ring uden navn, som før', function () {
    $occ = [occ_row(1, '2026-03-01', '2026-03-20', 'HR'), occ_row(2, '2026-03-10', '2026-03-30', 'IT'), occ_row(3, '2026-06-01', '2026-06-01', 'IT')];
    assert_same([[null, 2]], ring_summary(Wheel::rings($occ, 'auto', '2026-01-01', '2026-12-31')));
    assert_same([], Wheel::rings([], 'category', '2026-01-01', '2026-12-31'), 'Ingen forekomster, ingen ringe');
});

// --- Wheel::svg med faste ringe ---

test('Ringnavne står fire gange i hver ring og kan slås fra for kategorier', function () {
    $occ = [occ_row(1, '2026-03-01', '2026-03-01', 'HR', ['Anne']), occ_row(2, '2026-04-01', '2026-04-01', 'IT', [], '#ffeecc')];
    $names = fn(string $svg) => array_count_values(array_map('html_entity_decode',
        preg_match_all('~<textPath href="#p-r\d+q\d"[^>]*>([^<]*)</textPath>~', $svg, $m) ? $m[1] : []));

    set_setting('ring_mode', 'category');
    $svg = Wheel::svg(2026, $occ, false);
    assert_same(['HR' => 4, 'IT' => 4], $names($svg));
    assert_same(1, preg_match_all('~<path class="ring-bg" [^>]*fill="#ffeecc"~', $svg), 'Baggrund i IT-ringen');

    set_setting('ring_names', '0');
    $svg = Wheel::svg(2026, $occ, false);
    assert_same([], $names($svg), 'Kategorinavne slået fra');
    assert_true(!str_contains($svg, 'class="ring-band"'));
    assert_same(1, preg_match_all('~<path class="ring-bg" ~', $svg), 'Baggrunden vises stadig');

    set_setting('ring_mode', 'person');
    assert_same(['Anne' => 4, 'Ingen person' => 4], $names(Wheel::svg(2026, $occ, false)), 'Personnavne vises altid');

    set_setting('ring_mode', 'auto');
    $svg = Wheel::svg(2026, $occ, false);
    assert_same([], $names($svg));
    assert_true(!str_contains($svg, 'class="ring-bg"'), 'Ingen baggrund i automatisk tilstand');
});
