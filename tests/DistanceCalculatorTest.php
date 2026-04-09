<?php

declare(strict_types=1);

namespace Pfazzi\DxMetrics\Tests;

use Pfazzi\DxMetrics\DistanceCalculator;
use PHPUnit\Framework\TestCase;

class DistanceCalculatorTest extends TestCase
{
    private DistanceCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new DistanceCalculator();
    }

    public function test_same_file_returns_zero(): void
    {
        self::assertSame(0, $this->calculator->calc('src/Foo/Bar.php', 'src/Foo/Bar.php'));
    }

    public function test_files_in_same_directory_return_zero(): void
    {
        self::assertSame(0, $this->calculator->calc('src/Foo/Bar.php', 'src/Foo/Baz.php'));
    }

    public function test_files_in_sibling_directories_return_two(): void
    {
        // src/Foo → src (1 up) → src/Baz (1 down) = 2
        self::assertSame(2, $this->calculator->calc('src/Foo/Bar.php', 'src/Baz/Qux.php'));
    }

    public function test_file_in_parent_directory_vs_nested_file(): void
    {
        // src/App/X.php → dir ['src', 'App']
        // tests/Unit/XTest.php → dir ['tests', 'Unit']
        // No common prefix → fromA=2 + toB=2 = 4
        self::assertSame(4, $this->calculator->calc('src/App/X.php', 'tests/Unit/XTest.php'));
    }

    public function test_deeply_nested_vs_root_file(): void
    {
        // a/b/c/D.php → a/b/c (0 up from src dir)
        // Root.php is in . (root)
        // distance from a/b/c to . = 3 up, to . = 0 down → 3
        self::assertSame(3, $this->calculator->calc('a/b/c/D.php', 'Root.php'));
    }

    public function test_distance_is_symmetric(): void
    {
        $a = 'src/Foo/Bar.php';
        $b = 'tests/Unit/BarTest.php';

        self::assertSame(
            $this->calculator->calc($a, $b),
            $this->calculator->calc($b, $a),
        );
    }

    public function test_files_with_dot_segments_are_normalized(): void
    {
        // src/./Foo/Bar.php normalizes to src/Foo/Bar.php
        self::assertSame(0, $this->calculator->calc('src/./Foo/Bar.php', 'src/Foo/Baz.php'));
    }

    public function test_files_with_dotdot_segments_are_resolved(): void
    {
        // src/Foo/../Bar/Baz.php normalizes to src/Bar/Baz.php
        self::assertSame(0, $this->calculator->calc('src/Foo/../Bar/Baz.php', 'src/Bar/Qux.php'));
    }

    public function test_last_segment_without_extension_treated_as_directory(): void
    {
        // "src/controllers" has no dot → treated as directory, not stripped
        // Both in same logical dir "src/controllers"
        self::assertSame(0, $this->calculator->calc('src/controllers/Foo.php', 'src/controllers/Bar.php'));
    }

    public function test_windows_style_paths_are_normalized(): void
    {
        // Backslashes normalized to forward slashes
        self::assertSame(0, $this->calculator->calc('src\\Foo\\Bar.php', 'src\\Foo\\Baz.php'));
    }

    public function test_dotdot_beyond_root_is_kept_as_literal(): void
    {
        // Both paths resolve to the same effective location when starting with ../
        self::assertSame(0, $this->calculator->calc('../shared/A.php', '../shared/B.php'));
    }

    public function test_root_level_files_have_zero_distance(): void
    {
        // Files with no directory component are both in the implicit root
        self::assertSame(0, $this->calculator->calc('A.php', 'B.php'));
    }

    public function test_windows_drive_letter_paths_are_normalized(): void
    {
        // C:/src/Foo/Bar.php and C:/src/Foo/Baz.php are in the same directory
        self::assertSame(0, $this->calculator->calc('C:/src/Foo/Bar.php', 'C:/src/Foo/Baz.php'));
    }

    public function test_windows_drive_letter_paths_in_different_directories(): void
    {
        // C:/src/Foo/Bar.php vs C:/src/Baz/Qux.php → 2 hops
        self::assertSame(2, $this->calculator->calc('C:/src/Foo/Bar.php', 'C:/src/Baz/Qux.php'));
    }

    public function test_current_directory_path_treated_as_root(): void
    {
        // '.' normalizes to zero segments, behaves as the root directory
        // Two files relative to '.' are in the same location
        self::assertSame(0, $this->calculator->calc('./A.php', './B.php'));
    }
}
