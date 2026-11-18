<?php

declare(strict_types=1);

namespace XiangGraph\Coord;

/**
 * Loads a coordination plan and a scenario, then replays the event stream.
 * This is a CLI/demo boundary, not a product authority.
 */
final class Replay
{
    public static function loadPlan(string $path): Plan
    {
        $data = json_decode((string) file_get_contents($path), true, 512, \JSON_THROW_ON_ERROR);
        if (!\is_array($data)) {
            throw new \InvalidArgumentException('plan must be a JSON object: ' . $path);
        }

        return new Plan($data);
    }

    /**
     * @return array<string, mixed>
     */
    public static function loadScenario(string $path): array
    {
        $data = json_decode((string) file_get_contents($path), true, 512, \JSON_THROW_ON_ERROR);
        if (!\is_array($data)) {
            throw new \InvalidArgumentException('scenario must be a JSON object: ' . $path);
        }
        if (!\is_array($data['events'] ?? null)) {
            throw new \InvalidArgumentException('scenario needs an events list: ' . $path);
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $scenario
     * @return array<string, mixed>
     */
    public function run(Plan $plan, array $scenario, string $runId = 'COORD-R0-REPLAY-20261113-01', ?AuthorityProvider $authority = null): array
    {
        $reducer = new EventReducer(new ProtocolComposer(), new EscalationRouter(), new ProtocolPatchValidator($authority), $authority);

        return $reducer->reduce($plan, array_values($scenario['events']), [
            'run_id' => $runId,
            'scenario_id' => (string) ($scenario['scenario_id'] ?? 'unnamed'),
            'expected_escalations' => $scenario['expected_escalations'] ?? [],
        ]);
    }
}
