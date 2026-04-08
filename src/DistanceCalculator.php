<?php

declare(strict_types=1);

namespace Pfazzi\DxMetrics;

/** @psalm-suppress UnusedClass */
final class DistanceCalculator
{
    /**
     * Distanza fra due file come numero di salti di directory
     * necessari per andare dalla cartella di A alla cartella di B.
     *
     * Esempi:
     *  - a: "src/Foo/Bar.php", b: "src/Foo/Baz.php"       => 0
     *  - a: "src/Foo/Bar.php", b: "src/Baz/Qux.php"       => 2  (Foo -> src) + (src -> Baz)
     *  - a: "src/App/X.php",   b: "tests/Unit/XTest.php"  => 3  (App->src, src->., .->tests) + (tests->Unit)
     *
     * Nota: non richiede che i path esistano sul filesystem.
     */
    public function calc(string $a, string $b): int
    {
        // Normalizza separatori, risolve . e .., rimuove vuoti
        $aParts = $this->normalizePath($a);
        $bParts = $this->normalizePath($b);

        // Se ultimo segmento sembra un file, rimuovilo (misuriamo distanza tra directory contenitrici)
        $aDir = $this->directorySegments($aParts);
        $bDir = $this->directorySegments($bParts);

        // Gestione caso identico (stessa directory)
        if ($aDir === $bDir) {
            return 0;
        }

        // Trova il prefisso comune
        $i = 0;
        $max = min(\count($aDir), \count($bDir));
        while ($i < $max && $aDir[$i] === $bDir[$i]) {
            ++$i;
        }

        // Distanza = segmenti restanti da A fino al LCA + segmenti dal LCA fino a B
        $fromA = \count($aDir) - $i;
        $toB = \count($bDir) - $i;

        return $fromA + $toB;
    }

    /**
     * Normalizza un path:
     * - converte "\" in "/"
     * - spezza in segmenti
     * - elimina segmenti vuoti e "."
     * - risolve ".." localmente
     * - mantiene eventuale root/drive come primo segmento (trattato come letterale)
     *
     * Non usa realpath() perché i file potrebbero non esistere.
     *
     * @return list<string>
     */
    private function normalizePath(string $path): array
    {
        $path = str_replace('\\', '/', $path);

        // Gestione rudimentale di drive Windows (es. "C:/")
        $drive = null;
        if (1 === preg_match('#^[A-Za-z]:/#', $path)) {
            $drive = substr($path, 0, 2); // "C:"
            $path = substr($path, 2);    // rimuove "C:"
        }

        $parts = explode('/', $path);
        $stack = [];

        foreach ($parts as $p) {
            if ('' === $p || '.' === $p) {
                continue;
            }
            if ('..' === $p) {
                if (!empty($stack) && '..' !== end($stack)) {
                    array_pop($stack);
                } else {
                    // Se non possiamo risalire, manteniamo ".."
                    $stack[] = '..';
                }
                continue;
            }
            $stack[] = $p;
        }

        if (null !== $drive) {
            array_unshift($stack, $drive);
        }

        return $stack;
    }

    /**
     * Rimuove l'ultimo segmento (considerato file) se presente.
     * Se il path finisce con una directory (es. con "/"), normalizePath avrà già
     * rimosso il trailing slash e qui lo tratteremo comunque come file solo se
     * sembra un nome file (heuristica: contiene un ".").
     *
     * @param list<string> $segments
     *
     * @return list<string>
     */
    private function directorySegments(array $segments): array
    {
        if ([] === $segments) {
            return [];
        }

        $last = end($segments);

        // Heuristica: se l'ultimo segmento contiene un punto, assumiamo sia un file
        // (es. "Bar.php"). In caso contrario lo trattiamo come directory.
        if (str_contains($last, '.')) {
            array_pop($segments);
        }

        return $segments;
    }
}
