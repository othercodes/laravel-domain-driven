<?php

/*
 * This application publishes domain events from inside DB::transaction, on
 * purpose, so anything queued from a listener is pushed while the rows are
 * still uncommitted. On any connection whose push is not itself part of that
 * transaction, a worker can pick the job up and read the row as it was before.
 *
 * Held here rather than by an interface on each class: Queue::shouldDispatchAfterCommit()
 * falls through to the connection when a job says nothing, so the config
 * covers listeners, jobs, mailables and notifications at once, and nobody has
 * to remember an interface on the next class they write.
 */
test('no queue connection dispatches before the transaction commits', function () {
    /** @var array<string, array<string, mixed>> $connections */
    $connections = config('queue.connections');

    $before = array_keys(array_filter(
        $connections,
        // Only the drivers that carry the setting. sync and null run inline
        // and have nothing to defer.
        fn (array $connection): bool => array_key_exists('after_commit', $connection)
            && $connection['after_commit'] !== true
    ));

    expect($before)->toBeEmpty();
});
