<?php

namespace NightOwl\Simulator\Commands;

use Illuminate\Console\Command;
use NightOwl\Simulator\NightwatchSimulator;

/**
 * Continuous demo feeder (Tier-1). Drives the realistic scenario tick-by-tick
 * against a local agent's TCP ingest so the public demo dashboard stays
 * populated. PINNED to `realistic` — it calls realisticTick() directly and
 * never the no-sleep high-throughput/error-storm paths, so it can't hammer.
 *
 * Run as a supervised service (e.g. a Coolify worker with auto-restart). It
 * handles SIGTERM/SIGINT by finishing the in-flight tick and exiting 0, so a
 * restart is clean. Only registered when nightowl.simulator.enabled is true.
 */
class SimulatorLoopCommand extends Command
{
    protected $signature = 'nightowl:simulator-loop
        {--token= : Agent token (defaults to config nightowl.agent.token / NIGHTOWL_TOKEN)}
        {--host= : Agent TCP host (defaults to config nightowl.agent.host)}
        {--port= : Agent TCP port (defaults to config nightowl.agent.port)}
        {--delay-ms= : Average ms between ticks — sets overall volume (default env NIGHTOWL_SIMULATOR_DELAY_MS, 0 = unthrottled). A diurnal + sub-hourly + noise curve is layered on top so traffic is not flat.}
        {--report-every=500 : Emit a heartbeat line every N ticks}';

    protected $description = 'Continuously feed a local agent realistic simulated traffic (Tier-1 demo feeder).';

    private bool $stop = false;

    public function handle(): int
    {
        $token = (string) ($this->option('token') ?: config('nightowl.agent.token', ''));

        if ($token === '') {
            $this->error('No agent token. Pass --token or set NIGHTOWL_TOKEN — it must match the running agent.');

            return self::FAILURE;
        }

        $host = (string) ($this->option('host') ?: config('nightowl.agent.host', '127.0.0.1'));
        $port = (int) ($this->option('port') ?: config('nightowl.agent.port', 2407));
        $reportEvery = max(1, (int) $this->option('report-every'));
        $baseDelayMs = max(0, (int) ($this->option('delay-ms') ?: getenv('NIGHTOWL_SIMULATOR_DELAY_MS') ?: 0));

        $simulator = new NightwatchSimulator($token, $host, $port);
        $simulator->errorRate = max(0.0, (float) (getenv('NIGHTOWL_SIMULATOR_ERROR_RATE') ?: 0.6));

        $this->installSignalHandlers();

        $this->info("nightowl:simulator-loop → tcp://{$host}:{$port} (realistic, self-paced). SIGTERM/Ctrl-C to stop.");

        $ticks = 0;
        while (! $this->stop) {
            // Realistic scenario only — self-paced 10-100ms jitter per tick.
            $simulator->realisticTick();
            $ticks++;

            if ($ticks % $reportEvery === 0) {
                $stats = $simulator->getStats();
                $this->line("  …{$ticks} ticks · sent {$stats['sent']}, failed {$stats['failed']}");
            }

            if ($baseDelayMs > 0) {
                usleep((int) round($this->shapedDelayMs($baseDelayMs) * 1000));
            }
        }

        $stats = $simulator->getStats();
        $this->info("Stopped after {$ticks} ticks (sent {$stats['sent']}, failed {$stats['failed']}).");

        return self::SUCCESS;
    }

    /**
     * Vary the inter-tick delay so the dashboards show a real app's week, not a flat
     * line or a repeated stamp. Volume follows the shared traffic curve (diurnal +
     * per-day variation + weekend dips) with a little per-tick noise so spacing isn't
     * mechanical. Higher weight = busier = shorter delay; the curve averages ~1 so
     * overall volume still tracks $baseDelayMs.
     */
    private function shapedDelayMs(int $baseDelayMs): float
    {
        $weight = NightwatchSimulator::trafficWeight(microtime(true));
        $noise = 0.80 + mt_rand(0, 400) / 1000.0;            // 0.80..1.20 jitter
        $rate = max(0.12, min(3.2, $weight * $noise));       // higher = busier = shorter delay

        return $baseDelayMs / $rate;
    }

    /**
     * Graceful shutdown: flip the loop flag on SIGTERM/SIGINT so the current
     * tick completes and the process exits 0 instead of being hard-killed.
     */
    private function installSignalHandlers(): void
    {
        if (! function_exists('pcntl_async_signals')) {
            return;
        }

        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, function (): void {
            $this->stop = true;
        });
        pcntl_signal(SIGINT, function (): void {
            $this->stop = true;
        });
    }
}
