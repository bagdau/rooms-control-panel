<?php
require_once __DIR__ . '/../lib/JsonStore.php';

function assert_equals($expected, $actual, $message) {
    if ($expected !== $actual) {
        fwrite(STDERR, "Assertion failed: {$message}\nExpected: " . var_export($expected, true) . "\nActual:   " . var_export($actual, true) . "\n");
        exit(1);
    }
}

$baseDir = sys_get_temp_dir() . '/json_store_tests_' . uniqid();
$store = new JsonStore($baseDir);
$room = 'test-room';

// read on non-existing room should return empty payload
$data = $store->read($room);
assert_equals($room, $data['room'], 'room name is returned');
assert_equals(0, $data['total'], 'default total is zero');
assert_equals([], $data['computers'], 'default computers is empty array');

// initRoom should create required number of computers with sequential ids
$created = $store->initRoom($room, 3);
assert_equals(3, $created['total'], 'initRoom stores requested total');
assert_equals(3, count($created['computers']), 'initRoom creates computers for requested total');
assert_equals('free', $created['computers'][0]['status'], 'new computers start with free status');

// updateComputer should change status and note while keeping other machines intact
$updated = $store->updateComputer($room, $created['computers'][1]['id'], 'busy', 'occupied');
assert_equals('busy', $updated['computers'][1]['status'], 'updateComputer switches status');
assert_equals('occupied', $updated['computers'][1]['note'], 'updateComputer stores note');

// updateComputer should reject invalid status
try {
    $store->updateComputer($room, 'invalid-001', 'offline');
    fwrite(STDERR, "Assertion failed: invalid status accepted\n");
    exit(1);
} catch (InvalidArgumentException $e) {
    // expected
}

// initRoom with higher total should keep existing statuses
$reinit = $store->initRoom($room, 5);
assert_equals(5, $reinit['total'], 'reinit updates total value');
assert_equals('busy', $reinit['computers'][1]['status'], 'reinit keeps previous statuses');
assert_equals(5, count($reinit['computers']), 'reinit adds new computers up to requested total');

// updateComputer with new id should append new record and bump total if needed
$added = $store->updateComputer($room, 'custom-001', 'down', 'maintenance');
$found = null;
foreach ($added['computers'] as $computer) {
    if ($computer['id'] === 'custom-001') {
        $found = $computer;
        break;
    }
}
if ($found === null) {
    fwrite(STDERR, "Assertion failed: newly added computer is present\n");
    exit(1);
}
assert_equals('down', $found['status'], 'newly added computer has requested status');
assert_equals('maintenance', $found['note'], 'newly added computer stores note');
assert_equals(max(5, count($added['computers'])), $added['total'], 'total is adjusted when new computer is added');

// setNote should update only the note field
$noteUpdated = $store->setNote($room, $created['computers'][0]['id'], 'keyboard replaced');
$first = null;
foreach ($noteUpdated['computers'] as $computer) {
    if ($computer['id'] === $created['computers'][0]['id']) {
        $first = $computer;
        break;
    }
}
if ($first === null) {
    fwrite(STDERR, "Assertion failed: computer not found after setNote\n");
    exit(1);
}
assert_equals('free', $first['status'], 'setNote keeps status untouched');
assert_equals('keyboard replaced', $first['note'], 'setNote updates note');

// resetRoom should clear statuses and notes
$reset = $store->resetRoom($room);
foreach ($reset['computers'] as $computer) {
    assert_equals('free', $computer['status'], 'resetRoom resets status to free');
    assert_equals('', $computer['note'], 'resetRoom clears note');
}

// read should normalize malformed payloads
$path = sys_get_temp_dir() . '/json_store_tests_' . uniqid() . '/rooms';
mkdir($path, 0777, true);
$brokenStore = new JsonStore($path);
$filename = $path . '/weird.json';
file_put_contents($filename, json_encode([
    'room' => 'weird',
    'total' => -1,
    'computers' => [
        ['id' => 'pc-002', 'status' => 'unknown', 'note' => '<script>'],
        ['id' => 'pc-001', 'status' => 'free']
    ]
]));
$normalized = $brokenStore->read('weird');
assert_equals(2, $normalized['total'], 'normalization fixes total');
assert_equals('pc-001', $normalized['computers'][0]['id'], 'normalization sorts ids');
assert_equals('free', $normalized['computers'][0]['status'], 'normalization keeps valid status');
assert_equals('free', $normalized['computers'][1]['status'], 'normalization replaces invalid status');

echo "All JsonStore tests passed.\n";
