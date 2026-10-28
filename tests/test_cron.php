<?php
declare(strict_types=1);

/** Unit-Tests fuer CronExpr und die Dauerkonvertierung. Aufruf: php tests/test_cron.php */

require __DIR__ . '/../php_automation_engine/engine/lib/log.php';
require __DIR__ . '/../php_automation_engine/engine/lib/ha.php';
require __DIR__ . '/../php_automation_engine/engine/lib/cron.php';
require __DIR__ . '/../php_automation_engine/engine/lib/api.php';

$fail = 0;
$pass = 0;

function t(string $name, bool $ok, string $detail = ''): void
{
    global $fail, $pass;
    if ($ok) {
        $pass++;
        echo "  PASS  $name\n";
    } else {
        $fail++;
        echo "  FAIL  $name" . ($detail !== '' ? "   [$detail]" : '') . "\n";
    }
}

date_default_timezone_set('Europe/Vienna');

// --- naechste Minute bei "* * * * *"
$now = new DateTimeImmutable('2026-10-28 17:20:30');
$next = (new CronExpr('* * * * *'))->next($now);
t('* * * * * -> naechste Minute', $next->format('Y-m-d H:i') === '2026-10-28 17:21', $next->format('c'));

// --- 07:00 werktags
$next = (new CronExpr('0 7 * * 1-5'))->next($now);
t('0 7 * * 1-5 -> 29.10. 07:00 (Do)', $next->format('Y-m-d H:i') === '2026-10-29 07:00', $next->format('c'));
t('0 7 * * 1-5 -> Wochentag Mo-Fr', (int) $next->format('N') <= 5, $next->format('N'));

// --- Schrittweite */15
$c = new CronExpr('*/15 * * * *');
$minutes = [];
$t0 = new DateTimeImmutable('2026-10-28 17:00:00');
for ($i = 0; $i < 6; $i++) {
    $t0 = $c->next($t0);
    $minutes[] = $t0->format('H:i');
}
t('*/15 * * * * -> 17:15,17:30,17:45,18:00', implode(',', $minutes) === '17:15,17:30,17:45,18:00,18:15,18:30', implode(',', $minutes));

// --- Monatserster
$next = (new CronExpr('0 0 1 * *'))->next($now);
t('0 0 1 * * -> 01.11.2026 00:00', $next->format('Y-m-d H:i') === '2026-11-01 00:00', $next->format('c'));

// --- Sonntag (7 = Sonntag)
$next = (new CronExpr('30 6 * * 7'))->next($now);
t('30 6 * * 7 -> Sonntag', $next->format('w') === '0' && $next->format('H:i') === '06:30', $next->format('c'));

// --- Wochentag 1-7 (darf nicht als ungueltiger Bereich gelten)
try {
    (new CronExpr('0 8 * * 1-7'));
    t('0 8 * * 1-7 wird akzeptiert', true);
} catch (Throwable $e) {
    t('0 8 * * 1-7 wird akzeptiert', false, $e->getMessage());
}

// --- Liste
$c = new CronExpr('0 6,12,18 * * *');
t('Liste 6,12,18 -> 12:00 als naechster nach 08:00', $c->next(new DateTimeImmutable('2026-10-28 08:00:00'))->format('H:i') === '12:00');

// --- ungueltige Ausdruecke
foreach (['* * * *', '0 7 * * 1-9', '70 * * * *'] as $bad) {
    try {
        new CronExpr($bad);
        t("ungueltig '$bad' wird abgelehnt", false);
    } catch (InvalidArgumentException $e) {
        t("ungueltig '$bad' wird abgelehnt", true);
    }
}

// --- parseDuration
t("parseDuration('30') = 30", Engine::parseDuration('30') === 30.0);
t("parseDuration('30s') = 30", Engine::parseDuration('30s') === 30.0);
t("parseDuration('5m') = 300", Engine::parseDuration('5m') === 300.0);
t("parseDuration('2h') = 7200", Engine::parseDuration('2h') === 7200.0);
t("parseDuration('1d') = 86400", Engine::parseDuration('1d') === 86400.0);
try {
    Engine::parseDuration('irgendwas');
    t("parseDuration('irgendwas') wird abgelehnt", false);
} catch (InvalidArgumentException $e) {
    t("parseDuration('irgendwas') wird abgelehnt", true);
}

// --- Bedingungsauswertung
t("cond 'above:30' bei 35", Engine::condMatches('above:30', '35', '20', 'x') === true);
t("cond 'above:30' bei 20", Engine::condMatches('above:30', '20', '35', 'x') === false);
t("cond '>30' bei 31", Engine::condMatches('>30', '31', '0', 'x') === true);
t("cond 'eq:on'", Engine::condMatches('eq:on', 'on', 'off', 'x') === true);
t("cond 'from:off'", Engine::condMatches('from:off', 'on', 'off', 'x') === true);
t("cond 'in:a,b'", Engine::condMatches('in:a,b', 'b', 'a', 'x') === true);
t("cond 'attr:brightness=255'", Engine::condMatches('attr:brightness=255', 'on', 'off', 'x', ['brightness' => 255]) === true);
t("cond null = changed", Engine::condMatches(null, 'a', 'b', 'x') === true);
t("cond Array ['to'=>'on']", Engine::condMatches(['to' => 'on'], 'on', 'off', 'x') === true);
t("cond Callable", Engine::condMatches(static fn(string $n): bool => $n === 'ja', 'ja', 'nein', 'x') === true);

echo "\n$pass bestanden, $fail fehlgeschlagen\n";
exit($fail === 0 ? 0 : 1);
