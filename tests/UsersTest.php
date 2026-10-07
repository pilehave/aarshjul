<?php
declare(strict_types=1);

// --- Users::validate ---

function user_row(array $overrides = []): array
{
    return $overrides + ['name' => 'Anne Holm', 'email' => 'anne@example.com', 'role' => 'reader', 'person' => null, 'active' => true];
}

test('Gyldige brugere', function () {
    $rows = [
        1 => user_row(['name' => 'Admin', 'email' => 'admin@example.com', 'role' => 'admin']),
        2 => user_row(['person' => 7]),
        'new' => user_row(['name' => 'Bo', 'email' => 'bo@example.com', 'role' => 'contributor']),
    ];
    assert_same([], Users::validate($rows, 1, [7]));
});

test('Mail og person skal være unikke', function () {
    $rows = [
        1 => user_row(['email' => 'admin@example.com', 'role' => 'admin', 'person' => 7]),
        2 => user_row(['email' => 'anne@example.com', 'person' => 7]),
        'new' => user_row(['email' => 'anne@example.com']),
    ];
    $errors = Users::validate($rows, 1, [7]);
    assert_true(in_array('Mailadressen anne@example.com bruges af flere brugere.', $errors, true), 'Mail');
    assert_true(in_array('En person kan kun kobles til én bruger.', $errors, true), 'Person');
});

test('Ugyldig mail, rolle, person og navn afvises', function () {
    $rows = [
        1 => user_row(['email' => 'admin@example.com', 'role' => 'admin']),
        2 => user_row(['email' => 'ikke-en-mail']),
        3 => user_row(['email' => 'c@example.com', 'role' => 'superuser']),
        4 => user_row(['email' => 'd@example.com', 'person' => 99]),
        5 => user_row(['email' => 'e@example.com', 'name' => '']),
    ];
    assert_same(4, count(Users::validate($rows, 1, [7])));
});

test('Man kan ikke fjerne sin egen administratoradgang', function () {
    $other = user_row(['email' => 'bo@example.com', 'role' => 'admin']);
    $demoted = [1 => user_row(['role' => 'contributor']), 2 => $other];
    $deactivated = [1 => user_row(['role' => 'admin', 'active' => false]), 2 => $other];
    assert_same(['Du kan ikke fjerne din egen administratoradgang eller deaktivere dig selv.'], Users::validate($demoted, 1, []));
    assert_same(['Du kan ikke fjerne din egen administratoradgang eller deaktivere dig selv.'], Users::validate($deactivated, 1, []));
    assert_same([], Users::validate($demoted, 2, []), 'En anden administrator må gerne ændre rollen');
});

test('Der skal være mindst én aktiv administrator', function () {
    $rows = [1 => user_row(['role' => 'admin', 'active' => false]), 2 => user_row(['email' => 'bo@example.com'])];
    assert_same(['Der skal være mindst én aktiv administrator.'], Users::validate($rows, 0, []));
});
