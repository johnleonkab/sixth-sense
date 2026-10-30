<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
 * An OpenUI Lang program Laravel wrote is valid for the canvas library: the
 * real parser reads it with no errors. Skipped where Node is not installed.
 */
expect()->extend('toBeValidOpenUi', function () {
    $node = trim((string) shell_exec('command -v node'));

    if ($node === '') {
        return $this;
    }

    $file = tempnam(sys_get_temp_dir(), 'openui-');
    file_put_contents($file, (string) $this->value);
    exec(escapeshellarg($node).' '.escapeshellarg(base_path('scripts/openui-check.mts')).' '.escapeshellarg($file).' 2>&1', $output, $status);
    unlink($file);

    expect($status)->toBe(0, "OpenUI Lang no válido:\n".implode("\n", $output)."\n\n".$this->value);

    return $this;
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}
