<?php

declare(strict_types=1);

namespace Korin\Gedcom\Benchmarks;

/**
 * Deterministic, fictional GEDCOM 5.5.1 files for benchmarks: families of two people
 * with names, events, places, notes and a vendor tag, Cyrillic and Latin mixed.
 */
final class BenchFile
{
    private const GIVEN = ['Олена', 'Іван', 'Марія', 'Петро', 'Ганна', 'Степан', 'Оксана', 'Василь', 'Катерина', 'Микола', 'Anna', 'Jan', 'Zofia'];
    private const SURNAMES = ['Коваль', 'Гнатюк', 'Мельник', 'Шевчук', 'Бондар', 'Ткачук', 'Кравець', 'Savchenko', 'Nowak', 'Lysenko'];
    private const PLACES = ['Вишнівка, Тернопільська обл., Україна', 'Збараж, Тернопільська обл., Україна', 'Львів, Україна', 'Kraków, Polska'];
    private const MONTHS = ['JAN', 'FEB', 'MAR', 'APR', 'MAY', 'JUN', 'JUL', 'AUG', 'SEP', 'OCT', 'NOV', 'DEC'];

    /** Path of a cached file of about $megabytes in build/bench, generated on first use. */
    public static function path(int $megabytes, string $charset = 'UTF-8'): string
    {
        $path = dirname(__DIR__) . "/build/bench/{$megabytes}mb-{$charset}.ged";
        if (!is_file($path)) {
            @mkdir(dirname($path), 0o777, true);
            self::write($megabytes * 1024 * 1024, $path, $charset);
        }

        return $path;
    }

    /** @return int bytes written */
    public static function write(int $target, string $path, string $charset = 'UTF-8'): int
    {
        if (!in_array($charset, ['UTF-8', 'Windows-1251'], true)) {
            throw new \InvalidArgumentException('Charset must be UTF-8 or Windows-1251');
        }
        $out = fopen($path, 'wb');
        if ($out === false) {
            throw new \RuntimeException("Cannot write {$path}");
        }
        $written = 0;
        $emit = static function (string $lines) use ($out, $charset, &$written): void {
            $bytes = $charset === 'UTF-8' ? $lines : (string) iconv('UTF-8', 'CP1251', $lines);
            fwrite($out, $bytes);
            $written += strlen($bytes);
        };

        $emit("0 HEAD\n1 SOUR KORIN_BENCH\n1 GEDC\n2 VERS 5.5.1\n2 FORM LINEAGE-LINKED\n1 CHAR {$charset}\n");
        mt_srand(20261115);
        for ($f = 1; $written < $target; ++$f) {
            $husband = 2 * $f - 1;
            $wife = 2 * $f;
            foreach ([$husband => 'M', $wife => 'F'] as $i => $sex) {
                $year = 1800 + mt_rand(0, 180);
                $surname = self::SURNAMES[mt_rand(0, count(self::SURNAMES) - 1)];
                $name = self::GIVEN[mt_rand(0, count(self::GIVEN) - 1)];
                $lines = "0 @I{$i}@ INDI\n1 NAME {$name} /{$surname}/\n2 GIVN {$name}\n2 SURN {$surname}\n1 SEX {$sex}\n"
                    . "1 BIRT\n2 DATE " . mt_rand(1, 28) . ' ' . self::MONTHS[mt_rand(0, 11)] . " {$year}\n"
                    . '2 PLAC ' . self::PLACES[mt_rand(0, count(self::PLACES) - 1)] . "\n"
                    . "1 DEAT\n2 DATE ABT " . ($year + mt_rand(30, 90)) . "\n"
                    . "1 FAMS @F{$f}@\n";
                if (mt_rand(0, 3) === 0) {
                    $lines .= "1 NOTE Записано зі слів родичів; дати уточнено за метричними книгами парафії.\n2 CONT Деякі роки приблизні.\n";
                }
                $lines .= '1 _UID ' . strtoupper(bin2hex(pack('N4', $i, $f, $year, 0x4B4F52))) . "\n";
                $emit($lines);
            }
            $emit("0 @F{$f}@ FAM\n1 HUSB @I{$husband}@\n1 WIFE @I{$wife}@\n1 MARR\n2 DATE " . (1820 + mt_rand(0, 170)) . "\n");
        }
        $emit("0 TRLR\n");
        fclose($out);

        return $written;
    }
}
