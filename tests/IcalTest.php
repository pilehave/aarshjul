<?php
declare(strict_types=1);

/** En forekomst som fra Events::occurrencesBetween() til kalenderfeedet. */
function ical_occ(array $event = [], array $occ = []): array
{
    return $occ + [
        'event'     => ev($event + ['id' => 7, 'title' => 'Budgetmøde', 'description' => '', 'category_name' => 'Økonomi',
            'people' => [], 'links' => []]),
        'date'      => '2026-03-10',
        'end'       => '2026-03-10',
        'done'      => false,
        'completed' => null,
        'overdue'   => false,
        'blocked'   => false,
        'note'      => null,
        'links'     => [],
    ];
}

/** Kalenderen pakket ud: fortsættelseslinjer samlet igen, som et kalenderprogram læser den. */
function ical_unfold(string $ics): array
{
    return explode("\r\n", rtrim(str_replace("\r\n ", '', $ics), "\r\n"));
}

test('iCal: tekst escapes efter RFC 5545', function () {
    assert_same('A\, B\; C \\\\ D\nE\nF', Ical::text("A, B; C \\ D\r\nE\nF"));
});

test('iCal: lange linjer ombrydes til højst 75 bytes uden at dele æøå', function () {
    $line = 'DESCRIPTION:' . str_repeat('æøå ', 60);
    $folded = Ical::fold($line);
    foreach (explode("\r\n", $folded) as $i => $part) {
        assert_true(strlen($part) <= 75, "Linje $i er " . strlen($part) . ' bytes');
        assert_true(mb_check_encoding($part, 'UTF-8'), "Linje $i deler et UTF-8-tegn");
        assert_true($i === 0 || $part[0] === ' ', "Linje $i starter ikke med mellemrum");
    }
    assert_same($line, str_replace("\r\n ", '', $folded));
    assert_same('KORT:linje', Ical::fold('KORT:linje'));
});

test('iCal: perioden er fra et år tilbage til to år frem', function () {
    assert_same(['2025-11-06', '2028-11-06'], Ical::window('2026-11-06'));
});

test('iCal: nøglen er 64 hex-tegn', function () {
    assert_true(Ical::isToken(str_repeat('a1', 32)));
    assert_true(!Ical::isToken(str_repeat('a1', 31)));
    assert_true(!Ical::isToken(str_repeat('A1', 32)));
    assert_true(!Ical::isToken(''));
});

test('iCal: adressen bygges fra app_url med valgfrit filter', function () {
    $base = config('app_url');
    $t = str_repeat('b', 64);
    assert_same("$base/ical.php?token=$t", Ical::url($t));
    assert_same("$base/ical.php?token=$t&person=3&category=2", Ical::url($t, 3, 2));
});

test('iCal: en forekomst bliver en heldagsbegivenhed med fast UID, og DTEND er dagen efter', function () {
    $ics = Ical::calendar([ical_occ(['duration_days' => 3], ['date' => '2026-12-30', 'end' => '2027-01-01'])],
        'Årshjul', 1793880000, 'https://hjul.example.dk');
    $lines = ical_unfold($ics);
    assert_true(str_ends_with($ics, "END:VCALENDAR\r\n"), 'Slutter med END:VCALENDAR og CRLF');
    assert_same('BEGIN:VCALENDAR', $lines[0]);
    foreach (['UID:event-7-2026-12-30@hjul.example.dk', 'DTSTART;VALUE=DATE:20261230', 'DTEND;VALUE=DATE:20270102',
        'SUMMARY:Budgetmøde', 'CATEGORIES:Økonomi', 'TRANSP:TRANSPARENT', 'X-WR-CALNAME:Årshjul',
        'DTSTAMP:' . gmdate('Ymd\THis\Z', 1793880000)] as $want) {
        assert_true(in_array($want, $lines, true), "Mangler linjen $want");
    }
    assert_same(1, substr_count($ics, 'BEGIN:VEVENT'));
});

test('iCal: uden forekomster er kalenderen tom, men gyldig', function () {
    $lines = ical_unfold(Ical::calendar([], 'X', 0, 'http://localhost'));
    assert_same('BEGIN:VCALENDAR', $lines[0]);
    assert_same('END:VCALENDAR', end($lines));
    assert_true(!in_array('BEGIN:VEVENT', $lines, true));
});

test('iCal: opfyldte forekomster får ✓ i titlen', function () {
    $lines = ical_unfold(Ical::calendar([ical_occ([], ['done' => true])], 'X', 0, 'http://localhost'));
    assert_true(in_array('SUMMARY:✓ Budgetmøde', $lines, true));
});

test('iCal: beskrivelsen har kategori, personer, status, tekst, note, links og link til hjulet', function () {
    set_start_month(8);
    $o = ical_occ(
        ['description' => 'Husk bilag', 'people' => [['id' => 1, 'name' => 'Anne Holm'], ['id' => 2, 'name' => 'Jonas Berg']],
         'links' => [['url' => 'https://a.dk', 'label' => 'Budget']]],
        ['overdue' => true, 'note' => 'Flyttet til Teams', 'links' => [['url' => 'https://b.dk', 'label' => '']]]
    );
    assert_same(implode("\n", [
        'Kategori: Økonomi',
        'Personer: Anne Holm, Jonas Berg',
        'Status: Overskredet',
        '',
        'Husk bilag',
        '',
        'Note: Flyttet til Teams',
        '',
        'Budget: https://a.dk',
        'https://b.dk',
        '',
        'Se i årshjulet: http://x/index.php?year=2025', // marts 2026 ligger i årshjulet 2025/26
    ]), Ical::description($o, 'http://x'));
});

test('iCal: venter på forudsætning vises som status', function () {
    assert_true(str_contains(Ical::description(ical_occ([], ['blocked' => true]), 'http://x'), 'Status: Venter på forudsætning'));
    assert_true(!str_contains(Ical::description(ical_occ(), 'http://x'), 'Status:'));
});
