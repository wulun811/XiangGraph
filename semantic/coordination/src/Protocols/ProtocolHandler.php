<?php

declare(strict_types=1);

namespace XiangGraph\Coord\Protocols;

use XiangGraph\Coord\Plan;
use XiangGraph\Coord\ProtocolEffect;
use XiangGraph\Coord\State;

interface ProtocolHandler
{
    public function type(): string;

    /**
     * @return list<string>
     */
    public function signals(): array;

    /**
     * @param array<string, mixed> $protocol
     * @param array<string, mixed> $event
     */
    public function evaluate(Plan $plan, array $protocol, array $event, State $state): ProtocolEffect;
}
