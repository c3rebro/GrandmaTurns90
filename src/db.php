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
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS settings (
            key TEXT PRIMARY KEY,
            value TEXT NOT NULL
        )'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS response_tokens (
            response_id INTEGER PRIMARY KEY,
            token TEXT NOT NULL,
            created_at TEXT NOT NULL,
            FOREIGN KEY (response_id) REFERENCES responses(id) ON DELETE CASCADE
        )'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS page_visits (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ip_address TEXT NOT NULL,
            page_path TEXT NOT NULL,
            visited_at TEXT NOT NULL
        )'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS login_attempts (
            ip_address TEXT PRIMARY KEY,
            attempt_count INTEGER NOT NULL,
            last_attempt_at TEXT NOT NULL
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

function prune_unused_food_entries(PDO $pdo): void
{
    // Remove food entries that are no longer referenced by any response.
    $pdo->exec('DELETE FROM food_entries WHERE food_text NOT IN (SELECT food_text FROM responses)');
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

function fetch_settings(PDO $pdo): array
{
    $defaults = [
        'survey_title' => 'Omas 90. Geburtstag',
        'gate_question_count' => 1,
        'gate_questions' => [
            [
                'question' => 'Wie lautet der Vorname von Oma?',
                'answer' => 'ilse',
            ],
        ],
        'hints_content' => '',
        'footer_content' => '',
    ];

    $stmt = $pdo->query('SELECT key, value FROM settings');
    $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $title = $rows['survey_title'] ?? $defaults['survey_title'];
    $count = isset($rows['gate_question_count'])
        ? max(1, (int) $rows['gate_question_count'])
        : $defaults['gate_question_count'];

    $questions = $defaults['gate_questions'];
    if (isset($rows['gate_questions'])) {
        $decoded = json_decode($rows['gate_questions'], true);
        if (is_array($decoded)) {
            $questions = $decoded;
        }
    }

    if ($questions === []) {
        $questions = $defaults['gate_questions'];
    }

    if (count($questions) < $count) {
        $questions = array_pad($questions, $count, $defaults['gate_questions'][0]);
    }

    return [
        'survey_title' => $title,
        'gate_question_count' => $count,
        'gate_questions' => $questions,
        'hints_content' => $rows['hints_content'] ?? $defaults['hints_content'],
        'footer_content' => $rows['footer_content'] ?? $defaults['footer_content'],
    ];
}

function update_settings(
    PDO $pdo,
    string $title,
    int $questionCount,
    array $questions,
    string $hintsContent,
    string $footerContent
): void
{
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('INSERT OR REPLACE INTO settings (key, value) VALUES (:key, :value)');
    $stmt->execute([
        ':key' => 'survey_title',
        ':value' => $title,
    ]);
    $stmt->execute([
        ':key' => 'gate_question_count',
        ':value' => (string) $questionCount,
    ]);
    $stmt->execute([
        ':key' => 'gate_questions',
        ':value' => json_encode($questions, JSON_UNESCAPED_UNICODE),
    ]);
    $stmt->execute([
        ':key' => 'hints_content',
        ':value' => $hintsContent,
    ]);
    $stmt->execute([
        ':key' => 'footer_content',
        ':value' => $footerContent,
    ]);
    $pdo->commit();
}

function seed_settings(PDO $pdo): void
{
    $stmt = $pdo->query('SELECT COUNT(*) FROM settings');
    $count = (int) $stmt->fetchColumn();

    if ($count === 0) {
        update_settings(
            $pdo,
            'Omas 90. Geburtstag',
            1,
            [
                [
                    'question' => 'Wie lautet der Vorname von Oma?',
                    'answer' => 'ilse',
                ],
            ],
            '',
            ''
        );
    }
}

function insert_response(PDO $pdo, string $participantName, int $peopleCount, string $foodText, string $timestamp): int
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

    return (int) $pdo->lastInsertId();
}

function store_response_token(PDO $pdo, int $responseId, string $token, string $timestamp): void
{
    $stmt = $pdo->prepare(
        'INSERT OR REPLACE INTO response_tokens (response_id, token, created_at)
         VALUES (:response_id, :token, :created_at)'
    );
    $stmt->execute([
        ':response_id' => $responseId,
        ':token' => $token,
        ':created_at' => $timestamp,
    ]);
}

function fetch_response_for_token(PDO $pdo, int $responseId, string $token): ?array
{
    $stmt = $pdo->prepare(
        'SELECT responses.id, responses.people_count, responses.food_text, responses.created_at,
                participants.name AS participant_name
         FROM responses
         JOIN participants ON participants.id = responses.participant_id
         JOIN response_tokens ON response_tokens.response_id = responses.id
         WHERE responses.id = :response_id AND response_tokens.token = :token'
    );
    $stmt->execute([
        ':response_id' => $responseId,
        ':token' => $token,
    ]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function update_response_for_participant(
    PDO $pdo,
    int $responseId,
    string $participantName,
    int $peopleCount,
    string $foodText
): void
{
    $participantStmt = $pdo->prepare('SELECT participant_id FROM responses WHERE id = :id');
    $participantStmt->execute([':id' => $responseId]);
    $participantId = (int) $participantStmt->fetchColumn();

    if ($participantId > 0) {
        $updateParticipant = $pdo->prepare('UPDATE participants SET name = :name WHERE id = :id');
        $updateParticipant->execute([
            ':name' => $participantName,
            ':id' => $participantId,
        ]);
    }

    $updateResponse = $pdo->prepare(
        'UPDATE responses SET people_count = :people_count, food_text = :food_text WHERE id = :id'
    );
    $updateResponse->execute([
        ':people_count' => $peopleCount,
        ':food_text' => $foodText,
        ':id' => $responseId,
    ]);

    prune_unused_food_entries($pdo);
}

function delete_response_for_participant(PDO $pdo, int $responseId): void
{
    $pdo->beginTransaction();
    $participantIdStmt = $pdo->prepare('SELECT participant_id FROM responses WHERE id = :id');
    $participantIdStmt->execute([':id' => $responseId]);
    $participantId = (int) $participantIdStmt->fetchColumn();

    $deleteResponseStmt = $pdo->prepare('DELETE FROM responses WHERE id = :id');
    $deleteResponseStmt->execute([':id' => $responseId]);

    $deleteTokenStmt = $pdo->prepare('DELETE FROM response_tokens WHERE response_id = :id');
    $deleteTokenStmt->execute([':id' => $responseId]);

    if ($participantId > 0) {
        $deleteParticipantStmt = $pdo->prepare('DELETE FROM participants WHERE id = :id');
        $deleteParticipantStmt->execute([':id' => $participantId]);
    }

    prune_unused_food_entries($pdo);

    $pdo->commit();
}

function log_page_visit(PDO $pdo, string $ipAddress, string $pagePath, string $timestamp): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO page_visits (ip_address, page_path, visited_at) VALUES (:ip_address, :page_path, :visited_at)'
    );
    $stmt->execute([
        ':ip_address' => $ipAddress,
        ':page_path' => $pagePath,
        ':visited_at' => $timestamp,
    ]);
}

function purge_old_ip_logs(PDO $pdo, string $cutoff): void
{
    $visitStmt = $pdo->prepare('DELETE FROM page_visits WHERE visited_at < :cutoff');
    $visitStmt->execute([':cutoff' => $cutoff]);

    $loginStmt = $pdo->prepare('DELETE FROM login_attempts WHERE last_attempt_at < :cutoff');
    $loginStmt->execute([':cutoff' => $cutoff]);
}

function fetch_login_attempt(PDO $pdo, string $ipAddress): ?array
{
    $stmt = $pdo->prepare(
        'SELECT attempt_count, last_attempt_at FROM login_attempts WHERE ip_address = :ip_address'
    );
    $stmt->execute([':ip_address' => $ipAddress]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function record_login_failure(PDO $pdo, string $ipAddress, string $timestamp): int
{
    $current = fetch_login_attempt($pdo, $ipAddress);
    $attempts = $current ? ((int) $current['attempt_count']) + 1 : 1;

    $stmt = $pdo->prepare(
        'INSERT OR REPLACE INTO login_attempts (ip_address, attempt_count, last_attempt_at)
         VALUES (:ip_address, :attempt_count, :last_attempt_at)'
    );
    $stmt->execute([
        ':ip_address' => $ipAddress,
        ':attempt_count' => $attempts,
        ':last_attempt_at' => $timestamp,
    ]);

    return $attempts;
}

function reset_login_attempts(PDO $pdo, string $ipAddress): void
{
    $stmt = $pdo->prepare('DELETE FROM login_attempts WHERE ip_address = :ip_address');
    $stmt->execute([':ip_address' => $ipAddress]);
}
