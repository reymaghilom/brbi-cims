<?php

declare(strict_types=1);

/**
 * Destructive-to-test-data integration test for mysql-schema.sql.
 *
 * It runs only when CIMS_N8N_TEST_DSN names a database ending in `_staging` or `_test` and uses
 * synthetic recipients. Install mysql-schema.sql first with a schema-owner account, then run with
 * a staging account that can CALL the routine and inspect/delete this script's synthetic rows.
 * The real n8n account must have EXECUTE only and is not suitable for this test harness.
 */

function fail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function connection(): PDO
{
    $dsn = getenv('CIMS_N8N_TEST_DSN') ?: '';
    $user = getenv('CIMS_N8N_TEST_USER') ?: '';
    $password = getenv('CIMS_N8N_TEST_PASSWORD') ?: '';

    if (! str_starts_with($dsn, 'mysql:')) {
        fail('CIMS_N8N_TEST_DSN must be an explicit mysql: DSN.');
    }

    $pdo = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_STRINGIFY_FETCHES => false,
    ]);
    $database = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    if (! preg_match('/_(staging|test)$/', $database)) {
        fail('Refusing to modify a database whose name does not end in _staging or _test.');
    }
    $pdo->exec("SET time_zone = '+00:00'");

    return $pdo;
}

function uuid(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);

    return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20);
}

/** @return array<string, mixed> */
function event(int $subjectId, int $version, string $type = 'schedule.changed', ?string $eventUuid = null, ?string $hashSeed = null): array
{
    $cancelled = $type === 'schedule.cancelled';
    $key = "ci_activity:{$subjectId}";

    return [
        'event_uuid' => $eventUuid ?? uuid(),
        'idempotency_key' => "{$key}:v{$version}",
        'payload_sha256' => hash('sha256', $hashSeed ?? uuid()),
        'subject_key' => $key,
        'subject_type' => 'ci_activity',
        'subject_id' => $subjectId,
        'ci_activity_id' => $subjectId,
        'schedule_version' => $version,
        'event_type' => $type,
        'occurred_at' => '2027-01-01 00:00:00.000000',
        'request_timestamp' => 1798761600,
        'received_at' => '2027-01-01 00:00:00.000000',
        'recipient_user_id' => 7,
        'recipient_email' => $cancelled ? null : 'synthetic.creator@example.test',
        'scheduled_at' => $cancelled ? null : '2027-01-05 01:00:00.000000',
        'trigger_at' => $cancelled ? null : '2027-01-05 01:00:00.000000',
        'trigger_at_local' => $cancelled ? null : '2027-01-05T09:00:00+08:00',
        'timezone' => 'Asia/Manila',
        'cancel_reason' => $cancelled ? 'completed' : null,
        'subject_label' => $cancelled ? null : 'Synthetic integration test',
        'activities_url' => $cancelled ? null : 'https://cims.example.test/client-folders/1/activities',
    ];
}

/** @param array<string, mixed> $event */
function ingest(PDO $pdo, array $event): array
{
    $statement = $pdo->prepare('CALL ingest_reminder_event(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $statement->execute(array_values($event));
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    while ($statement->nextRowset()) {
        // Consume MySQL's trailing routine result sets before reusing the connection.
    }
    $statement->closeCursor();

    return is_array($row) ? $row : fail('The ingest routine returned no status row.');
}

function expect(string $label, mixed $actual, mixed $expected): void
{
    if ($actual !== $expected) {
        fail("{$label}: expected ".var_export($expected, true).', got '.var_export($actual, true));
    }
    fwrite(STDOUT, "PASS: {$label}\n");
}

// Worker mode is used only for the two genuinely concurrent procedure calls.
if (($argv[1] ?? null) === '--worker') {
    $encoded = getenv('CIMS_N8N_TEST_EVENT') ?: '';
    $decoded = json_decode(base64_decode($encoded, true) ?: '', true, flags: JSON_THROW_ON_ERROR);
    fwrite(STDOUT, json_encode(ingest(connection(), $decoded), JSON_THROW_ON_ERROR));
    exit(0);
}

$pdo = connection();
$base = random_int(7_000_000_000, 7_999_000_000);
$subjectKeys = [];

try {
    $subject = $base + 1;
    $subjectKeys[] = "ci_activity:{$subject}";
    $created = event($subject, 1, 'schedule.created', hashSeed: 'creation');
    expect('valid schedule creation', ingest($pdo, $created)['outcome'], 'applied');
    expect('identical replay', ingest($pdo, $created)['outcome'], 'duplicate');

    $uuidConflict = $created;
    $uuidConflict['payload_sha256'] = hash('sha256', 'conflicting uuid payload');
    expect('conflicting duplicate UUID', ingest($pdo, $uuidConflict)['reason'], 'event_uuid_payload_conflict');

    $keyConflict = $created;
    $keyConflict['event_uuid'] = uuid();
    $keyConflict['payload_sha256'] = hash('sha256', 'conflicting idempotency payload');
    expect('conflicting idempotency key', ingest($pdo, $keyConflict)['reason'], 'idempotency_key_payload_conflict');

    $subject = $base + 2;
    $subjectKeys[] = "ci_activity:{$subject}";
    expect('version 3 first', ingest($pdo, event($subject, 3))['outcome'], 'applied');
    $stale = ingest($pdo, event($subject, 2));
    expect('version 2 after version 3', $stale['outcome'], 'stale');
    expect('version 3 remains current', (int) $stale['current_version'], 3);

    $subject = $base + 3;
    $subjectKeys[] = "ci_activity:{$subject}";
    expect('cancellation version 4 first', ingest($pdo, event($subject, 4, 'schedule.cancelled'))['outcome'], 'applied');
    $oldActive = ingest($pdo, event($subject, 3));
    expect('older active cannot undo cancellation', $oldActive['outcome'], 'stale');
    $active = (int) $pdo->query("SELECT is_active FROM reminder_states WHERE subject_key = 'ci_activity:{$subject}'")->fetchColumn();
    expect('cancelled state remains inactive', $active, 0);

    $subject = $base + 4;
    $subjectKeys[] = "ci_activity:{$subject}";
    $events = [event($subject, 5), event($subject, 6)];
    $processes = [];
    foreach ($events as $candidate) {
        $environment = array_merge(getenv(), ['CIMS_N8N_TEST_EVENT' => base64_encode(json_encode($candidate, JSON_THROW_ON_ERROR))]);
        $process = proc_open([PHP_BINARY, __FILE__, '--worker'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
        if (! is_resource($process)) {
            fail('Could not start concurrent MySQL worker.');
        }
        $processes[] = [$process, $pipes];
    }
    foreach ($processes as [$process, $pipes]) {
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            fail('Concurrent worker failed: '.trim($error));
        }
        json_decode($output, true, flags: JSON_THROW_ON_ERROR);
    }
    $version = (int) $pdo->query("SELECT schedule_version FROM reminder_states WHERE subject_key = 'ci_activity:{$subject}'")->fetchColumn();
    expect('concurrent same-subject events retain greatest version', $version, 6);
} finally {
    if ($subjectKeys !== []) {
        $placeholders = implode(',', array_fill(0, count($subjectKeys), '?'));
        $pdo->beginTransaction();
        foreach (['ingestion_audit', 'ingested_events', 'reminder_states'] as $table) {
            $statement = $pdo->prepare("DELETE FROM {$table} WHERE subject_key IN ({$placeholders})");
            $statement->execute($subjectKeys);
        }
        $pdo->commit();
    }
}

fwrite(STDOUT, "All MySQL integration scenarios passed; synthetic rows were removed.\n");
