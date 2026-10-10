<?php
namespace App\Exceptions;

use RuntimeException;

/** Thrown when a business rule is violated (insufficient stock, invalid status transition...). Rendered as HTTP 422. */
class BusinessRuleException extends RuntimeException {}
