<?php
declare(strict_types=1);

// --- Auth::validatePassword ---

test('Gyldig adgangskode, også med danske bogstaver og specialtegn', function () {
    assert_same([], Auth::validatePassword('Hemmelig2026', 'Hemmelig2026'));
    assert_same([], Auth::validatePassword('æøå-ÆØÅ 1 lang!', 'æøå-ÆØÅ 1 lang!'));
    assert_same([], Auth::validatePassword('Øl' . str_repeat('x', 253) . '1', 'Øl' . str_repeat('x', 253) . '1'), '256 tegn er tilladt');
});

test('Adgangskode for kort eller for lang', function () {
    assert_same(1, count(Auth::validatePassword('Kort1abcdef', 'Kort1abcdef')), '11 tegn er for kort');
    $long = 'Aa1' . str_repeat('x', 254);
    assert_same(1, count(Auth::validatePassword($long, $long)), '257 tegn er for langt');
    $multibyte = 'Ææ1' . str_repeat('ø', 9);
    assert_same([], Auth::validatePassword($multibyte, $multibyte), 'Længden tælles i tegn, ikke bytes');
});

test('Adgangskode mangler stort bogstav, lille bogstav eller tal', function () {
    foreach (['hemmelig2026', 'HEMMELIG2026', 'HemmeligAbcd'] as $pw) {
        assert_same(1, count(Auth::validatePassword($pw, $pw)), $pw);
    }
});

test('De to adgangskoder skal være ens', function () {
    assert_same(['De to adgangskoder er ikke ens.'], Auth::validatePassword('Hemmelig2026', 'Hemmelig2027'));
});

test('Hash kan verificeres', function () {
    $h = Auth::hash('Hemmelig2026');
    assert_true(password_verify('Hemmelig2026', $h));
    assert_true(!password_verify('Hemmelig2027', $h));
});

// --- Auth::can ---

test('Rollerne er ordnet læser < bidragyder < administrator', function () {
    $reader = ['role' => 'reader'];
    $contributor = ['role' => 'contributor'];
    $admin = ['role' => 'admin'];
    assert_true(Auth::can('reader', $reader));
    assert_true(!Auth::can('contributor', $reader));
    assert_true(Auth::can('contributor', $contributor));
    assert_true(!Auth::can('admin', $contributor));
    assert_true(Auth::can('reader', $admin) && Auth::can('contributor', $admin) && Auth::can('admin', $admin));
    assert_true(!Auth::can('reader', ['role' => 'ukendt']));
    assert_true(!Auth::can('reader'), 'Ingen indlogget bruger i CLI');
});

// --- local_path ---

test('Kun lokale stier accepteres som redirect', function () {
    assert_same('/index.php?year=2026', local_path('/index.php?year=2026'));
    assert_same('/', local_path('/'));
    foreach (['//evil.example', '/\\evil.example', 'https://evil.example', 'index.php', '', "/a\nLocation: x", 'javascript:alert(1)'] as $bad) {
        assert_same('index.php', local_path($bad), var_export($bad, true));
    }
});
