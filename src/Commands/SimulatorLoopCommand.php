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
     * Vary the inter-tick delay around the target average so the dashboards show a
     * natural traffic curve, not a flat line: a diurnal swing (quiet overnight, busy
     * mid-afternoon) × sub-hourly swells + ripples × per-tick noise. The factors
     * average ~1, so overall volume still tracks $baseDelayMs.
     */
    private function shapedDelayMs(int $baseDelayMs): float
    {
        $now = microtime(true);
        $hourFrac = ((int) date('G')) + ((int) date('i')) / 60.0;       // 0..24, local
        $daily = 1.0 + 0.6 * sin(2 * M_PI * ($hourFrac - 8.0) / 24.0);  // peak ~14:00, trough ~02:00
        $wave = 1.0 + 0.30 * sin(2 * M_PI * $now / (37 * 60))           // ~37-min swells
                    + 0.15 * sin(2 * M_PI * $now / (8 * 60));           // ~8-min ripples
        $noise = 0.70 + mt_rand(0, 600) / 1000.0;                       // 0.70..1.30 jitter
        $rate = max(0.18, min(3.5, $daily * $wave * $noise));          // higher = busier = shorter delay
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
