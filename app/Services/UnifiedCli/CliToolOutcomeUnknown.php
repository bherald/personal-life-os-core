<?php

namespace App\Services\UnifiedCli;

use RuntimeException;

/** Leave the durable action in started state; automatic replay is unsafe. */
final class CliToolOutcomeUnknown extends RuntimeException {}
