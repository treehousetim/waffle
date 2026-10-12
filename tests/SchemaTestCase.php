<?php

declare(strict_types=1);

namespace Tests;

/**
 * Base class for tests that issue DDL.
 *
 * Identical to TestCase except that Pest binds it WITHOUT the transaction
 * hooks. MariaDB commits implicitly on CREATE/ALTER/DROP, which would end the
 * surrounding transaction and make the rollback a no-op — so DDL never appears
 * in a transaction-wrapped test, and anything created here is dropped here.
 */
abstract class SchemaTestCase extends TestCase
{
}
