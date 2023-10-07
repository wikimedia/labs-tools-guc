<?php

namespace Guc;

use Exception;

/**
 * When this kind of exception is caught, it will be displayed without
 * any stack trace, as it is expected to be a problem that doesn't
 * require a code investigation or code change to resolve.
 *
 * Examples: user input mistakes, and environment issues
 * that we anticipate may happen or otherwise are self-explanatory.
 */
class ExpectedError extends Exception {
}
