<?php

declare(strict_types=1);

namespace App\Lottery\Schema;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use RuntimeException;

/**
 * Builds a LaneSchema from a lane's config file (PROMPT 9).
 *
 * ONE SOURCE OF TRUTH. The width, the validation pattern, the public label and
 * the search type of a field are all read from config('<lane>.fields') and
 * config('<lane>.search.type_map'). Nothing here invents a default width or a
 * default pattern: a field the config does not describe does not exist, which
 * is the behaviour that makes a typo in a config key fail loudly instead of
 * silently creating an unvalidated column.
 *
 * THE TWO CONFIG BLOCKS MUST AGREE. If the search map says a type is six
 * characters wide and the field block says the column is four, one of them is
 * wrong and a search would either never match or match the wrong column. The
 * factory refuses to build such a schema rather than picking a winner.
 *
 * Schemas are memoised per lane. They are immutable and derived purely from
 * config, so rebuilding one per query would be wasted work; config()->set() in
 * a test still takes effect because the cache is keyed on the lane and the
 * factory is resolved fresh per container instance.
 */
final class LaneSchemaFactory
{
    /** @var array<string, LaneSchema> */
    private array $cache = [];

    public function __construct(private readonly ConfigRepository $config) {}

    public function for(string $laneKey): LaneSchema
    {
        return $this->cache[$laneKey] ??= $this->build($laneKey);
    }

    /**
     * Drop a memoised schema. Used by tests that rewrite a lane's config.
     */
    public function forget(?string $laneKey = null): void
    {
        if ($laneKey === null) {
            $this->cache = [];

            return;
        }

        unset($this->cache[$laneKey]);
    }

    private function build(string $laneKey): LaneSchema
    {
        $fields = $this->config->get($laneKey.'.fields');

        if (! is_array($fields) || $fields === []) {
            throw new RuntimeException("Lane [{$laneKey}] declares no result fields.");
        }

        $typeMap = $this->config->get($laneKey.'.search.type_map');
        $typeMap = is_array($typeMap) ? $typeMap : [];

        // column => search type, inverted from the config's type => column map.
        $searchTypes = [];

        foreach ($typeMap as $type => $meta) {
            if (! is_string($type) || ! is_array($meta) || ! isset($meta['column']) || ! is_string($meta['column'])) {
                continue;
            }

            $searchTypes[$meta['column']] = ['type' => $type, 'length' => $meta['length'] ?? null];
        }

        $built = [];

        foreach ($fields as $column => $definition) {
            if (! is_string($column) || ! is_array($definition)) {
                continue;
            }

            $width = $definition['length'] ?? null;
            $pattern = $definition['pattern'] ?? null;
            $labelKey = $definition['label_key'] ?? null;

            if (! is_int($width) || $width < 1) {
                throw new RuntimeException("Lane [{$laneKey}] field [{$column}] has no usable width.");
            }

            if (! is_string($pattern) || $pattern === '') {
                throw new RuntimeException("Lane [{$laneKey}] field [{$column}] has no validation pattern.");
            }

            $searchType = null;

            if (isset($searchTypes[$column])) {
                $mapped = $searchTypes[$column];

                if (is_int($mapped['length']) && $mapped['length'] !== $width) {
                    throw new RuntimeException(
                        "Lane [{$laneKey}] field [{$column}] is {$width} wide but its search type [{$mapped['type']}] expects {$mapped['length']}."
                    );
                }

                $searchType = $mapped['type'];
            }

            $built[] = new ResultFieldSchema(
                column: $column,
                width: $width,
                pattern: $pattern,
                searchType: $searchType,
                labelKey: is_string($labelKey) && $labelKey !== '' ? $labelKey : $laneKey.'.field_'.$column,
            );
        }

        if ($built === []) {
            throw new RuntimeException("Lane [{$laneKey}] declares no usable result fields.");
        }

        $canonical = $this->config->get($laneKey.'.canonical_version');

        if (! is_string($canonical) || $canonical === '') {
            // Weekly keeps its label under the integrity block it actually uses.
            $canonical = $this->config->get($laneKey.'.integrity.canonical_version');
        }

        if (! is_string($canonical) || $canonical === '') {
            throw new RuntimeException("Lane [{$laneKey}] declares no canonical_version.");
        }

        return new LaneSchema(
            laneKey: $laneKey,
            canonicalVersion: $canonical,
            fields: $built,
            hasDrawTimes: (bool) $this->config->get($laneKey.'.draw_times.enabled', false),
            // Defaults to true, which is the behaviour Weekly, Mega and
            // National already relied on. A lane only opts out when a partly
            // published draw is a REAL state rather than a broken payload.
            requiresCompleteResultSet: (bool) $this->config->get(
                $laneKey.'.publication.require_complete_result_set',
                true,
            ),
        );
    }
}
