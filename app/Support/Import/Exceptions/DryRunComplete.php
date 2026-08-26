<?php

namespace App\Support\Import\Exceptions;

use RuntimeException;

/** Thrown to roll back a preview once it has proved the rows would save. */
final class DryRunComplete extends RuntimeException {}
