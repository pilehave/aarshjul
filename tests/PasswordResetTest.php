<?php
declare(strict_types=1);

// --- PasswordReset ---

test('Linket bygges ud fra app_url, ikke fra Host-headeren', function () {
    $_SERVER['HTTP_HOST'] = 'evil.example';
    $token = str_repeat('ab', 32);
    assert_same(config('app_url') . '/reset.php?token=' . $token, PasswordReset::link($token));
    assert_true(!str_contains(PasswordReset::link($token), 'evil.example'));
    unset($_SERVER['HTTP_HOST']);
});

test('Mailen om ny adgangskode indeholder navn, link og udløbstid', function () {
    $body = PasswordReset::resetMail('Anne Holm', 'https://aarshjul.example/reset.php?token=x');
    assert_true(str_starts_with($body, "Hej Anne Holm\n"));
    assert_true(str_contains($body, "\nhttps://aarshjul.example/reset.php?token=x\n"), 'Linket står på sin egen linje');
    assert_true(str_contains($body, PasswordReset::RESET_MINUTES . ' minutter'));
});

test('Invitationen nævner, hvem der inviterer, og hvor længe linket virker', function () {
    $body = PasswordReset::inviteMail('Bo', 'Anne Holm', 'https://aarshjul.example/reset.php?token=x');
    assert_true(str_contains($body, 'Anne Holm har givet dig adgang'));
    assert_true(str_contains($body, PasswordReset::INVITE_DAYS . ' dage'));
});

test('Ugyldige nøgler afvises uden databaseopslag', function () {
    foreach (['', 'abc', str_repeat('g', 64), str_repeat('A', 64), str_repeat('a', 65)] as $bad) {
        assert_same(null, PasswordReset::find($bad), var_export($bad, true));
    }
});
