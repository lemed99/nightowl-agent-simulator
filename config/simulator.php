<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Simulator (demo / test telemetry feeder)
    |--------------------------------------------------------------------------
    |
    | Off by default. Enable only on the demo box (or in the agent's test env)
    | to register `nightowl:simulator-loop` + `nightowl:simulator-backfill`.
    | Merged into the `nightowl.simulator` config key by SimulatorServiceProvider.
    |
    */
    'enabled' => (bool) env('NIGHTOWL_SIMULATOR_ENABLED', false),
];
