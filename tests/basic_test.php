<?php

declare(strict_types=1);

require __DIR__ . '/../src/db.php';

// Simple sanity tests for schema creation and response insertion.
$pdo = get_db(':memory:');
initialize_schema($pdo);

$timestamp = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
insert_response($pdo, 'Testperson', 3, 'Kuchen', $timestamp);

$statement = $pdo->query('SELECT participants.name, responses.people_count, responses.food_text FROM responses JOIN participants ON participants.id = responses.participant_id');
$row = $statement->fetch(PDO::FETCH_ASSOC);

assert($row !== false, 'Response should be inserted.');
assert($row['name'] === 'Testperson', 'Participant name should match.');
assert((int) $row['people_count'] === 3, 'People count should match.');
assert($row['food_text'] === 'Kuchen', 'Food text should match.');

$foodStatement = $pdo->query('SELECT COUNT(*) FROM food_entries');
$count = (int) $foodStatement->fetchColumn();

// Food entries are inserted via ensure_food_entry in the main flow, not in insert_response.
assert($count === 0, 'Food entries should be separate from responses.');
