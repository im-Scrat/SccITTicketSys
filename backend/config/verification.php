<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Verification / Test-Fixture Settings
|--------------------------------------------------------------------------
|
| Settings for the browser (E2E) and accessibility harnesses introduced by
| WP-2.7d. They live in a config file rather than being read with env() at the
| point of use because `config:cache` — which the production entrypoint runs on
| every boot — makes env() return null outside this directory. Larastan enforces
| that rule, and it is enforcing something real: a fixture password that
| silently became null in production would be a null password, not a missing one.
|
| None of this reaches production regardless. `sccit:e2e-fixtures` refuses to
| run under APP_ENV=production, and its guard is covered by
| tests/Feature/Verification/SeedE2eFixturesTest.php.
|
*/

return [

    /*
     * The shared password for the three deterministic fixture accounts
     * (e2e.admin@ / e2e.tech@ / e2e.teacher@ sccit.test).
     *
     * Deliberately known and documented: the browser suite has to sign in as
     * all three roles, and a secret it cannot read would defeat the purpose.
     * It satisfies the production password policy (FR-AUTH-003) so the fixture
     * exercises the same validation a real account does. Override it per
     * environment with E2E_PASSWORD.
     */
    'e2e_password' => (string) env('E2E_PASSWORD', 'E2ePassw0rd!23'),

];
