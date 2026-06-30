<?php

namespace NightOwl\Simulator;

/**
 * Simulates a laravel/nightwatch collector sending telemetry over TCP.
 *
 * Record bodies are loaded from captured fixtures under src/Simulator/fixtures/
 * (one JSONL file per Nightwatch record type). The simulator picks a random
 * fixture row, refreshes mutable fields (trace_id/timestamp), then merges any
 * caller overrides. This keeps the wire-shape locked to what the real
 * laravel/nightwatch SDK emits — the fixtures are the source of truth.
 *
 * Wire format: [length]:[version]:[tokenHash]:[payload]
 *
 * Usage:
 *   php tests/Simulator/run.php --token=your-token --host=127.0.0.1 --port=2407
 *   php tests/Simulator/run.php --token=your-token --requests=50 --burst
 *   php tests/Simulator/run.php --token=your-token --scenario=error-storm
 */
final class NightwatchSimulator
{
    private string $tokenHash;

    private string $host;

    private int $port;

    private float $timeout;

    /** @var array<string, int> */
    private array $stats = ['sent' => 0, 'failed' => 0, 'bytes' => 0];

    /** @var array<string, array<int, array<string, mixed>>> Fixture cache keyed by record type */
    private array $fixtures = [];

    /**
     * Seconds to subtract from the wall clock when stamping records. 0 = live.
     * The backfill command sets a random positive offset per event so synthetic
     * telemetry scatters across a past window — otherwise a freshly deployed
     * feed leaves the dashboard's wide default time range empty for hours.
     */
    private float $clockOffsetSeconds = 0.0;

    /**
     * Percent (0..100) of realistic-tick events that carry an exception — a failing
     * request or a failed job. Tunable per feeder (NIGHTOWL_SIMULATOR_ERROR_RATE) so
     * the demo's apps read as healthy / degraded / on-fire instead of all on fire.
     * ~0.6 ≈ a healthy app with the occasional error.
     */
    public float $errorRate = 0.6;

    public function __construct(
        string $token,
        string $host = '127.0.0.1',
        int $port = 2407,
        float $timeout = 5.0,
    ) {
        $this->tokenHash = substr(hash('xxh128', $token), 0, 7);
        $this->host = $host;
        $this->port = $port;
        $this->timeout = $timeout;
    }

    // ─── Clock ─────────────────────────────────────────────────

    /**
     * The simulator's notion of "now" in epoch seconds, honoring any backfill
     * offset. Every record timestamp flows through this.
     */
    public function now(): float
    {
        return microtime(true) - $this->clockOffsetSeconds;
    }

    /**
     * Backdate subsequently-built records by $seconds (0 = live). Used by the
     * backfill command; reset to 0 before live looping.
     */
    public function setClockOffset(float $seconds): void
    {
        $this->clockOffsetSeconds = max(0.0, $seconds);
    }

    // ─── Traffic shape ─────────────────────────────────────────

    /**
     * Deterministic per-calendar-day pseudo-random in [0,1) — stable for a given
     * (day, seed), so the live loop and the backfill agree on a day's character.
     */
    private static function dayRand(int $dayIndex, float $seed): float
    {
        $v = sin($dayIndex * 12.9898 + $seed * 78.233) * 43758.5453;

        return $v - floor($v);
    }

    /**
     * A traffic-intensity multiplier (~0.1 .. ~2.9, averaging ~1) for an epoch time.
     * Layers a daily curve with DETERMINISTIC per-day variation — each calendar day
     * gets its own overall level, peak hour, and amplitude — plus weekend dips and a
     * sub-hourly swell. Shared by the live loop (delay = base / weight) and the
     * backfill (rejection sampling) so synthetic traffic reads like a real app's week
     * (non-identical day to day, quieter weekends) instead of a repeated sine stamp.
     */
    public static function trafficWeight(float $t): float
    {
        $ti = (int) $t;
        $dayIndex = (int) floor($t / 86400.0);
        $dow = (int) date('N', $ti);                                    // 1=Mon .. 7=Sun
        $hourFrac = ((int) date('G', $ti)) + ((int) date('i', $ti)) / 60.0;

        $dayLevel = 0.60 + 0.85 * self::dayRand($dayIndex, 1.7);        // overall height 0.60..1.45
        $peakHour = 13.5 + 5.0 * (self::dayRand($dayIndex, 5.3) - 0.5); // peak hour 11.0..16.0
        $amp = 0.45 + 0.30 * self::dayRand($dayIndex, 9.1);             // diurnal depth 0.45..0.75

        $daily = 1.0 + $amp * sin(2 * M_PI * ($hourFrac - $peakHour + 6.0) / 24.0);
        $weekend = $dow >= 6 ? 0.62 : 1.0;                              // quieter Sat/Sun
        $swell = 1.0 + 0.16 * sin(2 * M_PI * $t / (43.0 * 60.0));       // ~43-min swell

        return max(0.10, $dayLevel * $weekend * $daily * $swell);
    }

    // ─── Sending ───────────────────────────────────────────────

    /**
     * Send a batch of records over TCP.
     *
     * @param  array  $records  Array of record arrays (each must have 't' key)
     * @return string|null Server response ("2:OK", "5:ERROR") or null on failure
     */
    public function send(array $records): ?string
    {
        $json = json_encode($records, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);

        return $this->sendRaw($json);
    }

    /**
     * Send a raw JSON payload string over TCP.
     */
    public function sendRaw(string $payload): ?string
    {
        $body = "v1:{$this->tokenHash}:{$payload}";
        $wire = strlen($body).':'.$body;

        $socket = @stream_socket_client(
            "tcp://{$this->host}:{$this->port}",
            $errno,
            $errstr,
            $this->timeout,
        );

        if (! $socket) {
            $this->stats['failed']++;
            fwrite(STDERR, "Connection failed: [{$errno}] {$errstr}\n");

            return null;
        }

        stream_set_timeout($socket, (int) $this->timeout);

        fwrite($socket, $wire);
        $this->stats['bytes'] += strlen($wire);

        $response = fread($socket, 128);
        fclose($socket);

        if ($response !== false && str_starts_with($response, '2:')) {
            $this->stats['sent']++;
        } else {
            $this->stats['failed']++;
        }

        return $response ?: null;
    }

    /**
     * Send a PING health check.
     */
    public function ping(): ?string
    {
        return $this->sendRaw('PING');
    }

    /**
     * @return array{sent: int, failed: int, bytes: int}
     */
    public function getStats(): array
    {
        return $this->stats;
    }

    /**
     * Build a closure that hands out child-event timestamps spread across the busy
     * (controller/handler) window of a parent execution, so a request/job waterfall
     * staggers queries/caches along the timeline instead of stacking them at t=0.
     * Uses the request phase fields (bootstrap/middleware/action µs) when present, else
     * a generic slice of the total duration. The dashboard sorts the timeline by
     * timestamp, so random instants in the window interleave the child types naturally.
     *
     * @param  array<string, mixed>  $parent  the parent request / job-attempt / command record
     */
    private function childClock(float $start, array $parent): \Closure
    {
        $totalUs = (float) ($parent['duration'] ?? 1_000_000);

        if (isset($parent['action'])) {
            // Request: children run during the action (controller) phase.
            $winStartUs = (float) (($parent['bootstrap'] ?? 0)
                + ($parent['before_middleware'] ?? 0) + ($parent['after_middleware'] ?? 0));
            $winSpanUs = max(1.0, (float) $parent['action'] * 0.92);
        } else {
            // Job / command / etc.: spread across the bulk of the total duration.
            $winStartUs = $totalUs * 0.06;
            $winSpanUs = max(1.0, $totalUs * 0.84);
        }

        return fn (): float => $start
            + ($winStartUs + (mt_rand(0, 1000) / 1000.0) * $winSpanUs) / 1_000_000.0;
    }

    // ─── Scenarios ─────────────────────────────────────────────

    /**
     * Send a realistic request lifecycle: request + queries + cache + user.
     */
    public function simulateRequest(array $overrides = []): ?string
    {
        $traceId = $this->uuid();
        $now = $this->now();
        $userId = 'user_'.mt_rand(1, 50);

        $records = [];

        // The request itself
        $records[] = $this->makeRequest(array_merge([
            'trace_id' => $traceId,
            'timestamp' => $now,
            'user' => $userId,
        ], $overrides));

        // Spread the child events across the request's controller window so the
        // detail-page waterfall staggers them along the timeline (not stacked at t=0).
        $childAt = $this->childClock($now, $records[0]);

        // 2-8 queries
        $queryCount = mt_rand(2, 8);
        for ($i = 0; $i < $queryCount; $i++) {
            $records[] = $this->makeQuery([
                'trace_id' => $this->uuid(),
                'timestamp' => $childAt(),
                'execution_id' => $traceId,
                'execution_source' => 'request',
                'user' => $userId,
            ]);
        }

        // 1-3 cache events
        $cacheCount = mt_rand(1, 3);
        for ($i = 0; $i < $cacheCount; $i++) {
            $records[] = $this->makeCacheEvent([
                'trace_id' => $this->uuid(),
                'timestamp' => $childAt(),
                'execution_id' => $traceId,
                'execution_source' => 'request',
                'user' => $userId,
            ]);
        }

        // Some requests send mail, fire a notification, or make an outgoing call —
        // link a fraction so they surface on the request-detail timeline (the
        // realistic feeder still emits standalone ones too, e.g. queued mail).
        if (mt_rand(1, 3) === 1) {
            $records[] = $this->makeMail([
                'trace_id' => $this->uuid(), 'timestamp' => $childAt(),
                'execution_id' => $traceId, 'execution_source' => 'request', 'user' => $userId,
            ]);
        }
        if (mt_rand(1, 4) === 1) {
            $records[] = $this->makeNotification([
                'trace_id' => $this->uuid(), 'timestamp' => $childAt(),
                'execution_id' => $traceId, 'execution_source' => 'request', 'user' => $userId,
            ]);
        }
        if (mt_rand(1, 2) === 1) {
            $records[] = $this->makeOutgoingRequest([
                'trace_id' => $this->uuid(), 'timestamp' => $childAt(),
                'execution_id' => $traceId, 'execution_source' => 'request', 'user' => $userId,
            ]);
        }

        // User record
        $records[] = $this->makeUser($userId);

        return $this->send($records);
    }

    /**
     * Simulate a request that throws an exception.
     */
    public function simulateErrorRequest(array $overrides = []): ?string
    {
        $traceId = $this->uuid();
        $now = $this->now();
        $userId = 'user_'.mt_rand(1, 50);

        $records = [];

        // A failing request still did some DB work. Emit real child queries AND pin
        // the request's child counters to what we actually emit, so the request-detail
        // header doesn't advertise phantom queries/cache/mail over empty child tabs.
        $queryCount = mt_rand(2, 6);
        $records[] = $this->makeRequest(array_merge([
            'trace_id' => $traceId,
            'timestamp' => $now,
            'user' => $userId,
            'status_code' => 500,
            'exceptions' => 1,
            'queries' => $queryCount,
            'logs' => 1,
            'cache_events' => 0,
            'mail' => 0,
            'notifications' => 0,
            'outgoing_requests' => 0,
            'jobs_queued' => 0,
            // No duration override — fromFixture jitters duration + the phase timings
            // by the SAME factor, keeping them consistent (an mt_rand duration here
            // would desync from the jittered phases and let a phase exceed the total).
        ], $overrides));

        $childAt = $this->childClock($now, $records[0]);

        for ($i = 0; $i < $queryCount; $i++) {
            $records[] = $this->makeQuery([
                'trace_id' => $this->uuid(),
                'timestamp' => $childAt(),
                'execution_id' => $traceId,
                'execution_source' => 'request',
                'user' => $userId,
            ]);
        }

        // The throw + its error log share one instant within the handler window.
        $failAt = $childAt();
        $records[] = $this->makeException([
            'trace_id' => $this->uuid(),
            'timestamp' => $failAt,
            'execution_id' => $traceId,
            'execution_source' => 'request',
            'user' => $userId,
        ]);

        $records[] = $this->makeLog([
            'trace_id' => $this->uuid(),
            'timestamp' => $failAt,
            'execution_id' => $traceId,
            'execution_source' => 'request',
            'level' => 'error',
            'message' => 'Unhandled exception in request handler',
        ]);

        return $this->send($records);
    }

    /**
     * Simulate a queued job lifecycle: a queued-job dispatch event followed by a
     * job-attempt execution event with the given status.
     */
    public function simulateJob(string $status = 'processed', array $overrides = []): ?string
    {
        $traceId = $this->uuid();
        $now = $this->now();
        $userId = 'user_'.mt_rand(1, 50);
        $jobId = $this->uuid();
        $attemptId = $this->uuid();

        $records = [];

        // Execution event (job-attempt) — carries status, duration, exceptions, and
        // the job's identity (name/_group/queue/connection). Built first so the
        // dispatch row can share it.
        $attempt = $this->makeJobAttempt([
            'trace_id' => $this->uuid(),
            'timestamp' => $now + 0.01,
            'user' => $userId,
            'job_id' => $jobId,
            'attempt_id' => $attemptId,
            'attempt' => 1,
            'status' => $status,
        ]);

        // Dispatch event (queued-job) — no execution stats. Give it a UNIQUE
        // execution_id (its own trace) so the attempt-detail tree finds THIS job's
        // attempt (the fixture's shared zero-UUID would sibling-link every job), and
        // PIN the attempt's job identity so dispatch + attempt agree on
        // name/group_hash/queue/connection (fromFixture picks a random fixture row
        // per call, so otherwise the same job_id's two rows disagree and the
        // job-detail page — grouped by group_hash — comes up empty).
        $records[] = $this->makeJob(array_merge([
            'trace_id' => $traceId,
            'timestamp' => $now,
            'user' => $userId,
            'job_id' => $jobId,
            'execution_id' => $traceId,
            'name' => $attempt['name'] ?? null,
            '_group' => $attempt['_group'] ?? null,
            'queue' => $attempt['queue'] ?? null,
            'connection' => $attempt['connection'] ?? null,
            // A queued/dispatch row has NO execution duration — null it (the fixture
            // carries an enqueue-time value). The raw job-duration queries assume
            // "queued rows have null duration"; a real duration here drags avg/min/p95.
            'duration' => null,
        ], $overrides));
        $records[] = $attempt;

        // Spread the attempt's child events across its execution window (relative to the
        // attempt's own start) so the job-attempt waterfall staggers them, not at t=0.
        $childAt = $this->childClock($now + 0.01, $attempt);

        // Jobs do queries too. A job's child events ran INSIDE the attempt, so they
        // link via execution_id = attempt_id (this is how the job attempt-detail page
        // resolves its timeline — not the dispatch trace_id).
        for ($i = 0; $i < mt_rand(1, 5); $i++) {
            $records[] = $this->makeQuery([
                'trace_id' => $this->uuid(),
                'timestamp' => $childAt(),
                'execution_id' => $attemptId,
                'execution_source' => 'job',
                'user' => $userId,
            ]);
        }

        if ($status === 'failed') {
            $records[] = $this->makeException([
                'trace_id' => $this->uuid(),
                'timestamp' => $childAt(),
                'execution_id' => $attemptId,
                'execution_source' => 'job',
                'user' => $userId,
            ]);
        }

        return $this->send($records);
    }

    /**
     * Simulate an artisan command execution.
     */
    public function simulateCommand(array $overrides = []): ?string
    {
        $traceId = $this->uuid();
        $now = $this->now();

        $records = [];

        $records[] = $this->makeCommand(array_merge([
            'trace_id' => $traceId,
            'timestamp' => $now,
        ], $overrides));

        $childAt = $this->childClock($now, $records[0]);

        for ($i = 0; $i < mt_rand(0, 3); $i++) {
            $records[] = $this->makeQuery([
                'trace_id' => $this->uuid(),
                'timestamp' => $childAt(),
                'execution_id' => $traceId,
                'execution_source' => 'command',
            ]);
        }

        return $this->send($records);
    }

    /**
     * Simulate a scheduled task execution.
     */
    public function simulateScheduledTask(array $overrides = []): ?string
    {
        $traceId = $this->uuid();
        $now = $this->now();

        $records = [];
        $records[] = $this->makeScheduledTask(array_merge([
            'trace_id' => $traceId,
            'timestamp' => $now,
        ], $overrides));

        $childAt = $this->childClock($now, $records[0]);

        // Scheduled tasks run queries too — link them (execution_source=scheduled_task,
        // execution_id=trace_id, which is how the task-detail page resolves children)
        // so the timeline isn't empty.
        for ($i = 0; $i < mt_rand(1, 4); $i++) {
            $records[] = $this->makeQuery([
                'trace_id' => $this->uuid(),
                'timestamp' => $childAt(),
                'execution_id' => $traceId,
                'execution_source' => 'scheduled_task',
            ]);
        }

        return $this->send($records);
    }

    /**
     * Run a full traffic scenario.
     */
    public function runScenario(string $scenario, int $count = 100): void
    {
        $start = microtime(true);

        match ($scenario) {
            'mixed' => $this->scenarioMixed($count),
            'error-storm' => $this->scenarioErrorStorm($count),
            'high-throughput' => $this->scenarioHighThroughput($count),
            'jobs' => $this->scenarioJobs($count),
            'realistic' => $this->scenarioRealistic($count),
            default => throw new \InvalidArgumentException("Unknown scenario: {$scenario}"),
        };

        $elapsed = round((microtime(true) - $start) * 1000);
        $s = $this->stats;
        fwrite(STDOUT, "\nDone: {$s['sent']} sent, {$s['failed']} failed, "
            .number_format($s['bytes'])." bytes, {$elapsed}ms\n");
    }

    private function scenarioMixed(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $type = mt_rand(0, 9);
            match (true) {
                $type <= 4 => $this->simulateRequest(),         // 50% requests
                $type <= 6 => $this->simulateJob(),             // 20% jobs
                $type === 7 => $this->simulateCommand(),        // 10% commands
                $type === 8 => $this->simulateErrorRequest(),   // 10% errors
                default => $this->simulateScheduledTask(),      // 10% scheduled
            };
            $this->printProgress($i + 1, $count);
        }
    }

    private function scenarioErrorStorm(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->simulateErrorRequest([
                'url' => '/api/checkout',
                'route_path' => '/api/checkout',
            ]);
            $this->printProgress($i + 1, $count);
        }
    }

    private function scenarioHighThroughput(int $count): void
    {
        // Send large batches to stress the buffer
        for ($i = 0; $i < $count; $i++) {
            $records = [];
            for ($j = 0; $j < 20; $j++) {
                $records[] = $this->makeRequest([
                    'trace_id' => $this->uuid(),
                    'timestamp' => $this->now(),
                ]);
            }
            $this->send($records);
            $this->printProgress($i + 1, $count);
        }
    }

    private function scenarioJobs(int $count): void
    {
        $statuses = ['processed', 'processed', 'processed', 'released', 'failed'];

        for ($i = 0; $i < $count; $i++) {
            $status = $statuses[array_rand($statuses)];
            $this->simulateJob($status);
            $this->printProgress($i + 1, $count);
        }
    }

    private function scenarioRealistic(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->realisticTick();
            $this->printProgress($i + 1, $count);
        }
    }

    /**
     * One iteration of the `realistic` scenario: a weighted-random event cluster
     * (lots of requests, some jobs, rare errors) plus the occasional standalone
     * mail/notification/outgoing. With $sleep=true it self-paces with 10-100ms
     * jitter so a long-running feeder produces lifelike, non-bursty traffic; the
     * backfill command passes $sleep=false to fill a past window as fast as it
     * can. Extracted from scenarioRealistic so the demo feeder can drive it
     * tick-by-tick and stop cleanly between ticks.
     */
    public function realisticTick(bool $sleep = true): void
    {
        // Error budget for this tick (percent, per-app tunable). Most errors are
        // failing requests, a minority failed jobs; everything else is a healthy mix
        // with no exception. Keeps a healthy app from reading as on-fire.
        if (mt_rand(1, 10000) / 100.0 <= $this->errorRate) {
            if (mt_rand(1, 4) === 1) {
                $this->simulateJob('failed');
            } else {
                $this->simulateErrorRequest();
            }
        } else {
            $roll = mt_rand(1, 100);
            match (true) {
                $roll <= 68 => $this->simulateRequest(),
                $roll <= 84 => $this->simulateJob(),
                $roll <= 93 => $this->simulateCommand(),
                default => $this->simulateScheduledTask(),
            };
        }

        // Occasional STANDALONE mail/notification/outgoing — these have NO parent
        // execution (queued mail, scheduler notifications, etc.). Null the execution
        // link explicitly: the fixtures default execution_source='request' + a zero-UUID
        // execution_id, which the detail page's Source badge would otherwise deep-link
        // to a non-existent request ("Request not found").
        $standalone = ['execution_source' => null, 'execution_id' => null];
        if (mt_rand(1, 10) === 1) {
            $this->send([
                $this->makeMail(array_merge(['trace_id' => $this->uuid(), 'timestamp' => $this->now()], $standalone)),
            ]);
        }
        if (mt_rand(1, 15) === 1) {
            $this->send([
                $this->makeNotification(array_merge(['trace_id' => $this->uuid(), 'timestamp' => $this->now()], $standalone)),
            ]);
        }
        if (mt_rand(1, 5) === 1) {
            $this->send([
                $this->makeOutgoingRequest(array_merge(['trace_id' => $this->uuid(), 'timestamp' => $this->now()], $standalone)),
            ]);
        }

        if ($sleep) {
            usleep(mt_rand(10_000, 100_000)); // 10-100ms between events
        }
    }

    // ─── Record Builders ───────────────────────────────────────
    //
    // Each make*() returns a captured Nightwatch payload (random row from the
    // matching fixture file) with a fresh trace_id + timestamp, then merges
    // any caller overrides. The fixtures are the source of truth for which
    // fields exist — do not add hand-rolled defaults here.

    public function makeRequest(array $overrides = []): array
    {
        return array_merge($this->fromFixture('request'), $overrides);
    }

    public function makeQuery(array $overrides = []): array
    {
        return array_merge($this->fromFixture('query'), $overrides);
    }

    public function makeException(array $overrides = []): array
    {
        $record = array_merge($this->fromFixture('exception'), $overrides);
        // Force a deterministic `code` when the caller doesn't pin one — the
        // fixture file carries multiple sample rows (some with SQLSTATE codes
        // like "23505") and a random pick would produce a different `_group`
        // each call, breaking tests that send N exceptions and expect them
        // to dedupe into one issue.
        if (! array_key_exists('code', $overrides)) {
            $record['code'] = '0';
        }
        // Recompute _group to reflect the merged class/code/file/line — the
        // baked fixture hash is stale once a test customizes those fields.
        // Formula matches the test's expected md5(class|0|file|line).
        $record['_group'] = md5(
            ($record['class'] ?? '').'|'.($record['code'] ?? '').'|'.($record['file'] ?? '').'|'.($record['line'] ?? '')
        );

        return $record;
    }

    /**
     * Job dispatch event — `t: queued-job`. No execution stats (status,
     * exceptions, queries, etc.) — those belong to job-attempt records.
     */
    public function makeJob(array $overrides = []): array
    {
        return array_merge($this->fromFixture('queued-job'), $overrides);
    }

    /**
     * Job execution event — `t: job-attempt`. Carries status/duration/exceptions/etc.
     * Pair with a queued-job record (same job_id) to model the full lifecycle.
     */
    public function makeJobAttempt(array $overrides = []): array
    {
        return array_merge($this->fromFixture('job-attempt'), $overrides);
    }

    public function makeCommand(array $overrides = []): array
    {
        return array_merge($this->fromFixture('command'), $overrides);
    }

    public function makeScheduledTask(array $overrides = []): array
    {
        return array_merge($this->fromFixture('scheduled-task'), $overrides);
    }

    public function makeCacheEvent(array $overrides = []): array
    {
        return array_merge($this->fromFixture('cache-event'), $overrides);
    }

    public function makeMail(array $overrides = []): array
    {
        return array_merge($this->fromFixture('mail'), $overrides);
    }

    public function makeNotification(array $overrides = []): array
    {
        return array_merge($this->fromFixture('notification'), $overrides);
    }

    public function makeOutgoingRequest(array $overrides = []): array
    {
        return array_merge($this->fromFixture('outgoing-request'), $overrides);
    }

    public function makeLog(array $overrides = []): array
    {
        return array_merge($this->fromFixture('log'), $overrides);
    }

    public function makeUser(string $userId): array
    {
        // Derive a stable, distinct identity from the user_id so the Users page
        // doesn't collapse 50 ids onto the fixture's handful of names/emails.
        $n = (int) preg_replace('/\D/', '', $userId);
        $n = $n > 0 ? $n : (int) (crc32($userId) % 997);
        $first = ['Alex', 'Priya', 'Sam', 'Jordan', 'Casey', 'Morgan', 'Riley', 'Taylor', 'Jamie', 'Quinn'][$n % 10];
        $last = ['Rivera', 'Nair', 'Okafor', 'Chen', 'Patel', 'Kim', 'Silva', 'Costa', 'Diaz', 'Reed'][intdiv($n, 10) % 10];

        return array_merge($this->fromFixture('user'), [
            'id' => $userId,
            'name' => $first.' '.$last,
            // RecordWriter writes the user's email from the wire `username` field
            // (RecordWriter.php: 'email' => $r['username']), so override THAT — an
            // 'email' override never reaches the DB and the emails stayed collapsed.
            'username' => strtolower($first.'.'.$last).$n.'@example.com',
        ]);
    }

    // ─── Helpers ───────────────────────────────────────────────

    /**
     * Pull a random row from a fixture file, then refresh the mutable fields
     * (trace_id, timestamp). Fixtures are loaded lazily and cached per type.
     */
    private function fromFixture(string $type): array
    {
        if (! isset($this->fixtures[$type])) {
            $path = __DIR__."/fixtures/{$type}.jsonl";
            if (! is_file($path)) {
                throw new \RuntimeException("Missing fixture file: {$path}");
            }

            $rows = [];
            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $rows[] = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            }

            if ($rows === []) {
                throw new \RuntimeException("Empty fixture file: {$path}");
            }

            $this->fixtures[$type] = $rows;
        }

        $row = $this->fixtures[$type][array_rand($this->fixtures[$type])];

        if (array_key_exists('trace_id', $row)) {
            $row['trace_id'] = $this->uuid();
        }
        if (array_key_exists('timestamp', $row)) {
            $row['timestamp'] = $this->now();
        }
        // Spread durations (~0.35x–2.8x) so percentiles diverge instead of every
        // row in a group sharing the fixture's single value (which makes P50=P95=P99
        // and the percentile selector meaningless). A caller override still wins,
        // since overrides are array_merge'd over this in the make*() methods.
        if (isset($row['duration']) && is_numeric($row['duration'])) {
            $factor = mt_rand(35, 280) / 100;
            $row['duration'] = max(1, (int) round(((float) $row['duration']) * $factor));
            // Scale a request's lifecycle phase timings by the SAME factor so they
            // still sum to ~duration — an unscaled phase could exceed the jittered
            // total and overflow the request-detail timeline bar.
            foreach (['bootstrap', 'before_middleware', 'action', 'render', 'after_middleware', 'sending', 'terminating'] as $phase) {
                if (isset($row[$phase]) && is_numeric($row[$phase])) {
                    $row[$phase] = (int) round(((float) $row[$phase]) * $factor);
                }
            }
        }
        // Drop the baked execution_preview (only child/queued rows carry it). It's a
        // denormalized hint of the PARENT execution, but the fixture's value is a
        // random unrelated route — so it mislabels once the row is stitched to a real
        // parent. Null it; the UI's SourceCell resolves the true parent via execution_id.
        if (array_key_exists('execution_preview', $row)) {
            $row['execution_preview'] = null;
        }

        return $row;
    }

    private function uuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xFFFF), mt_rand(0, 0xFFFF),
            mt_rand(0, 0xFFFF),
            mt_rand(0, 0x0FFF) | 0x4000,
            mt_rand(0, 0x3FFF) | 0x8000,
            mt_rand(0, 0xFFFF), mt_rand(0, 0xFFFF), mt_rand(0, 0xFFFF),
        );
    }

    private function printProgress(int $current, int $total): void
    {
        if ($current % 10 === 0 || $current === $total) {
            fwrite(STDOUT, "\r  [{$current}/{$total}] sent: {$this->stats['sent']}, failed: {$this->stats['failed']}");
        }
    }
}
