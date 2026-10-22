<?php

declare(strict_types=1);

namespace App\Contracts\Wallet;

use App\DTOs\Wallet\ConversionPolicyDecisionData;
use App\DTOs\Wallet\ConversionRequestData;

/**
 * Decides whether a conversion request may proceed, before any money moves.
 *
 * WHY A DEDICATED POLICY OBJECT
 * The gates a conversion must pass are not one condition: the feature must be
 * on, the direction must be on, the amount must be inside its envelope, the
 * rolling windows must have head-room, the source wallet must hold the funds,
 * the member must be verified and in good standing, and no self-exclusion may
 * be active. Spreading those checks through a service makes it impossible to
 * answer the only question an auditor asks — "show me every reason this
 * platform refuses a conversion" — without reading the whole service.
 *
 * This contract answers that question in one object, and every refusal it
 * returns names the exact reason, in a fixed evaluation order, so the API, the
 * UI and the audit trail explain a denial identically.
 *
 * IMPLEMENTATION CONTRACT
 *  - evaluate() performs no writes and takes no wallet lock; it may read.
 *  - It performs no money arithmetic beyond exact decimal comparison.
 *  - It is safe to call twice for the same request; the result must be
 *    deterministic for an unchanged world state.
 */
interface ConversionPolicyContract
{
    /**
     * Evaluate every gate and return the verdict with its individual checks.
     */
    public function evaluate(ConversionRequestData $request): ConversionPolicyDecisionData;

    /**
     * The same evaluation, but it throws instead of returning a denial.
     *
     * The thrown exception is the typed subclass matching the primary reason,
     * so a caller that wants "just do it or tell me why" does not have to
     * translate a decision object itself.
     *
     * @throws \App\Exceptions\Wallet\ConversionException
     */
    public function assertAllowed(ConversionRequestData $request): void;
}
