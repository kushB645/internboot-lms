# InternBoot Test Suite

This directory contains the automated test suite for the InternBoot platform, using PHPUnit.

## Running the Tests

To run the full test suite, use the composer script:

```bash
php composer.phar test
# OR
./vendor/bin/phpunit
```

## Database Isolation

The test suite is designed to run in complete isolation from the production database to prevent accidental data corruption or schema pollution.

1. **Test Database**: Tests execute against a dedicated database named `internboot_test`.
2. **Environment Overrides**: The `tests/bootstrap.php` script forcefully overrides `APP_ENV` to `testing` and `DB_NAME` to `internboot_test` before any application code is loaded.
3. **Automated Schema Provisioning**: Before the test suite runs, the bootstrap script connects to MySQL, creates the `internboot_test` database if it doesn't exist, drops all existing tables, and freshly applies `schema.sql`. 
4. **Per-Test Cleanup**: Each test class (e.g. `EvaluateAttemptTest`) truncates all relevant tables in its `setUp()` and `tearDown()` methods, ensuring a pristine state for every single test case without side effects.

Do not run tests against your local development database (`internboot_dev`), as the data will be truncated. The bootstrap script specifically checks for the `internboot_test` database name as a safeguard.
