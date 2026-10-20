<?php
declare(strict_types=1);

/** En begivenhed som fra Events::all() med de felter, en skabelon bruger. */
function tpl_ev(array $overrides = []): array
{
    return ev($overrides + [
        'title' => 'Budget', 'description' => '', 'category_id' => '1', 'category_name' => 'Økonomi',
        'people' => [], 'links' => [],
    ]);
}

const TPL_CATEGORIES = [
    1 => ['id' => 1, 'name' => 'Økonomi', 'color' => '#2f6fde', 'ring_color' => null],
    2 => ['id' => 2, 'name' => 'HR', 'color' => '#e30613', 'ring_color' => '#eef2f8'],
];
const TPL_PEOPLE = [1 => ['id' => 1, 'name' => 'Anne Holm'], 2 => ['id' => 2, 'name' => 'Bo Berg']];

// --- Template::build ---

test('Skabelon har kategori, personer, links og afhængigheder som navne og ref', function () {
    $events = [
        1 => tpl_ev(['id' => 1, 'people' => [['id' => 1, 'name' => 'Anne Holm']],
            'links' => [['id' => 5, 'event_id' => 1, 'url' => 'https://x.dk', 'label' => '']]]),
        2 => tpl_ev(['id' => 2, 'title' => 'Opfølgning', 'depends_on' => [1], 'duration_days' => '3', 'rule_nth' => '2']),
    ];
    $tpl = Template::build($events, TPL_CATEGORIES, TPL_PEOPLE, false);
    assert_same(Template::FORMAT, $tpl['format']);
    assert_same(2, count($tpl['categories']), 'Uden filter kommer alle kategorier med');
    assert_same(['Anne Holm', 'Bo Berg'], $tpl['people']);
    assert_same('Økonomi', $tpl['events'][0]['category']);
    assert_same(['Anne Holm'], $tpl['events'][0]['people']);
    assert_same([['url' => 'https://x.dk', 'label' => null]], $tpl['events'][0]['links']);
    assert_same(null, $tpl['events'][0]['description']);
    assert_same([1], $tpl['events'][1]['depends_on']);
    assert_same(3, $tpl['events'][1]['duration_days']);
    assert_same(2, $tpl['events'][1]['rule_nth']);
});

test('Filtreret skabelon har kun brugte kategorier og personer og udelader afhængigheder udenfor', function () {
    $events = [2 => tpl_ev(['id' => 2, 'depends_on' => [1], 'people' => [['id' => 2, 'name' => 'Bo Berg']]])];
    $tpl = Template::build($events, TPL_CATEGORIES, TPL_PEOPLE, true);
    assert_same(['Økonomi'], array_column($tpl['categories'], 'name'));
    assert_same(['Bo Berg'], $tpl['people']);
    assert_same([], $tpl['events'][0]['depends_on']);
});

// --- Template::parse ---

test('En eksporteret skabelon kan læses igen', function () {
    $events = [1 => tpl_ev(['id' => 1]), 2 => tpl_ev(['id' => 2, 'depends_on' => [1]])];
    $json = json_encode(Template::build($events, TPL_CATEGORIES, TPL_PEOPLE, false));
    [$tpl, $errors] = Template::parse($json);
    assert_same([], $errors);
    assert_same(2, count($tpl['events']));
});

test('Ugyldige skabeloner afvises med en fejl', function () {
    $valid = Template::build([1 => tpl_ev(['id' => 1])], TPL_CATEGORIES, TPL_PEOPLE, false);
    $cases = [
        'ikke JSON'           => '{',
        'forkert format'      => json_encode(['format' => 'andet', 'version' => 1]),
        'nyere version'       => json_encode(['version' => 99] + $valid),
        'ukendt kategori'     => json_encode(['events' => [['category' => 'Findes ikke'] + $valid['events'][0]]] + $valid),
        'ukendt afhængighed'  => json_encode(['events' => [['depends_on' => [42]] + $valid['events'][0]]] + $valid),
        'afhænger af sig selv' => json_encode(['events' => [['depends_on' => [1]] + $valid['events'][0]]] + $valid),
        'dobbelt ref'         => json_encode(['events' => [$valid['events'][0], $valid['events'][0]]] + $valid),
        'ugyldig startdato'   => json_encode(['events' => [['start_date' => '2026-13-01'] + $valid['events'][0]]] + $valid),
        'ugyldig farve'       => json_encode(['categories' => [['name' => 'Økonomi', 'color' => 'rød']]] + $valid),
        'titel som liste'     => json_encode(['events' => [['title' => ['x']] + $valid['events'][0]]] + $valid),
        'person som tal'      => json_encode(['events' => [['people' => [7]] + $valid['events'][0]]] + $valid),
    ];
    foreach ($cases as $name => $json) {
        [$tpl, $errors] = Template::parse($json);
        assert_same(null, $tpl, $name);
        assert_true($errors !== [], "$name: ingen fejl");
    }
});

// --- Template::shiftDate / yearShift ---

test('Datoer flyttes med hele år', function () {
    assert_same('2027-03-15', Template::shiftDate('2026-03-15', 1));
    assert_same('2023-03-15', Template::shiftDate('2026-03-15', -3));
    assert_same('2026-03-15', Template::shiftDate('2026-03-15', 0, true));
    assert_same('2025-02-28', Template::shiftDate('2024-02-29', 1), '29. februar uden skudår');
});

test('Ugentlige datoer beholder ugedagen', function () {
    // Mandag 2. marts 2026 + 1 år = tirsdag 2. marts 2027, nærmeste mandag er 1. marts
    assert_same('2027-03-01', Template::shiftDate('2026-03-02', 1, true));
    // Mandag 6. januar 2025 + 2 år = onsdag 6. januar 2027, nærmeste mandag er 4. januar
    assert_same('2027-01-04', Template::shiftDate('2025-01-06', 2, true));
    assert_same('1', (new DateTimeImmutable(Template::shiftDate('2026-03-02', 5, true)))->format('N'));
});

test('Flytning regnes ud fra den tidligste startdato og årshjulets startmåned', function () {
    $tpl = ['events' => [['start_date' => '2024-09-10'], ['start_date' => '2024-03-01']]];
    assert_same(2, Template::yearShift($tpl, 2026));
    set_start_month(8);
    // 1. marts 2024 hører til årshjulet 2023/24
    assert_same(3, Template::yearShift($tpl, 2026));
});
