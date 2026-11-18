<?php

declare(strict_types=1);

namespace XiangGraph\Coord;

/**
 * Generic decision-rights classification for contract changes.
 *
 * A contract change is not automatically a Director decision, and it is not
 * automatically a local adoption either. The authority source declares which
 * external contracts were already signed and which bounded delegations exist.
 * An event only carries references and the proposed change; the event's own
 * label, actor string or "decision_basis" claim is never treated as authority.
 *
 * In the frozen simulations the plan is the authority source. In a real C0 run
 * the host supplies a signed fact ledger and stamps each event with an
 * authenticated principal; both XG and B3 consume the same provider.
 */
final class DecisionRights
{
    public const ADOPT_EXTERNAL = 'ADOPT_EXTERNAL';
    public const ADOPT_DELEGATED = 'ADOPT_DELEGATED';
    public const ESCALATE_OPEN = 'ESCALATE_OPEN';
    public const ESCALATE_OUT_OF_RANGE = 'ESCALATE_OUT_OF_RANGE';

    /**
     * @param array<string, mixed> $event
     * @return array{
     *   decision: string,
     *   basis: string,
     *   authority_ref: string|null,
     *   reason: string,
     *   requires_director: bool,
     *   authority_source?: string
     * }
     */
    public static function classify(Plan $plan, array $event, ?AuthorityProvider $authority = null): array
    {
        $authority ??= AuthorityFacts::fromPlan($plan);
        $authorityRef = self::string($event['authority_ref'] ?? null);
        $contractRef = self::string($event['contract_ref'] ?? null);
        $delegationRef = self::string($event['delegation_ref'] ?? null);
        $expectedKind = null;

        if ($authorityRef === '') {
            if ($authority->source() === AuthorityProvider::SOURCE_HOST) {
                return self::decision(
                    self::ESCALATE_OPEN,
                    'NO_HOST_AUTHORITY_REF',
                    null,
                    'the event has no host-issued authority reference',
                    $authority->source()
                );
            }
            if ($contractRef !== '') {
                $authorityRef = $contractRef;
                $expectedKind = AuthorityFacts::KIND_SIGNED;
            } elseif ($delegationRef !== '') {
                $authorityRef = $delegationRef;
                $expectedKind = AuthorityFacts::KIND_DELEGATION;
            } else {
                return self::decision(
                    self::ESCALATE_OPEN,
                    'NO_REGISTERED_DECISION_AUTHORITY',
                    null,
                    'no signed contract or bounded delegation covers this change',
                    $authority->source()
                );
            }
        }

        $fact = $authority->resolve($authorityRef);
        if ($fact === null) {
            $basis = match ($expectedKind) {
                AuthorityFacts::KIND_SIGNED => 'UNKNOWN_CONTRACT_REF',
                AuthorityFacts::KIND_DELEGATION => 'UNKNOWN_DELEGATION_REF',
                default => 'UNKNOWN_AUTHORITY_REF',
            };
            $reason = match ($expectedKind) {
                AuthorityFacts::KIND_SIGNED => 'the event names a contract that is not in the signed-contract registry',
                AuthorityFacts::KIND_DELEGATION => 'the event names a delegation that is not registered',
                default => 'the event names an authority fact that is not registered',
            };

            return self::decision(self::ESCALATE_OPEN, $basis, $authorityRef === '' ? null : $authorityRef, $reason, $authority->source());
        }

        $check = $authority->revalidate($plan, $fact, $event);
        if (!$check['valid']) {
            $decision = $check['basis'] === 'OUT_OF_DELEGATED_RANGE'
                ? self::ESCALATE_OUT_OF_RANGE
                : self::ESCALATE_OPEN;

            return self::decision($decision, $check['basis'], $authorityRef, $check['reason'], $authority->source());
        }

        $kind = (string) ($fact['kind'] ?? '');
        if ($kind === AuthorityFacts::KIND_SIGNED) {
            return self::decision(
                self::ADOPT_EXTERNAL,
                'SIGNED_EXTERNAL_CONTRACT',
                $authorityRef,
                'a registered external authority already decided this contract change',
                $authority->source()
            );
        }
        if ($kind === AuthorityFacts::KIND_DELEGATION) {
            return self::decision(
                self::ADOPT_DELEGATED,
                'DELEGATED_RANGE',
                $authorityRef,
                'the change is inside a registered and bounded delegation',
                $authority->source()
            );
        }

        return self::decision(self::ESCALATE_OPEN, 'UNKNOWN_AUTHORITY_KIND', $authorityRef, 'the authority fact has an unknown kind', $authority->source());
    }

    /**
     * Re-check a local adoption immediately before applying it. This is the
     * application-time check that a revocation or expiry between observation
     * and application cannot silently pass.
     *
     * @param array<string, mixed> $rebase
     * @param array<string, mixed> $event
     * @return array{valid: bool, basis: string, reason: string}
     */
    public static function revalidateForApply(Plan $plan, array $rebase, array $event, ?AuthorityProvider $authority = null): array
    {
        $authority ??= AuthorityFacts::fromPlan($plan);

        return $authority->revalidateForApply($plan, $rebase, $event);
    }

    private static function string(mixed $value): string
    {
        return \is_string($value) ? $value : '';
    }

    /**
     * @return array{
     *   decision: string,
     *   basis: string,
     *   authority_ref: string|null,
     *   reason: string,
     *   requires_director: bool,
     *   authority_source?: string
     * }
     */
    private static function decision(string $decision, string $basis, ?string $authorityRef, string $reason, string $authoritySource): array
    {
        $result = [
            'decision' => $decision,
            'basis' => $basis,
            'authority_ref' => $authorityRef,
            'reason' => $reason,
            'requires_director' => \in_array($decision, [self::ESCALATE_OPEN, self::ESCALATE_OUT_OF_RANGE], true),
        ];
        if ($authoritySource === AuthorityProvider::SOURCE_HOST) {
            $result['authority_source'] = $authoritySource;
        }

        return $result;
    }
}
