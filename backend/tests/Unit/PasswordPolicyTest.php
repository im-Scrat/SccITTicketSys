<?php

declare(strict_types=1);

use App\Domains\Identity\Rules\PasswordPolicy;
use Illuminate\Support\Facades\Validator;

function failsPolicy(string $password): bool
{
    return Validator::make(
        ['password' => $password],
        ['password' => [new PasswordPolicy]],
    )->fails();
}

it('accepts a strong password', function () {
    expect(failsPolicy('Str0ng-Passw0rd!'))->toBeFalse();
    expect(failsPolicy('Corr3ctHorse'))->toBeFalse(); // upper+lower+digit = 3 classes, len 12
});

it('rejects passwords shorter than the minimum length', function () {
    expect(failsPolicy('Ab1!x'))->toBeTrue();
});

it('rejects passwords with fewer than three character classes', function () {
    expect(failsPolicy('alllowercaseletters'))->toBeTrue(); // 1 class
    expect(failsPolicy('lowercaseandUPPERCASE'))->toBeTrue(); // 2 classes
});

it('rejects common passwords', function () {
    expect(failsPolicy('Password123'))->toBeTrue();
    expect(failsPolicy('Welcome123'))->toBeTrue();
});
