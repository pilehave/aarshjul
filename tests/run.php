<?php
declare(strict_types=1);

/**
 * Minimal testkører uden afhængigheder. Kør:  php tests/run.php
 * (eller i Docker:  docker compose exec web php tests/run.php)
 *
 * Hver fil tests/*Test.php kalder test('navn', function () { ... }) og bruger assert_same()/assert_true().
 * Testene rører ikke databasen. Exitkoden er 1, hvis en test fejler.
 */

ini_set('session.use_cookies', '0');
ini_set('session.save_path', sys_get_temp_dir());
require __DIR__ . '/../src/bootstrap.php';

final class TestFailure extends Exception
{
}

$GLOBALS['tests'] = [];

function test(string $name, callable $fn): void
{
    $GLOBALS['tests'][] = [$name, $fn];
}

function assert_same(mixed $expected, mixed $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        throw new TestFailure(($msg !== '' ? "$msg\n" : '')
            . '  forventet: ' . var_export($expected, true) . "\n"
            . '  fik:       ' . var_export($actual, true));
    }
}

function assert_true(bool $cond, string $msg = 'Betingelsen er ikke opfyldt'): void
{
    if (!$cond) {
        throw new TestFailure($msg);
    }
}

/** Sætter startmåneden uden database (Settings læser ellers tabellen settings). */
function set_start_month(int $month): void
{
    $p = new ReflectionProperty(Settings::class, 'cache');
    $p->setAccessible(true);
    $p->setValue(null, ['start_month' => (string)$month]);
}

/** Sætter en indstilling uden database, ud over startmåneden (nulstilles før hver test). */
function set_setting(string $name, string $value): void
{
    $p = new ReflectionProperty(Settings::class, 'cache');
    $p->setAccessible(true);
    $p->setValue(null, [$name => $value] + ($p->getValue() ?? []));
}

/** En begivenhed som fra Events::all() med standardværdier, der kan overskrives. */
function ev(array $overrides = []): array
{
    return $overrides + [
        'id'            => 1,
        'start_date'    => '2026-01-01',
        'end_date'      => null,
        'duration_days' => 1,
        'recurrence'    => 'none',
        'rec_interval'  => 1,
        'rule_nth'      => null,
        'rule_weekday'  => null,
        'rule_month'    => null,
        'depends_on'    => [],
    ];
}

foreach (glob(__DIR__ . '/*Test.php') as $file) {
    require $file;
}

$failed = 0;
foreach ($GLOBALS['tests'] as [$name, $fn]) {
    set_start_month(1);
    try {
        $fn();
        echo "  ok    $name\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  FEJL  $name\n";
        echo ($e instanceof TestFailure ? $e->getMessage() : get_class($e) . ': ' . $e->getMessage()
            . ' (' . $e->getFile() . ':' . $e->getLine() . ')') . "\n";
    }
}

$total = count($GLOBALS['tests']);
echo "\n" . ($total - $failed) . " af $total test bestået.\n";
exit($failed > 0 ? 1 : 0);
