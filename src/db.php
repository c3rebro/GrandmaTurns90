<?php

declare(strict_types=1);

function get_db(string $dbPath): PDO
{
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    return $pdo;
}

function initialize_schema(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS participants (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            selected_at TEXT NOT NULL
        )'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS guest_list (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            created_at TEXT NOT NULL
        )'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS responses (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            participant_id INTEGER NOT NULL,
            people_count INTEGER NOT NULL,
            food_text TEXT NOT NULL,
            created_at TEXT NOT NULL,
            FOREIGN KEY (participant_id) REFERENCES participants(id) ON DELETE CASCADE
        )'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS food_entries (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            food_text TEXT NOT NULL UNIQUE,
            created_at TEXT NOT NULL
        )'
    );
}

function fetch_food_entries(PDO $pdo): array
{
    // Query existing food entries so they can be shown before submission.
    $stmt = $pdo->query('SELECT food_text FROM food_entries ORDER BY food_text');

    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function ensure_food_entry(PDO $pdo, string $foodText, string $timestamp): void
{
    // Insert new food entry if it doesn't already exist.
    $stmt = $pdo->prepare('INSERT OR IGNORE INTO food_entries (food_text, created_at) VALUES (:food_text, :created_at)');
    $stmt->execute([
        ':food_text' => $foodText,
        ':created_at' => $timestamp,
    ]);
}

function fetch_guest_list(PDO $pdo): array
{
    // Query the editable guest list for the survey dropdown.
    $stmt = $pdo->query('SELECT name FROM guest_list ORDER BY name');

    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function replace_guest_list(PDO $pdo, array $names, string $timestamp): void
{
    // Replace the stored guest list with the provided names.
    $pdo->beginTransaction();
    $pdo->exec('DELETE FROM guest_list');

    $insertStmt = $pdo->prepare('INSERT INTO guest_list (name, created_at) VALUES (:name, :created_at)');
    foreach ($names as $name) {
        $insertStmt->execute([
            ':name' => $name,
            ':created_at' => $timestamp,
        ]);
    }

    $pdo->commit();
}

function seed_guest_list(PDO $pdo, array $names, string $timestamp): void
{
    // Seed the guest list once to provide initial names for the survey.
    $stmt = $pdo->query('SELECT COUNT(*) FROM guest_list');
    $count = (int) $stmt->fetchColumn();

    if ($count === 0 && $names !== []) {
        replace_guest_list($pdo, $names, $timestamp);
    }
}

function insert_response(PDO $pdo, string $participantName, int $peopleCount, string $foodText, string $timestamp): void
{
    // Store participant selection with timestamp.
    $participantStmt = $pdo->prepare('INSERT INTO participants (name, selected_at) VALUES (:name, :selected_at)');
    $participantStmt->execute([
        ':name' => $participantName,
        ':selected_at' => $timestamp,
    ]);

    $participantId = (int) $pdo->lastInsertId();

    // Store survey response linked to participant.
    $responseStmt = $pdo->prepare(
        'INSERT INTO responses (participant_id, people_count, food_text, created_at)
         VALUES (:participant_id, :people_count, :food_text, :created_at)'
    );
    $responseStmt->execute([
        ':participant_id' => $participantId,
        ':people_count' => $peopleCount,
        ':food_text' => $foodText,
        ':created_at' => $timestamp,
    ]);
}
