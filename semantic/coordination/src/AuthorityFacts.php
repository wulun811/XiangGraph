<?php

declare(strict_types=1);

namespace XiangGraph\Coord;

use XiangGraph\S1\Canonical;

/**
 * Decision-rights facts from either the frozen simulation plan or a
 * host-signed ledger.
 *
 * The host ledger is deliberately small and experiment-scoped: it is not a
 * production PKI. Its purpose is to make the trust boundary explicit before a
 * real model is connected. Facts are signed by the experiment host, events are
 * stamped with a host-authenticated principal, and every local adoption is
 * re-checked immediately before it is applied.
 */
final class AuthorityFacts implements AuthorityProvider
{
    public const SCHEMA_VERSION = 'xg-authority-facts/v0.1';
    public const KIND_SIGNED = 'SIGNED_CONTRACT';
    public const KIND_DELEGATION = 'DELEGATION';

    /** @var array<string, array<string, mixed>> fact id => fact */
    private array $facts;

    /**
     * @param array<string, array<string, mixed>> $facts
     */
    private function __construct(
        private string $source,
        array $facts,
        private string $issuer = '',
        private string $keyId = ''
    ) {
        $this->facts = $facts;
    }

    public static function fromPlan(Plan $plan): self
    {
        $facts = [];
        $rights = $plan->decisionRights();

        $contracts = $rights['signed_contracts'] ?? [];
        foreach (\is_array($contracts) ? $contracts : [] as $contract) {
            if (!\is_array($contract)) {
                continue;
            }
            $id = (string) ($contract['contract_id'] ?? '');
            if ($id === '') {
                continue;
            }
            $facts[$id] = [
                'fact_id' => $id,
                'kind' => self::KIND_SIGNED,
                'scope' => \is_array($contract['scope'] ?? null) ? array_values($contract['scope']) : [],
                'allowed_keys' => \is_array($contract['allowed_keys'] ?? null) ? array_values($contract['allowed_keys']) : [],
                'signer' => (string) ($contract['signer'] ?? ''),
                'version' => (string) ($contract['version'] ?? ''),
                'status' => (string) ($contract['status'] ?? 'ACTIVE'),
                'source' => self::SOURCE_PLAN,
            ];
        }

        $delegations = $rights['delegations'] ?? [];
        foreach (\is_array($delegations) ? $delegations : [] as $delegation) {
            if (!\is_array($delegation)) {
                continue;
            }
            $id = (string) ($delegation['delegation_id'] ?? '');
            if ($id === '') {
                continue;
            }
            $facts[$id] = [
                'fact_id' => $id,
                'kind' => self::KIND_DELEGATION,
                'scope' => \is_array($delegation['scope'] ?? null) ? array_values($delegation['scope']) : [],
                'allowed_keys' => \is_array($delegation['allowed_keys'] ?? null) ? array_values($delegation['allowed_keys']) : [],
                'delegate' => (string) ($delegation['delegate'] ?? ''),
                'version' => (string) ($delegation['version'] ?? ''),
                'status' => (string) ($delegation['status'] ?? 'ACTIVE'),
                'range' => $delegation['range'] ?? null,
                'source' => self::SOURCE_PLAN,
            ];
        }

        return new self(self::SOURCE_PLAN, $facts);
    }

    /**
     * Load a host-signed fact ledger. The host key is supplied out of band and
     * must never be written into the repository, prompt, or agent workspace.
     *
     * @param array<string, mixed> $ledger
     */
    public static function fromHostLedger(array $ledger, string $hostKey): self
    {
        if (\strlen($hostKey) < 16) {
            throw new \InvalidArgumentException('host authority key must be at least 16 characters');
        }
        if (($ledger['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
            throw new \InvalidArgumentException('host authority ledger schema_version must be ' . self::SCHEMA_VERSION);
        }
        $issuer = $ledger['issuer'] ?? null;
        if (!\is_string($issuer) || $issuer === '') {
            throw new \InvalidArgumentException('host authority ledger needs a non-empty issuer');
        }
        $keyId = $ledger['key_id'] ?? null;
        if (!\is_string($keyId) || $keyId === '') {
            throw new \InvalidArgumentException('host authority ledger needs a non-empty key_id');
        }
        $rawFacts = $ledger['facts'] ?? null;
        if (!\is_array($rawFacts) || $rawFacts === []) {
            throw new \InvalidArgumentException('host authority ledger needs a non-empty facts list');
        }

        $facts = [];
        foreach ($rawFacts as $index => $fact) {
            $label = 'host authority facts[' . $index . ']';
            if (!\is_array($fact)) {
                throw new \InvalidArgumentException($label . ' must be an object');
            }
            $id = $fact['fact_id'] ?? null;
            if (!\is_string($id) || $id === '') {
                throw new \InvalidArgumentException($label . '.fact_id is required');
            }
            if (isset($facts[$id])) {
                throw new \InvalidArgumentException('duplicate host authority fact id: ' . $id);
            }
            $kind = $fact['kind'] ?? null;
            if (!\in_array($kind, [self::KIND_SIGNED, self::KIND_DELEGATION], true)) {
                throw new \InvalidArgumentException($label . '.kind must be ' . self::KIND_SIGNED . ' or ' . self::KIND_DELEGATION);
            }
            self::assertScope($label . '.scope', $fact['scope'] ?? null);
            self::assertStringList($label . '.allowed_keys', $fact['allowed_keys'] ?? null, true);
            $status = $fact['status'] ?? null;
            if (!\in_array($status, ['ACTIVE', 'REVOKED', 'SUPERSEDED'], true)) {
                throw new \InvalidArgumentException($label . '.status must be ACTIVE, REVOKED or SUPERSEDED');
            }
            if (isset($fact['expires_at']) && (!\is_string($fact['expires_at']) || \strtotime($fact['expires_at']) === false)) {
                throw new \InvalidArgumentException($label . '.expires_at must be an ISO-8601 timestamp');
            }
            if ($kind === self::KIND_SIGNED) {
                foreach (['signer', 'version'] as $key) {
                    if (!\is_string($fact[$key] ?? null) || $fact[$key] === '') {
                        throw new \InvalidArgumentException($label . '.' . $key . ' is required for a signed contract');
                    }
                }
                self::assertStringList($label . '.authorized_principals', $fact['authorized_principals'] ?? null, true);
            } else {
                if (!\is_string($fact['delegate'] ?? null) || $fact['delegate'] === '') {
                    throw new \InvalidArgumentException($label . '.delegate is required for a delegation');
                }
                self::assertRange($label . '.range', $fact['range'] ?? null);
            }
            $signature = $fact['signature'] ?? null;
            if (!\is_string($signature) || !preg_match('/^[a-f0-9]{64}$/', $signature)) {
                throw new \InvalidArgumentException($label . '.signature must be a 64-character lowercase hex HMAC');
            }
            $expected = self::signFact($fact, $hostKey, $issuer);
            if (!hash_equals($expected, $signature)) {
                throw new \InvalidArgumentException($label . '.signature does not match the host key');
            }
            $fact['source'] = self::SOURCE_HOST;
            $fact['issuer'] = $issuer;
            $facts[$id] = $fact;
        }

        return new self(self::SOURCE_HOST, $facts, $issuer, $keyId);
    }

    public static function loadHostLedger(string $path, string $hostKey): self
    {
        $data = json_decode((string) file_get_contents($path), true, 512, \JSON_THROW_ON_ERROR);
        if (!\is_array($data)) {
            throw new \InvalidArgumentException('host authority ledger must be a JSON object: ' . $path);
        }

        return self::fromHostLedger($data, $hostKey);
    }

    /**
     * Sign one fact for a host ledger. This helper is used by the experiment
     * host and by tests; it is not exposed to an agent workspace.
     *
     * @param array<string, mixed> $fact
     */
    public static function signFact(array $fact, string $hostKey, string $issuer): string
    {
        unset($fact['signature'], $fact['source'], $fact['issuer']);

        return hash_hmac('sha256', Canonical::encode([
            'issuer' => $issuer,
            'fact' => $fact,
        ]), $hostKey);
    }

    public function source(): string
    {
        return $this->source;
    }

    public function issuer(): string
    {
        return $this->issuer;
    }

    public function keyId(): string
    {
        return $this->keyId;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function facts(): array
    {
        return $this->facts;
    }

    public function resolve(string $ref): ?array
    {
        return $this->facts[$ref] ?? null;
    }

    public function principal(array $event): string
    {
        $principal = $event['principal'] ?? null;
        if (\is_string($principal) && $principal !== '') {
            return $principal;
        }

        if ($this->source === self::SOURCE_PLAN) {
            $actor = $event['actor'] ?? null;

            return \is_string($actor) ? $actor : '';
        }

        return '';
    }

    public function revalidate(Plan $plan, array $fact, array $event, ?int $now = null): array
    {
        $kind = (string) ($fact['kind'] ?? '');
        $status = (string) ($fact['status'] ?? 'ACTIVE');
        if ($status !== 'ACTIVE') {
            return $this->invalid(
                $kind === self::KIND_SIGNED ? 'CONTRACT_NOT_ACTIVE' : 'DELEGATION_NOT_ACTIVE',
                $kind === self::KIND_SIGNED
                    ? 'the registered contract is not active'
                    : 'the registered delegation is not active'
            );
        }

        $expiresAt = $fact['expires_at'] ?? null;
        if (\is_string($expiresAt) && $expiresAt !== '') {
            $expiry = \strtotime($expiresAt);
            $at = $now ?? \time();
            if ($expiry === false) {
                return $this->invalid('AUTHORITY_EXPIRY_INVALID', 'the authority fact has an unreadable expiry');
            }
            if ($expiry <= $at) {
                return $this->invalid('AUTHORITY_EXPIRED', 'the authority fact has expired');
            }
        }

        $originScope = self::originScope($event);
        $scope = self::normalize($fact['scope'] ?? []);
        if ($scope === [] || !State::covers($scope, $originScope)) {
            return $this->invalid(
                $kind === self::KIND_SIGNED ? 'CONTRACT_SCOPE_MISMATCH' : 'DELEGATION_SCOPE_MISMATCH',
                $kind === self::KIND_SIGNED
                    ? 'the signed contract does not cover the original change scope'
                    : 'the delegation does not cover the original change scope'
            );
        }

        if (!self::keyAllowed($fact, $event)) {
            return $this->invalid(
                $kind === self::KIND_SIGNED ? 'CONTRACT_KEY_MISMATCH' : 'DELEGATION_KEY_MISMATCH',
                $kind === self::KIND_SIGNED
                    ? 'the change key is not covered by the signed contract'
                    : 'the change key is outside the delegated keys'
            );
        }

        if ($kind === self::KIND_SIGNED) {
            if (!self::signerTrusted($plan, (string) ($fact['signer'] ?? ''))) {
                return $this->invalid('UNTRUSTED_CONTRACT_SIGNER', 'the registered signer is not a trusted authority');
            }

            if ($this->source === self::SOURCE_HOST) {
                $authorized = array_map('strval', $fact['authorized_principals'] ?? []);
                $principal = $this->principal($event);
                if ($principal === '' || !\in_array($principal, $authorized, true)) {
                    return $this->invalid('PRINCIPAL_NOT_AUTHORIZED', 'the event principal is not authorized to use this signed contract');
                }
            }

            return $this->valid('SIGNED_EXTERNAL_CONTRACT');
        }

        if ($kind === self::KIND_DELEGATION) {
            $delegate = (string) ($fact['delegate'] ?? '');
            $principal = $this->principal($event);
            if ($delegate === '' || $principal !== $delegate) {
                return $this->invalid('DELEGATE_NOT_AUTHORIZED', 'the event actor is not the registered delegate');
            }

            $range = $fact['range'] ?? null;
            $value = $event['change_value'] ?? null;
            if (!\is_array($range) || !\is_numeric($value)) {
                return $this->invalid('DELEGATION_RANGE_MISSING', 'the delegated change has no numeric value or range');
            }
            $min = $range['min'] ?? null;
            $max = $range['max'] ?? null;
            if (!\is_numeric($min) || !\is_numeric($max) || (float) $min > (float) $max) {
                return $this->invalid('DELEGATION_RANGE_INVALID', 'the registered delegation range is invalid');
            }
            if ((float) $value < (float) $min || (float) $value > (float) $max) {
                return $this->invalid('OUT_OF_DELEGATED_RANGE', 'the proposed value is outside the delegated range');
            }

            return $this->valid('DELEGATED_RANGE');
        }

        return $this->invalid('UNKNOWN_AUTHORITY_KIND', 'the authority fact has an unknown kind');
    }

    /**
     * Re-check a local adoption immediately before applying it. Legacy plan
     * simulation keeps its already-classified result; a host ledger is checked
     * again so a revocation between observation and application fails closed.
     *
     * @param array<string, mixed> $rebase
     * @param array<string, mixed> $event
     * @return array{valid: bool, basis: string, reason: string}
     */
    public function revalidateForApply(Plan $plan, array $rebase, array $event, ?int $now = null): array
    {
        if ($this->source !== self::SOURCE_HOST) {
            return $this->valid((string) ($rebase['basis'] ?? 'PLAN_SIMULATION'));
        }

        $ref = (string) ($rebase['authority_ref'] ?? '');
        $fact = $this->resolve($ref);
        if ($fact === null) {
            return $this->invalid('UNKNOWN_AUTHORITY_REF', 'the authority fact disappeared before it could be applied');
        }

        return $this->revalidate($plan, $fact, $event, $now);
    }

    /**
     * @param array<string, mixed> $fact
     * @param array<string, mixed> $event
     */
    private static function keyAllowed(array $fact, array $event): bool
    {
        $allowed = $fact['allowed_keys'] ?? [];
        if (!\is_array($allowed) || $allowed === []) {
            return true;
        }
        $key = $event['change_key'] ?? null;

        return \is_string($key) && $key !== '' && \in_array($key, array_map('strval', $allowed), true);
    }

    private static function signerTrusted(Plan $plan, string $signer): bool
    {
        if ($signer === '') {
            return false;
        }
        $authority = $plan->authority();
        if ($signer === (string) ($authority['human_authorizer'] ?? '')) {
            return true;
        }
        $trusted = $authority['trusted_signers'] ?? [];

        return \is_array($trusted) && \in_array($signer, array_map('strval', $trusted), true);
    }

    /**
     * @param array<string, mixed> $event
     * @return list<string>
     */
    private static function originScope(array $event): array
    {
        return self::normalize($event['origin_scope'] ?? $event['scope'] ?? []);
    }

    /**
     * @return list<string>
     */
    private static function normalize(mixed $scope): array
    {
        if (!\is_array($scope)) {
            return [];
        }
        $scope = array_values(array_unique(array_map('strval', $scope)));
        sort($scope, \SORT_STRING);

        return $scope;
    }

    private static function assertScope(string $label, mixed $scope): void
    {
        if (!\is_array($scope) || $scope === [] || array_filter($scope, static fn (mixed $item): bool => !\is_string($item) || $item === '') !== []) {
            throw new \InvalidArgumentException($label . ' must be a non-empty list of non-empty strings');
        }
    }

    private static function assertStringList(string $label, mixed $value, bool $required): void
    {
        if ($value === null && !$required) {
            return;
        }
        if (!\is_array($value) || ($required && $value === []) || array_filter($value, static fn (mixed $item): bool => !\is_string($item) || $item === '') !== []) {
            throw new \InvalidArgumentException($label . ' must be a ' . ($required ? 'non-empty ' : '') . 'list of non-empty strings');
        }
    }

    private static function assertRange(string $label, mixed $range): void
    {
        if (!\is_array($range) || !\is_numeric($range['min'] ?? null) || !\is_numeric($range['max'] ?? null) || (float) $range['min'] > (float) $range['max']) {
            throw new \InvalidArgumentException($label . ' must have numeric min <= max');
        }
    }

    /**
     * @return array{valid: bool, basis: string, reason: string}
     */
    private function valid(string $basis): array
    {
        return ['valid' => true, 'basis' => $basis, 'reason' => ''];
    }

    /**
     * @return array{valid: bool, basis: string, reason: string}
     */
    private function invalid(string $basis, string $reason): array
    {
        return ['valid' => false, 'basis' => $basis, 'reason' => $reason];
    }
}
