<?php
declare(strict_types=1);

// --- Events::prerequisites ---

test('Forudsætning er seneste forekomst på eller før datoen', function () {
    $a = ev(['id' => 1, 'start_date' => '2025-03-01', 'recurrence' => 'yearly']);
    $b = ev(['id' => 2, 'start_date' => '2026-06-01', 'depends_on' => [1]]);
    $all = [1 => $a, 2 => $b];

    $p = Events::prerequisites($b, '2026-06-01', $all, []);
    assert_same(1, count($p));
    assert_same('2026-03-01', $p[0]['date']);
    assert_same(false, $p[0]['done']);

    $p = Events::prerequisites($b, '2026-06-01', $all, ['1|2026-03-01' => true]);
    assert_same(true, $p[0]['done']);

    // Afkrydsning af sidste års forekomst tæller ikke
    $p = Events::prerequisites($b, '2026-06-01', $all, ['1|2025-03-01' => true]);
    assert_same(false, $p[0]['done']);
});

test('Forudsætning samme dag tæller med', function () {
    $a = ev(['id' => 1, 'start_date' => '2026-06-01']);
    $b = ev(['id' => 2, 'start_date' => '2026-06-01', 'depends_on' => [1]]);
    $p = Events::prerequisites($b, '2026-06-01', [1 => $a, 2 => $b], []);
    assert_same('2026-06-01', $p[0]['date']);
});

test('Forudsætning uden forekomst inden for et år blokerer ikke', function () {
    $a = ev(['id' => 1, 'start_date' => '2024-01-01']);
    $b = ev(['id' => 2, 'start_date' => '2026-06-01', 'depends_on' => [1]]);
    $p = Events::prerequisites($b, '2026-06-01', [1 => $a, 2 => $b], []);
    assert_same(null, $p[0]['date']);
    assert_same(true, $p[0]['done']);
});

test('Slettet forudsætning ignoreres', function () {
    $b = ev(['id' => 2, 'depends_on' => [99]]);
    assert_same([], Events::prerequisites($b, '2026-06-01', [2 => $b], []));
});

// --- Events::wouldCreateCycle ---

test('Cirkulære afhængigheder opdages', function () {
    $all = [
        1 => ev(['id' => 1, 'depends_on' => [2]]),
        2 => ev(['id' => 2, 'depends_on' => [3]]),
        3 => ev(['id' => 3, 'depends_on' => []]),
    ];
    assert_true(Events::wouldCreateCycle(3, [1], $all), '3 → 1 → 2 → 3 er en cyklus');
    assert_true(Events::wouldCreateCycle(1, [1], $all), 'En begivenhed kan ikke afhænge af sig selv');
    assert_true(!Events::wouldCreateCycle(3, [], $all), 'Ingen afhængigheder giver ingen cyklus');
    assert_true(!Events::wouldCreateCycle(4, [1], $all), 'Ny begivenhed, der afhænger af 1, er ok');
});

// --- Events::diffLinks ---

test('Uændrede links bliver stående, ændrede erstattes', function () {
    $existing = [
        ['id' => 1, 'url' => 'https://a.example', 'label' => 'A'],
        ['id' => 2, 'url' => 'https://b.example', 'label' => null],
        ['id' => 3, 'url' => 'https://c.example', 'label' => 'C'],
    ];
    $submitted = [
        ['url' => 'https://a.example', 'label' => 'A'],          // uændret
        ['url' => 'https://b.example', 'label' => 'Ny tekst'],   // ny tekst = nyt link
        ['url' => 'https://d.example', 'label' => null],         // nyt
    ];
    [$remove, $add] = Events::diffLinks($existing, $submitted);
    assert_same([2, 3], $remove);
    assert_same([['url' => 'https://b.example', 'label' => 'Ny tekst'], ['url' => 'https://d.example', 'label' => null]], $add);
});

test('Ens links parres ét for ét', function () {
    $existing = [['id' => 1, 'url' => 'https://a.example', 'label' => null], ['id' => 2, 'url' => 'https://a.example', 'label' => null]];
    $one = [['url' => 'https://a.example', 'label' => null]];
    assert_same([[2], []], Events::diffLinks($existing, $one), 'Det ene af to ens links fjernes');
    assert_same([[], [$one[0]]], Events::diffLinks([$existing[0]], [$one[0], $one[0]]), 'Et ekstra ens link tilføjes');
    assert_same([[], []], Events::diffLinks([], []));
});

// --- Hvem og hvornår ---

test('Tekster om hvem og hvornår', function () {
    $ts = (new DateTimeImmutable(date('Y') . '-10-03 14:00'))->getTimestamp();
    assert_same('Opfyldt 3. oktober af Anne Holm', Events::completedText(['at' => $ts, 'by' => 'Anne Holm']));
    assert_same('Opfyldt 3. oktober', Events::completedText(['at' => $ts, 'by' => null]), 'Flueben fra før login');
    assert_same('Note af Anne Holm, 3. oktober ' . date('Y'), Events::noteText($ts, 'Anne Holm'), 'Noten har altid årstal');
    assert_same('Note, 3. oktober ' . date('Y'), Events::noteText($ts, null));
    assert_same(null, Events::noteText(null, null));
    assert_same('Uploadet af Bo, 3. oktober', Events::uploadedText(['uploaded_ts' => (string)$ts, 'by_name' => 'Bo']));
    $old = (new DateTimeImmutable('2020-10-03 14:00'))->getTimestamp();
    assert_same('Opfyldt 3. oktober 2020', Events::completedText(['at' => $old, 'by' => null]), 'Andre år får årstal med');
});

test('Datoen følger dansk tid, ikke UTC', function () {
    // 23:30 UTC den 2. oktober er 01:30 dansk sommertid den 3. oktober
    $ts = (new DateTimeImmutable('2020-10-02 23:30', new DateTimeZone('UTC')))->getTimestamp();
    assert_same('Opfyldt 3. oktober 2020', Events::completedText(['at' => $ts, 'by' => null]));
});
