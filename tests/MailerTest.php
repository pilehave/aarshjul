<?php
declare(strict_types=1);

// --- Mailer::buildMessage ---

/** @return array{0:array<string,string>,1:string} headere (nøglet på navn) og afkodet krop */
function parse_mail(string $msg): array
{
    [$head, $body] = explode("\r\n\r\n", $msg, 2);
    $headers = [];
    foreach (explode("\r\n", $head) as $line) {
        [$name, $value] = explode(': ', $line, 2);
        $headers[$name] = $value;
    }
    return [$headers, base64_decode($body)];
}

test('Mail med danske tegn i emne, afsendernavn og krop', function () {
    $msg = Mailer::buildMessage('anne@example.com', 'Nulstil din adgangskode til Årshjul', "Hej Anne\nKlik på linket.", 'aarshjul@example.com', 'Årshjul');
    [$h, $body] = parse_mail($msg);
    assert_same('Nulstil din adgangskode til Årshjul', mb_decode_mimeheader($h['Subject']));
    assert_same('Årshjul <aarshjul@example.com>', mb_decode_mimeheader($h['From']));
    assert_same('<anne@example.com>', $h['To']);
    assert_same('text/plain; charset=UTF-8', $h['Content-Type']);
    assert_same("Hej Anne\r\nKlik på linket.", $body);
    assert_true((bool)preg_match('/^<[0-9a-f]{32}@example\.com>$/', $h['Message-ID']), 'Message-ID bruger afsenderens domæne');
});

test('Ingen linje i mailen er over 76 tegn eller starter med punktum', function () {
    $msg = Mailer::buildMessage('a@example.com', 'Emne', str_repeat('æøå ', 200) . "\n.\n", 'b@example.com', '');
    foreach (explode("\r\n", explode("\r\n\r\n", $msg, 2)[1]) as $line) {
        assert_true(strlen($line) <= 76, 'For lang linje: ' . $line);
        assert_true(!str_starts_with($line, '.'), 'Linje starter med punktum');
    }
});

test('Ren ASCII i emnet kodes ikke', function () {
    [$h] = parse_mail(Mailer::buildMessage('a@example.com', 'Test', 'x', 'b@example.com', ''));
    assert_same('Test', $h['Subject']);
    assert_same('<b@example.com>', $h['From']);
});

test('Linjeskift og ugyldige adresser afvises (header injection)', function () {
    $cases = [
        ["a@example.com\r\nBcc: c@example.com", 'Emne', 'b@example.com', ''],
        ['a@example.com', "Emne\r\nBcc: c@example.com", 'b@example.com', ''],
        ['a@example.com', 'Emne', 'b@example.com', "Navn\nBcc: c@example.com"],
        ['ikke-en-adresse', 'Emne', 'b@example.com', ''],
        ['a@example.com', 'Emne', '', ''],
    ];
    foreach ($cases as $i => [$to, $subject, $from, $name]) {
        try {
            Mailer::buildMessage($to, $subject, 'x', $from, $name);
            throw new TestFailure("Tilfælde $i blev ikke afvist");
        } catch (InvalidArgumentException) {
        }
    }
});
