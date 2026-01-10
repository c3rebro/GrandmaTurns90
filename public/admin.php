<?php

declare(strict_types=1);

session_start();

require __DIR__ . '/../src/helpers.php';
require __DIR__ . '/../src/db.php';

$configPath = __DIR__ . '/../config/config.php';
$config = load_config($configPath);

if ($config === null) {
    redirect('setup.php');
}

$pdo = get_db($config['db_path']);
initialize_schema($pdo);
$now = new DateTimeImmutable();
$timestamp = $now->format(DateTimeInterface::ATOM);
$cutoff = $now->modify('-24 hours')->format(DateTimeInterface::ATOM);
$clientIp = get_client_ip();
purge_old_ip_logs($pdo, $cutoff);
log_page_visit($pdo, $clientIp, $_SERVER['REQUEST_URI'] ?? 'admin.php', $timestamp);

$defaultGuests = [
    'Andreas',
    'Maria',
    'Lena',
    'Thomas',
    'Sabine',
];

$seedTimestamp = $timestamp;
seed_guest_list($pdo, $defaultGuests, $seedTimestamp);
seed_settings($pdo);

$authError = '';
$actionMessage = '';
if (!empty($_SESSION['action_message'])) {
    $actionMessage = (string) $_SESSION['action_message'];
    unset($_SESSION['action_message']);
}
// Track failed login attempts to temporarily block repeated failures.
$loginAttempt = fetch_login_attempt($pdo, $clientIp);
$loginBlocked = $loginAttempt !== null && (int) $loginAttempt['attempt_count'] >= 3;

if (isset($_POST['logout'])) {
    unset($_SESSION['admin_authenticated']);
    redirect('admin.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_login'])) {
    if ($loginBlocked) {
        $authError = 'Zu viele Fehlversuche. Bitte versuche es in 24 Stunden erneut.';
    }

    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($authError === '') {
        if ($username === $config['admin_user'] && password_verify($password, $config['admin_password_hash'])) {
            $_SESSION['admin_authenticated'] = true;
            reset_login_attempts($pdo, $clientIp);
            redirect('admin.php');
        }

        record_login_failure($pdo, $clientIp, $timestamp);
        $authError = 'Login fehlgeschlagen. Nach 3 Fehlversuchen erfolgt eine temporäre Sperre.';
        $loginAttempt = fetch_login_attempt($pdo, $clientIp);
        $loginBlocked = $loginAttempt !== null && (int) $loginAttempt['attempt_count'] >= 3;
    }
}

if (!empty($_SESSION['admin_authenticated'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_response'])) {
        $id = (int) ($_POST['response_id'] ?? 0);

        // Delete response and related records in a transaction.
        delete_response_for_participant($pdo, $id);
        $actionMessage = 'Eintrag gelöscht.';
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_guest_list'])) {
        $rawList = (string) ($_POST['guest_list'] ?? '');
        $names = array_filter(
            array_map('trim', preg_split('/\r\n|\r|\n/', $rawList)),
            static fn(string $name): bool => $name !== ''
        );

        if ($names === []) {
            $actionMessage = 'Bitte mindestens einen Namen angeben.';
        } else {
            $timestamp = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
            replace_guest_list($pdo, array_values($names), $timestamp);
            $actionMessage = 'Gästeliste aktualisiert.';
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_settings'])) {
        $title = trim((string) ($_POST['survey_title'] ?? ''));
        $selectedCount = (int) ($_POST['gate_question_count'] ?? 1);
        $selectedCount = max(1, min(3, $selectedCount));
        $questions = [];
        $settingsError = '';
        $maxFilled = 0;

        for ($index = 1; $index <= 3; $index++) {
            $questionText = trim((string) ($_POST['gate_question_' . $index] ?? ''));
            $answerText = trim((string) ($_POST['gate_answer_' . $index] ?? ''));

            if ($questionText === '' && $answerText === '') {
                if ($index <= $selectedCount) {
                    $settingsError = 'Bitte alle Torfragen und Antworten ausfüllen.';
                    break;
                }
                continue;
            }

            if ($questionText === '' || $answerText === '') {
                $settingsError = 'Bitte alle Torfragen und Antworten ausfüllen.';
                break;
            }

            $questions[] = [
                'question' => $questionText,
                'answer' => $answerText,
            ];
            $maxFilled = $index;
        }

        if ($title === '') {
            $settingsError = 'Bitte einen Titel angeben.';
        }

        if ($settingsError !== '') {
            $actionMessage = $settingsError;
        } else {
            $hintsContent = (string) ($_POST['hints_content'] ?? '');
            $footerContent = (string) ($_POST['footer_content'] ?? '');

            $questionCount = max($selectedCount, $maxFilled);
            update_settings($pdo, $title, $questionCount, $questions, $hintsContent, $footerContent);
            $actionMessage = 'Einstellungen aktualisiert.';
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_response'])) {
        $id = (int) ($_POST['response_id'] ?? 0);
        $participantName = trim((string) ($_POST['participant_name'] ?? ''));
        $peopleCount = (int) ($_POST['people_count'] ?? 0);
        $foodText = trim((string) ($_POST['food_text'] ?? ''));

        if ($participantName !== '' && $peopleCount > 0 && $foodText !== '') {
            // Update participant and response records based on admin edits.
            $participantStmt = $pdo->prepare('SELECT participant_id FROM responses WHERE id = :id');
            $participantStmt->execute([':id' => $id]);
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
                ':id' => $id,
            ]);

            ensure_food_entry($pdo, ucfirst($foodText), $timestamp);
            prune_unused_food_entries($pdo);
            $_SESSION['action_message'] = 'Eintrag aktualisiert.';
            redirect('admin.php');
        } else {
            $actionMessage = 'Bitte alle Felder ausfüllen.';
        }
    }
}

$editingId = (int) ($_GET['edit'] ?? 0);

$responses = [];
$guestList = [];
$settings = [];
if (!empty($_SESSION['admin_authenticated'])) {
    // Query all responses with participant data for the admin table.
    $stmt = $pdo->query(
        'SELECT responses.id, responses.people_count, responses.food_text, responses.created_at, participants.name AS participant_name
         FROM responses
         JOIN participants ON participants.id = responses.participant_id
         ORDER BY responses.created_at DESC'
    );
    $responses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $guestList = fetch_guest_list($pdo);
    $settings = fetch_settings($pdo);
}
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Adminbereich</title>
    <link rel="stylesheet" href="assets/bootstrap/css/bootstrap.min.css">
</head>
<body class="bg-light">
    <div class="container py-5">
        <h1 class="mb-4">Adminbereich</h1>
        <div class="card shadow-sm">
            <div class="card-body">
                <?php if (empty($_SESSION['admin_authenticated'])): ?>
                    <?php if ($authError !== ''): ?>
                        <p class="text-danger"><?= h($authError) ?></p>
                    <?php endif; ?>
                    <form method="post">
                        <?php if ($loginBlocked): ?>
                            <p class="text-danger">Diese IP-Adresse ist vorübergehend gesperrt.</p>
                        <?php endif; ?>
                        <label class="form-label" for="username">Benutzername</label>
                        <input class="form-control" type="text" id="username" name="username" required <?= $loginBlocked ? 'disabled' : '' ?>>
                        <label class="form-label" for="password">Passwort</label>
                        <input class="form-control" type="password" id="password" name="password" required <?= $loginBlocked ? 'disabled' : '' ?>>
                        <button class="btn btn-primary w-100 my-3" type="submit" name="admin_login" value="1" <?= $loginBlocked ? 'disabled' : '' ?>>Einloggen</button>
                    </form>
                <?php else: ?>
                    <form method="post" class="mb-3">
                        <button class="btn btn-outline-secondary my-3" type="submit" name="logout" value="1">Abmelden</button>
                    </form>

                    <?php if ($actionMessage !== ''): ?>
                        <p class="text-success fw-semibold"><?= h($actionMessage) ?></p>
                    <?php endif; ?>

                    <div class="card mb-4">
                        <div class="card-body">
                            <h2 class="h5">Einstellungen</h2>
                            <form method="post">
                                <label class="form-label" for="survey_title">Titel der Seite</label>
                                <input class="form-control" type="text" id="survey_title" name="survey_title" value="<?= h($settings['survey_title'] ?? '') ?>" required>

                                <label class="form-label" for="gate_question_count">Anzahl Torfragen</label>
                                <select class="form-select" id="gate_question_count" name="gate_question_count">
                                    <?php for ($count = 1; $count <= 3; $count++): ?>
                                        <option value="<?= $count ?>" <?= ((int) ($settings['gate_question_count'] ?? 1) === $count) ? 'selected' : '' ?>>
                                            <?= $count ?>
                                        </option>
                                    <?php endfor; ?>
                                </select>

                                <?php for ($index = 1; $index <= 3; $index++): ?>
                                    <?php $question = $settings['gate_questions'][$index - 1]['question'] ?? ''; ?>
                                    <?php $answer = $settings['gate_questions'][$index - 1]['answer'] ?? ''; ?>
                                    <label class="form-label mt-3" for="gate_question_<?= $index ?>">Torfrage <?= $index ?></label>
                                    <input class="form-control" type="text" id="gate_question_<?= $index ?>" name="gate_question_<?= $index ?>" value="<?= h($question) ?>">
                                    <label class="form-label mt-2" for="gate_answer_<?= $index ?>">Antwort <?= $index ?></label>
                                    <input class="form-control" type="text" id="gate_answer_<?= $index ?>" name="gate_answer_<?= $index ?>" value="<?= h($answer) ?>">
                                <?php endfor; ?>

                                <label class="form-label mt-3" for="hints_content">Hinweise (HTML oder Markdown)</label>
                                <textarea class="form-control" id="hints_content" name="hints_content" rows="4"><?= h($settings['hints_content'] ?? '') ?></textarea>

                                <label class="form-label mt-3" for="footer_content">Footer (HTML oder Markdown)</label>
                                <textarea class="form-control" id="footer_content" name="footer_content" rows="3"><?= h($settings['footer_content'] ?? '') ?></textarea>

                                <button class="btn btn-primary w-100 my-3" type="submit" name="update_settings" value="1">Einstellungen speichern</button>
                            </form>
                        </div>
                    </div>

                    <div class="card mb-4">
                        <div class="card-body">
                            <h2 class="h5">Gästeliste bearbeiten</h2>
                            <form method="post">
                                <label class="form-label" for="guest_list">Ein Name pro Zeile</label>
                                <textarea class="form-control" id="guest_list" name="guest_list" rows="6" required><?= h(implode("\n", $guestList)) ?></textarea>
                                <button class="btn btn-primary w-100 my-3" type="submit" name="update_guest_list" value="1">Gästeliste speichern</button>
                            </form>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table align-middle">
                            <thead>
                                <tr>
                                    <th>Teilnehmer</th>
                                    <th>Personen</th>
                                    <th>Essen</th>
                                    <th>Zeitpunkt</th>
                                    <th>Aktionen</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($responses as $response): ?>
                                    <?php if ($editingId === (int) $response['id']): ?>
                                        <tr>
                                            <form method="post">
                                                <td>
                                                    <input class="form-control" type="text" name="participant_name" value="<?= h($response['participant_name']) ?>" required>
                                                </td>
                                                <td>
                                                    <input class="form-control" type="number" name="people_count" min="1" value="<?= h((string) $response['people_count']) ?>" required>
                                                </td>
                                                <td>
                                                    <input class="form-control" type="text" name="food_text" value="<?= h($response['food_text']) ?>" required>
                                                </td>
                                                <td><?= h($response['created_at']) ?></td>
                                                <td>
                                                    <input type="hidden" name="response_id" value="<?= h((string) $response['id']) ?>">
                                                    <button class="btn btn-success btn-sm my-3" type="submit" name="update_response" value="1">Speichern</button>
                                                </td>
                                            </form>
                                        </tr>
                                    <?php else: ?>
                                        <tr>
                                            <td><?= h($response['participant_name']) ?></td>
                                            <td><?= h((string) $response['people_count']) ?></td>
                                            <td><?= h($response['food_text']) ?></td>
                                            <td><?= h($response['created_at']) ?></td>
                                            <td class="d-flex flex-wrap gap-2">
                                                <a class="btn btn-outline-primary btn-sm" href="admin.php?edit=<?= h((string) $response['id']) ?>">Bearbeiten</a>
                                                <form method="post" onsubmit="return confirm('Wirklich löschen?');">
                                                    <input type="hidden" name="response_id" value="<?= h((string) $response['id']) ?>">
                                                    <button class="btn btn-outline-danger btn-sm my-3" type="submit" name="delete_response" value="1">Löschen</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <script src="assets/bootstrap/js/bootstrap.bundle.min.js"></script>
</body>
</html>
