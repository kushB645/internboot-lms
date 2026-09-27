<?php
declare(strict_types=1);

// Backward-compatible bridge: forwards to the single canonical
// bootstrap so M6 shares one DB connection code path with the rest
// of the app. Do not add connection logic here — see src/core/bootstrap.php.
require_once dirname(__DIR__, 4) . '/src/core/bootstrap.php';