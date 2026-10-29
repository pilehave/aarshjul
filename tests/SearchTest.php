<?php
declare(strict_types=1);

/** En begivenhed med de felter, søgningen bruger. */
function search_ev(array $overrides = []): array
{
    return ev($overrides + ['title' => 'Generalforsamling', 'description' => null, 'category_id' => 1,
        'category_name' => 'Bestyrelse', 'people' => []]);
}

// --- Events::matches ---

test('Søgning finder titel, beskrivelse, kategori og personer', function () {
    $e = search_ev(['description' => 'Husk dagsorden', 'people' => [['id' => 7, 'name' => 'Søren Ærø']]]);
    assert_true(Events::matches($e, 'general'));
    assert_true(Events::matches($e, 'dagsorden'));
    assert_true(Events::matches($e, 'bestyrelse'));
    assert_true(Events::matches($e, 'søren'));
    assert_false_match($e, 'budget');
});

test('Søgning er uafhængig af store og små bogstaver, også æ, ø og å', function () {
    $e = search_ev(['title' => 'Årsmøde i Ølstykke', 'people' => [['id' => 1, 'name' => 'Ærtebjerg']]]);
    assert_true(Events::matches($e, 'ÅRSMØDE'));
    assert_true(Events::matches($e, 'ølSTYKKE'));
    assert_true(Events::matches($e, 'ærte'));
});

test('Alle ord skal matche, men gerne i hver sit felt', function () {
    $e = search_ev(['people' => [['id' => 1, 'name' => 'Anne Holm']]]);
    assert_true(Events::matches($e, '  general   anne '));
    assert_false_match($e, 'general budget');
    assert_true(Events::matches($e, ''), 'En tom søgning matcher alt');
});

function assert_false_match(array $e, string $q): void
{
    assert_true(!Events::matches($e, $q), "\"$q\" burde ikke matche");
}

// --- Events::search ---

test('Søgeresultat har næste forekomst, også i et andet år', function () {
    $all = [
        1 => search_ev(['id' => 1, 'start_date' => '2020-03-15', 'recurrence' => 'yearly']),
        2 => search_ev(['id' => 2, 'title' => 'Ekstraordinær generalforsamling', 'start_date' => '2028-01-10']),
        3 => search_ev(['id' => 3, 'title' => 'Budget']),
    ];
    $r = Events::search($all, 'generalforsamling', null, null, '2026-10-27');
    assert_same([1, 2], array_map(fn($x) => (int)$x['event']['id'], $r));
    assert_same('2027-03-15', $r[0]['next']);
    assert_same('2028-01-10', $r[1]['next']);
    assert_same(null, $r[0]['last']);
});

test('Søgeresultat uden flere forekomster viser den seneste og kommer sidst', function () {
    $all = [
        1 => search_ev(['id' => 1, 'start_date' => '2024-02-01', 'recurrence' => 'monthly', 'end_date' => '2024-06-30']),
        2 => search_ev(['id' => 2, 'start_date' => '2027-01-01']),
    ];
    $r = Events::search($all, '', null, null, '2026-10-27');
    assert_same([2, 1], array_map(fn($x) => (int)$x['event']['id'], $r));
    assert_same(null, $r[1]['next']);
    assert_same('2024-06-01', $r[1]['last']);
});

test('En igangværende forekomst er den næste', function () {
    $all = [1 => search_ev(['start_date' => '2026-10-20', 'duration_days' => 14])];
    $r = Events::search($all, '', null, null, '2026-10-27');
    assert_same('2026-10-20', $r[0]['next']);
    assert_same(null, $r[0]['last']);
});

test('Søgning kombineres med person og kategori', function () {
    $all = [
        1 => search_ev(['id' => 1, 'people' => [['id' => 5, 'name' => 'Anne']]]),
        2 => search_ev(['id' => 2, 'category_id' => 2, 'people' => [['id' => 5, 'name' => 'Anne']]]),
        3 => search_ev(['id' => 3]),
    ];
    $ids = fn(array $r) => array_map(fn($x) => (int)$x['event']['id'], $r);
    assert_same([1, 2], $ids(Events::search($all, 'general', 5, null, '2025-01-01')));
    assert_same([2], $ids(Events::search($all, 'general', 5, 2, '2025-01-01')));
    assert_same([1, 3], $ids(Events::search($all, '', null, 1, '2025-01-01')));
});

// --- wheel_year ---

test('Hjulåret for en dato følger startmåneden', function () {
    assert_same(2027, wheel_year('2027-03-01'));
    set_start_month(8);
    assert_same(2026, wheel_year('2027-03-01'));
    assert_same(2027, wheel_year('2027-08-01'));
});
