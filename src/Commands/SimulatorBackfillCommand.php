<?php

namespace NightOwl\Simulator\Commands;

use Illuminate\Console\Command;
use NightOwl\Simulator\NightwatchSimulator;

/**
 * One-shot backdated seed for the Tier-1 demo. Every live simulated row is
 * stamped NOW, so a freshly deployed feed leaves the dashboard's wide default
 * time range empty for hours. This scatters event clusters across the last N
 * hours (random per-cluster offset → jittered, non-monotonic) so the demo is
 * populated from minute one. Run ONCE after the agent starts, before the loop.
 *
 * Only registered when nightowl.simulator.enabled is true.
 */
class SimulatorBackfillCommand extends Command
{
    protected $signature = 'nightowl:simulator-backfill
        {--token= : Agent token (defaults to config nightowl.agent.token / NIGHTOWL_TOKEN)}
        {--host= : Agent TCP host (defaults to config nightowl.agent.host)}
        {--port= : Agent TCP port (defaults to config nightowl.agent.port)}
        {--hours=48 : Span, in hours back from now, to scatter events across}
        {--events=800 : Number of realistic event clusters to generate}';

    protected $description = 'Seed backdated simulated traffic so the Tier-1 demo dashboard is populated immediately.';

    public function handle(): int
    {
        $token = (string) ($this->option('token') ?: config('nightowl.agent.token', ''));

        if ($token === '') {
            $this->error('No agent token. Pass --token or set NIGHTOWL_TOKEN — it must match the running agent.');

            return self::FAILURE;
        }

        $host = (string) ($this->option('host') ?: config('nightowl.agent.host', '127.0.0.1'));
        $port = (int) ($this->option('port') ?: config('nightowl.agent.port', 2407));
        $hours = max(1, (int) $this->option('hours'));
        $events = max(1, (int) $this->option('events'));
        $windowSeconds = $hours * 3600;

        $simulator = new NightwatchSimulator($token, $host, $port);

        $this->info("Backfilling {$events} event clusters across the last {$hours}h → tcp://{$host}:{$port} …");

        $bar = $this->output->createProgressBar($events);
        $bar->start();

        for ($i = 0; $i < $events; $i++) {
            // Random backdate within the window → jittered scatter (no inter-event
            // sleep: this is a bulk fill, not live traffic).
            $simulator->setClockOffset((float) mt_rand(0, $windowSeconds));
            $simulator->realisticTick(false);
            $bar->advance();
        }

        $simulator->setClockOffset(0.0);
        $bar->finish();
        $this->newLine(2);

        $stats = $simulator->getStats();

        if ($stats['failed'] > 0 && $stats['sent'] === 0) {
            $this->error("Backfill could not reach the agent (sent 0, failed {$stats['failed']}). Is nightowl:agent running on {$host}:{$port} with a matching token?");

            return self::FAILURE;
        }

        $this->info("Backfill done: sent {$stats['sent']}, failed {$stats['failed']}, ".number_format($stats['bytes']).' bytes.');

        return self::SUCCESS;
    }
}
