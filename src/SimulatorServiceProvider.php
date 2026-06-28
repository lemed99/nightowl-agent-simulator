<?php

namespace NightOwl\Simulator;

use Illuminate\Support\ServiceProvider;
use NightOwl\Simulator\Commands\SimulatorBackfillCommand;
use NightOwl\Simulator\Commands\SimulatorLoopCommand;

/**
 * Registers the demo/test telemetry feeder commands. This package is deliberately
 * NOT a runtime dependency of the customer-facing nightowl/agent install — it lives
 * here so the synthetic-data generator never ships to people monitoring real apps.
 * The commands stay gated behind `nightowl.simulator.enabled` (default false), so even
 * when the package IS present (agent CI, the demo box) nothing runs unless asked.
 */
class SimulatorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/simulator.php', 'nightowl.simulator');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole() && config('nightowl.simulator.enabled', false)) {
            $this->commands([
                SimulatorLoopCommand::class,
                SimulatorBackfillCommand::class,
            ]);
        }
    }
}
