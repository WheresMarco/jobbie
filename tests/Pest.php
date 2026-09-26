<?php

declare(strict_types=1);

use Tests\TestCase;

/*
| All tests share the base TestCase, which blocks stray HTTP requests and
| binds fake DNS and TLS clients. Tests that need MySQL (FULLTEXT) belong to
| the "mysql" group and are run separately with `composer test:mysql`.
*/
pest()->extend(TestCase::class)->in('Feature', 'Unit');
