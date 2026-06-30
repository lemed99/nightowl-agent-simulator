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
        $simulator->errorRate = max(0.0, (float) (getenv('NIGHTOWL_SIMULATOR_ERROR_RATE') ?: 0.6));

        $this->info("Backfilling {$events} event clusters across the last {$hours}h → tcp://{$host}:{$port} …");

        $bar = $this->output->createProgressBar($events);
        $bar->start();

        for ($i = 0; $i < $events; $i++) {
            // Diurnal-weighted backdate (no inter-event sleep — bulk fill): events
            // cluster toward busy hours so the backfilled chart shows a natural daily
            // curve, matching the live loop's shape instead of a flat scatter.
            $simulator->setClockOffset($this->shapedOffset($windowSeconds));
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

    /**
     * Rejection-sample a backdated offset weighted by the shared traffic curve, so the
     * backfilled week carries the same per-day variation + weekend dips as the live
     * feed (not a flat scatter and not identical daily humps). $wMax bounds the curve's
     * peak, so the accept probability is weight / wMax.
     */
    private function shapedOffset(int $windowSeconds): float
    {
        $now = microtime(true);
        $wMax = 3.0;
        for ($try = 0; $try < 32; $try++) {
            $off = (float) mt_rand(0, $windowSeconds);
            if (mt_rand(0, 1000) / 1000.0 <= NightwatchSimulator::trafficWeight($now - $off) / $wMax) {
                return $off;
            }
        }

        return (float) mt_rand(0, $windowSeconds);
    }
}
