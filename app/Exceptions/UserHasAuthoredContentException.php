<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when hard-deleting a user who has authored content or holds a
 * membership (SPEC Module 1, rule 7). Deactivate instead.
 */
class UserHasAuthoredContentException extends RuntimeException {}
