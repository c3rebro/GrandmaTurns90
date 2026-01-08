<?php

declare(strict_types=1);

require __DIR__ . '/../src/db.php';

// Simple sanity tests for schema creation and response insertion.
$pdo = get_db(':memory:');
initialize_schema($pdo);

$timestamp = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
seed_settings($pdo);
$seededSettings = fetch_settings($pdo);
assert($seededSettings['survey_title'] === 'Omas 90. Geburtstag', 'Default title should be seeded.');
assert((int) $seededSettings['gate_question_count'] === 1, 'Default gate question count should be seeded.');

update_settings($pdo, 'Testtitel', 2, [
    ['question' => 'Frage 1', 'answer' => 'Antwort 1'],
    ['question' => 'Frage 2', 'answer' => 'Antwort 2'],
]);

$settings = fetch_settings($pdo);
assert($settings['survey_title'] === 'Testtitel', 'Settings title should be updated.');
assert((int) $settings['gate_question_count'] === 2, 'Settings question count should be updated.');
assert($settings['gate_questions'][1]['answer'] === 'Antwort 2', 'Settings questions should be updated.');

seed_guest_list($pdo, ['Alice', 'Bob'], $timestamp);

$guestList = fetch_guest_list($pdo);
sort($guestList);
assert($guestList === ['Alice', 'Bob'], 'Guest list should be seeded.');

replace_guest_list($pdo, ['Carla'], $timestamp);
$guestList = fetch_guest_list($pdo);
assert($guestList === ['Carla'], 'Guest list should be replaceable.');

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
