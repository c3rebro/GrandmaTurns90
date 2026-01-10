<?php

declare(strict_types=1);

require __DIR__ . '/../src/db.php';
require __DIR__ . '/../src/helpers.php';

// Simple sanity tests for schema creation and response insertion.
$pdo = get_db(':memory:');
initialize_schema($pdo);

$timestamp = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
seed_settings($pdo);
$seededSettings = fetch_settings($pdo);
assert($seededSettings['survey_title'] === 'Omas 90. Geburtstag', 'Default title should be seeded.');
assert((int) $seededSettings['gate_question_count'] === 1, 'Default gate question count should be seeded.');
assert($seededSettings['hints_content'] === '', 'Default hints content should be seeded.');
assert($seededSettings['footer_content'] === '', 'Default footer content should be seeded.');

update_settings(
    $pdo,
    'Testtitel',
    2,
    [
        ['question' => 'Frage 1', 'answer' => 'Antwort 1'],
        ['question' => 'Frage 2', 'answer' => 'Antwort 2'],
    ],
    'Hinweise **fett**',
    'Footer <a href="mailto:test@example.com">Mail</a>'
);

$settings = fetch_settings($pdo);
assert($settings['survey_title'] === 'Testtitel', 'Settings title should be updated.');
assert((int) $settings['gate_question_count'] === 2, 'Settings question count should be updated.');
assert($settings['gate_questions'][1]['answer'] === 'Antwort 2', 'Settings questions should be updated.');
assert($settings['hints_content'] === 'Hinweise **fett**', 'Hints content should be updated.');
assert($settings['footer_content'] === 'Footer <a href="mailto:test@example.com">Mail</a>', 'Footer content should be updated.');

seed_guest_list($pdo, ['Alice', 'Bob'], $timestamp);

$guestList = fetch_guest_list($pdo);
sort($guestList);
assert($guestList === ['Alice', 'Bob'], 'Guest list should be seeded.');

replace_guest_list($pdo, ['Carla'], $timestamp);
$guestList = fetch_guest_list($pdo);
assert($guestList === ['Carla'], 'Guest list should be replaceable.');

$responseId = insert_response($pdo, 'Testperson', 3, 'Kuchen', $timestamp);

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

$token = 'token-value';
ensure_food_entry($pdo, 'Kuchen', $timestamp);
store_response_token($pdo, $responseId, $token, $timestamp);
$response = fetch_response_for_token($pdo, $responseId, $token);
assert($response !== null, 'Response should be fetchable by token.');
assert($response['participant_name'] === 'Testperson', 'Token response should include participant name.');

ensure_food_entry($pdo, 'Salat', $timestamp);
update_response_for_participant($pdo, $responseId, 'Neue Person', 4, 'Salat');
$updated = fetch_response_for_token($pdo, $responseId, $token);
assert($updated['participant_name'] === 'Neue Person', 'Participant name should update for token response.');
assert((int) $updated['people_count'] === 4, 'People count should update for token response.');
$foodsAfterUpdate = $pdo->query('SELECT food_text FROM food_entries ORDER BY food_text')->fetchAll(PDO::FETCH_COLUMN);
assert($foodsAfterUpdate === ['Salat'], 'Unused food entries should be pruned after update.');

delete_response_for_participant($pdo, $responseId);
$deleted = fetch_response_for_token($pdo, $responseId, $token);
assert($deleted === null, 'Response should be removed after delete.');
$foodCountAfterDelete = (int) $pdo->query('SELECT COUNT(*) FROM food_entries')->fetchColumn();
assert($foodCountAfterDelete === 0, 'Food entries should be cleaned up when unused.');

log_page_visit($pdo, '127.0.0.1', '/index.php', $timestamp);
$visitCount = (int) $pdo->query('SELECT COUNT(*) FROM page_visits')->fetchColumn();
assert($visitCount === 1, 'Page visits should be logged.');

$firstAttempt = record_login_failure($pdo, '127.0.0.1', $timestamp);
assert($firstAttempt === 1, 'First login failure should be recorded.');
$secondAttempt = record_login_failure($pdo, '127.0.0.1', $timestamp);
assert($secondAttempt === 2, 'Second login failure should be recorded.');

$attemptRow = fetch_login_attempt($pdo, '127.0.0.1');
assert($attemptRow !== null, 'Login attempt row should exist.');

reset_login_attempts($pdo, '127.0.0.1');
$attemptCleared = fetch_login_attempt($pdo, '127.0.0.1');
assert($attemptCleared === null, 'Login attempts should reset.');

$oldTimestamp = (new DateTimeImmutable('-25 hours'))->format(DateTimeInterface::ATOM);
log_page_visit($pdo, '127.0.0.1', '/old.php', $oldTimestamp);
record_login_failure($pdo, '127.0.0.1', $oldTimestamp);
purge_old_ip_logs($pdo, $timestamp);
$remainingVisits = (int) $pdo->query('SELECT COUNT(*) FROM page_visits')->fetchColumn();
assert($remainingVisits === 1, 'Old page visits should be purged.');

$rendered = render_rich_text('**Bold**');
assert($rendered === '<p><strong>Bold</strong></p>', 'Markdown should render bold text.');
$htmlRendered = render_rich_text('<h1>Title</h1><script>alert(1)</script>');
assert(strpos($htmlRendered, '<h1>Title</h1>') !== false, 'Allowed HTML should render.');
assert(strpos($htmlRendered, '<script>') === false, 'Disallowed HTML should be stripped.');
