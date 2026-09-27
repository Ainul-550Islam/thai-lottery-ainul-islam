<?php

declare(strict_types=1);

namespace App\Contracts\Lottery;

/**
 * What a result-version row must be able to say about where it came from.
 *
 * WHY AN INTERFACE AND NOT A BASE MODEL (PROMPT 6)
 * ---------------------------------------------------------------------------
 * The National and Weekly lanes store their versions in different tables with
 * different result schemas, so they cannot share a parent Eloquent model
 * without one lane inheriting columns it does not have. What they DO share is
 * the question a public page asks of a version: who said this, when, from
 * where, and what did it hash to.
 *
 * Expressing that as an interface lets AbstractLotterySourceService build one
 * public provenance projection for both lanes, which is what keeps a Weekly
 * badge from ever disagreeing with a National badge about what FIXTURE_ONLY
 * means.
 *
 * EVERY METHOD HERE IS PUBLIC-SAFE BY CONSTRUCTION. There is deliberately no
 * accessor for an endpoint URL, a token, an Authorization header, an importer
 * identity or a raw payload. A class cannot leak through this interface what
 * the interface has no way to express.
 */
interface ProvidesResultProvenance
{
    /** The provider lane that delivered this version: official, internal, fixture, replay. */
    public function provenanceProvider(): string;

    /** The stored App\Enums\GloSourceState backing value. */
    public function provenanceSourceState(): string;

    /** The provider's own reference for this delivery, when it has one. */
    public function provenanceSourceIdentifier(): ?string;

    /** HOST ONLY. Never a URL - a URL can carry a token in its query string. */
    public function provenanceSourceHost(): ?string;

    /** SHA-256 of the payload as delivered. */
    public function provenancePayloadFingerprint(): string;

    /** SHA-256 of the canonical values only. */
    public function provenanceNormalizedFingerprint(): string;

    public function provenanceParserVersion(): string;

    public function provenanceRetrievedAtIso(): ?string;

    public function provenanceImportedAtIso(): ?string;

    public function provenanceVersionNumber(): int;

    /** The version number this one replaced, or null when it replaced nothing. */
    public function provenanceSupersedesVersionNumber(): ?int;
}
