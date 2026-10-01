<?php

declare(strict_types=1);

namespace App\Http\Requests\Web;

use App\Http\Requests\ContactMessageRequest;

/**
 * Web Contact Request Validator.
 *
 * Enforces email/header injection defenses, honeypot spam prevention,
 * and payload boundary checks.
 */
class ContactRequest extends ContactMessageRequest
{
}
