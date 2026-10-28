<?php
declare(strict_types=1);

/**
 * Log mit Levelfilter, In-Memory-Ringpuffer (fuer die Web-UI) und Dateiausgabe
 * ( landet im Add-on-Log und in /data/engine.log ).
 */
final class Log
{
    private const ORDER = ['trace' => 0, 'debug' => 1, 'info' => 2, 'warning' => 3, 'error' => 4];

    private static string $level = 'info';
    private static string $file = '/data/engine.log';
    private static int $ringSize = 500;
    /** @var string[] */
    private static array $ring = [];
    private static bool $echo = true;

    public static function init(string $level, string $file, int $ringSize = 500, bool $echo = true): void
    {
        self::$level = array_key_exists($level, self::ORDER) ? $level : 'info';
        self::$file = $file;
        self::$ringSize = max(50, $ringSize);
        self::$echo = $echo;
    }

    public static function level(): string
    {
        return self::$level;
    }

    public static function enabled(string $level): bool
    {
        return (self::ORDER[$level] ?? 2) >= (self::ORDER[self::$level] ?? 2);
    }

    public static function add(string $level, string $message): void
    {
        if (!self::enabled($level)) {
            return;
        }
        $message = str_replace(["\r\n", "\n"], ' | ', $message);
        $line = sprintf('%s [%-7s] %s', date('Y-m-d H:i:s'), strtoupper($level), $message);

        self::$ring[] = $line;
        if (count(self::$ring) > self::$ringSize) {
            self::$ring = array_slice(self::$ring, -self::$ringSize);
        }

        @file_put_contents(self::$file, $line . PHP_EOL, FILE_APPEND);
        clearstatcache(true, self::$file);
        if (@filesize(self::$file) > 4 * 1024 * 1024) {
            self::rotate();
        }

        if (self::$echo) {
            fwrite(STDOUT, $line . PHP_EOL);
        }
    }

    private static function rotate(): void
    {
        $keep = max(200, self::$ringSize);
        $lines = @file(self::$file, FILE_IGNORE_NEW_LINES) ?: [];
        $tail = array_slice($lines, -$keep);
        @file_put_contents(self::$file, implode(PHP_EOL, $tail) . PHP_EOL);
    }

    /** @return string[] */
    public static function ring(): array
    {
        return self::$ring;
    }

    /** @return string[] */
    public static function tail(int $lines): array
    {
        return array_slice(self::$ring, -max(1, $lines));
    }
}
