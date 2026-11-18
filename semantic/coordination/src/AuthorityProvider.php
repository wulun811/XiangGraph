<?php

declare(strict_types=1);

namespace XiangGraph\Coord;

/**
 * A host-owned source of decision-rights facts.
 *
 * In simulation the plan itself is the fact source. In a real C0 run the
 * experiment host loads a signed fact ledger and stamps each event with the
 * authenticated principal; an agent's own `actor`, `decision_basis` or
 * `contract_ref` string is never enough to obtain authority.
 */
interface AuthorityProvider
{
    public const SOURCE_PLAN = 'PLAN_SIMULATION';
    public const SOURCE_HOST = 'HOST_LEDGER';

    public function source(): string;

    /**
     * @return array<string, mixed>|null
     */
    public function resolve(string $ref): ?array;

    /**
     * Re-check a fact at the moment it is about to be applied.
     *
     * @param array<string, mixed> $fact
     * @param array<string, mixed> $event
     * @return array{valid: bool, basis: string, reason: string}
     */
    public function revalidate(Plan $plan, array $fact, array $event, ?int $now = null): array;

    /**
     * @param array<string, mixed> $rebase
     * @param array<string, mixed> $event
     * @return array{valid: bool, basis: string, reason: string}
     */
    public function revalidateForApply(Plan $plan, array $rebase, array $event, ?int $now = null): array;

    /**
     * The host-authenticated principal for this event. In host mode a missing
     * principal is never replaced by the event's self-declared actor.
     *
     * @param array<string, mixed> $event
     */
    public function principal(array $event): string;
}
