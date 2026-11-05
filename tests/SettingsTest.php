<?php
declare(strict_types=1);

/** Skriver $content til en midlertidig fil, kalder $fn med stien og sletter filen bagefter. */
function with_temp_file(string $content, callable $fn): mixed
{
    $path = tempnam(sys_get_temp_dir(), 'logo');
    file_put_contents($path, $content);
    try {
        return $fn($path);
    } finally {
        unlink($path);
    }
}

/** Gyldige felter fra indstillingssiden, som kan overskrives. */
function settings_input(array $overrides = []): array
{
    return $overrides + ['start_month' => '1', 'ring_mode' => 'auto', 'topbar_bg' => '#e30613', 'topbar_fg' => '#ffffff', 'topbar_text' => 'Årshjul'];
}

test('Topbar: standardfarver og -tekst, når intet er gemt', function () {
    assert_same('#e30613', Settings::topbarBg());
    assert_same('#ffffff', Settings::topbarFg());
    assert_same('Årshjul', Settings::topbarText());
    assert_same(null, Settings::topbarLogo());
});

test('Topbar: gemte farver bruges, ugyldige falder tilbage til standard', function () {
    set_setting('topbar_bg', '#1A2B3C');
    set_setting('topbar_fg', 'red; background: url(x)');
    assert_same('#1a2b3c', Settings::topbarBg());
    assert_same('#ffffff', Settings::topbarFg());
});

test('Topbar: tom tekst giver standardteksten, medmindre der er et logo', function () {
    set_setting('topbar_text', '  ');
    assert_same('Årshjul', Settings::topbarText());
    set_setting('topbar_logo', str_repeat('a', 32));
    set_setting('topbar_logo_type', 'image/png');
    assert_same('', Settings::topbarText());
});

test('Topbar: logoet kræver et gyldigt gemt navn og en tilladt type', function () {
    set_setting('topbar_logo', '../../config.php');
    set_setting('topbar_logo_type', 'image/png');
    assert_same(null, Settings::topbarLogo());
    set_setting('topbar_logo', str_repeat('b', 32));
    set_setting('topbar_logo_type', 'text/html');
    assert_same(null, Settings::topbarLogo());
    set_setting('topbar_logo_type', 'image/svg+xml');
    assert_same(config('upload_dir') . '/' . str_repeat('b', 32), Settings::topbarLogo()['file']);
});

test('Logoets højde: 32 px uden værdi, ellers den gemte inden for grænserne', function () {
    assert_same(32, Settings::logoHeight());
    set_setting('topbar_logo_height', '48');
    assert_same(48, Settings::logoHeight());
    foreach (['', '15', '121', '40px', '-20', '2.5'] as $bad) {
        set_setting('topbar_logo_height', $bad);
        assert_same(32, Settings::logoHeight(), "Værdien '$bad'");
    }
});

test('Indstillinger: logoets højde skal være tom eller 16–120', function () {
    // Den ugyldige baggrundsfarve sørger for, at intet gemmes (testene rører ikke databasen)
    $colorErr = 'Vælg en gyldig baggrundsfarve til topbaren.';
    $heightErr = 'Logoets højde skal være et helt tal mellem 16 og 120 pixels, eller tom.';
    foreach (['15', '121', 'abc', '40px', '2.5', '-20'] as $bad) {
        assert_same([$colorErr, $heightErr], Settings::saveAll(settings_input(['topbar_bg' => 'x', 'topbar_logo_height' => $bad])), "Værdien '$bad'");
    }
    foreach (['', ' ', '16', '32', '120'] as $ok) {
        assert_same([$colorErr], Settings::saveAll(settings_input(['topbar_bg' => 'x', 'topbar_logo_height' => $ok])), "Værdien '$ok'");
    }
});

test('Logotype: genkendes ud fra indholdet, ikke filnavnet', function () {
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
    assert_same('image/png', with_temp_file($png, Settings::logoType(...)));
    assert_same('image/gif', with_temp_file(base64_decode('R0lGODlhAQABAAAAACw='), Settings::logoType(...)));
    assert_same('image/jpeg', with_temp_file("\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01", Settings::logoType(...)));
    assert_same('image/svg+xml', with_temp_file('<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"/>', Settings::logoType(...)));
    assert_same('image/svg+xml', with_temp_file('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"></svg>', Settings::logoType(...)));
    assert_same(null, with_temp_file('<html><body>ikke et billede</body></html>', Settings::logoType(...)));
    assert_same(null, with_temp_file("%PDF-1.4\n", Settings::logoType(...)));
});

test('Indstillinger: ugyldige topbar-værdier afvises før noget gemmes', function () {
    $errors = Settings::saveAll(settings_input(['topbar_bg' => 'rød', 'topbar_fg' => '#fff', 'topbar_text' => str_repeat('x', 61)]));
    assert_same([
        'Vælg en gyldig baggrundsfarve til topbaren.',
        'Vælg en gyldig tekstfarve til topbaren.',
        'Teksten i topbaren må højst være 60 tegn.',
    ], $errors);
});

test('Indstillinger: tom tekst kræver et logo', function () {
    assert_same(['Skriv en tekst til topbaren, eller upload et logo.'], Settings::saveAll(settings_input(['topbar_text' => ''])));
    set_setting('topbar_logo', str_repeat('c', 32));
    set_setting('topbar_logo_type', 'image/png');
    assert_same(['Skriv en tekst til topbaren, eller upload et logo.'],
        Settings::saveAll(settings_input(['topbar_text' => '', 'remove_logo' => '1'])), 'Det fjernede logo tæller ikke');
});

test('Indstillinger: et logo, der ikke er et billede, afvises', function () {
    $errors = with_temp_file('<?php echo 1;', fn ($path) => Settings::saveAll(settings_input(),
        ['error' => UPLOAD_ERR_OK, 'size' => 13, 'tmp_name' => $path, 'name' => 'logo.png']));
    assert_same(['Logoet skal være en PNG-, JPG-, GIF-, WebP- eller SVG-fil.'], $errors);
    $errors = Settings::saveAll(settings_input(), ['error' => UPLOAD_ERR_OK, 'size' => Settings::LOGO_MAX_BYTES + 1, 'tmp_name' => '', 'name' => 'stor.png']);
    assert_same(['Logoet må højst fylde 1 MB.'], $errors);
});
