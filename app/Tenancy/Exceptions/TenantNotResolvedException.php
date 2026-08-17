<?php

namespace App\Tenancy\Exceptions;

use RuntimeException;

/**
 * Thrown when a tenant-owned model is queried with no workspace bound.
 *
 * This is deliberately loud. The alternative - quietly returning every
 * tenant's rows - is the worst possible failure mode for this application.
 */
class TenantNotResolvedException extends RuntimeException {}
