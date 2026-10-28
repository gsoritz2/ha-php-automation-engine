<?php
declare(strict_types=1);

/**
 * Minimaler Cron-Parser: 5 Felder (Minute Stunde Tag Monat Wochentag).
 * Unterstuetzt Sternchen, Listen, Bereiche und Schrittweiten (z.B. Sternchen/5).
 * Wochentag: 0/7 = Sonntag.
 */
final class CronExpr
{
    private string $raw;
    /** @var array<int,array<int,bool>> */
    private array $allowed = [];

    public function __construct(string $expr)
    {
        $this->raw = trim($expr);
        $parts = preg_split('/\s+/', $this->raw) ?: [];
        if (count($parts) !== 5) {
            throw new InvalidArgumentException("Ungueltiger Cron-Ausdruck '$expr' (erwartet 5 Felder)");
        }
        $this->allowed['minute'] = $this->parseField($parts[0], 0, 59);
        $this->allowed['hour'] = $this->parseField($parts[1], 0, 23);
        $this->allowed['day'] = $this->parseField($parts[2], 1, 31);
        $this->allowed['month'] = $this->parseField($parts[3], 1, 12);
        // Wochentag: 0 und 7 bedeuten beide Sonntag -> 7 auf 0 abbilden
        $dow = $this->parseField($parts[4], 0, 7);
        if (isset($dow[7])) {
            $dow[0] = true;
            unset($dow[7]);
        }
        $this->allowed['dow'] = $dow;
    }

    /** @return array<int,bool> */
    private function parseField(string $field, int $min, int $max): array
    {
        $out = [];
        foreach (explode(',', $field) as $chunk) {
            $chunk = trim($chunk);
            $step = 1;
            if (str_contains($chunk, '/')) {
                [$chunk, $stepRaw] = explode('/', $chunk, 2);
                $step = max(1, (int) $stepRaw);
            }
            if ($chunk === '*' || $chunk === '') {
                $start = $min;
                $end = $max;
            } elseif (str_contains($chunk, '-')) {
                [$a, $b] = explode('-', $chunk, 2);
                $start = (int) $a;
                $end = (int) $b;
            } else {
                $start = (int) $chunk;
                $end = (int) $chunk;
            }
            if ($start < $min || $end > $max || $start > $end) {
                throw new InvalidArgumentException("Feld '$field' passt nicht in $min-$max");
            }
            for ($i = $start; $i <= $end; $i += $step) {
                $out[$i] = true;
            }
        }
        return $out;
    }

    public function matches(DateTimeImmutable $t): bool
    {
        return isset($this->allowed['minute'][(int) $t->format('i')])
            && isset($this->allowed['hour'][(int) $t->format('G')])
            && isset($this->allowed['day'][(int) $t->format('j')])
            && isset($this->allowed['month'][(int) $t->format('n')])
            && isset($this->allowed['dow'][(int) $t->format('w')]);
    }

    /** Naechster Ausfuehrungszeitpunkt nach $from (max. 366 Tage Suche). */
    public function next(DateTimeImmutable $from): DateTimeImmutable
    {
        $t = $from->setTime((int) $from->format('G'), (int) $from->format('i'), 0)->modify('+1 minute');
        $limit = 366 * 24 * 60;
        for ($i = 0; $i < $limit; $i++) {
            if ($this->matches($t)) {
                return $t;
            }
            $t = $t->modify('+1 minute');
        }
        return $from->modify('+1 day');
    }

    public function raw(): string
    {
        return $this->raw;
    }
}
