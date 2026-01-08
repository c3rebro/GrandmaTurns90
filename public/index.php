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
log_page_visit($pdo, $clientIp, $_SERVER['REQUEST_URI'] ?? 'index.php', $timestamp);

$defaultParticipants = [
    'Andreas',
    'Maria',
    'Lena',
    'Thomas',
    'Sabine',
];

$seedTimestamp = $timestamp;
seed_guest_list($pdo, $defaultParticipants, $seedTimestamp);
seed_settings($pdo);

$participants = fetch_guest_list($pdo);
$settings = fetch_settings($pdo);
$surveyTitle = $settings['survey_title'];
$gateQuestionCount = (int) $settings['gate_question_count'];
$gateQuestions = $settings['gate_questions'];
$hintsContent = $settings['hints_content'] ?? '';
$footerContent = $settings['footer_content'] ?? '';
$gateError = '';
$formError = '';
$successMessage = '';
$foodEntries = fetch_food_entries($pdo);
$participantResponse = null;
$participantCookie = (string) ($_COOKIE['participant_access'] ?? '');
$cookieResponseId = null;
$cookieToken = null;

// Restore participant access from the saved cookie token (if present).
if ($participantCookie !== '' && strpos($participantCookie, ':') !== false) {
    [$cookieResponseId, $cookieToken] = explode(':', $participantCookie, 2);
    if (ctype_digit($cookieResponseId) && $cookieToken !== '') {
        $participantResponse = fetch_response_for_token($pdo, (int) $cookieResponseId, $cookieToken);
    }
}

if ($participantCookie !== '' && $participantResponse === null) {
    setcookie('participant_access', '', time() - 3600);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['gate_check'])) {
    $gatePassed = true;
    for ($index = 0; $index < $gateQuestionCount; $index++) {
        $questionConfig = $gateQuestions[$index] ?? null;
        $expected = $questionConfig['answer'] ?? '';
        $fieldName = 'gate_answer_' . ($index + 1);
        $givenAnswer = strtolower(trim((string) ($_POST[$fieldName] ?? '')));

        if ($expected === '' || $givenAnswer === '' || $givenAnswer !== strtolower($expected)) {
            $gatePassed = false;
            break;
        }
    }

    // Gate status is stored in session to unlock the survey flow.
    if ($gatePassed) {
        $_SESSION['gate_passed'] = true;
    } else {
        $gateError = 'Bitte alle Fragen korrekt beantworten.';
        unset($_SESSION['gate_passed']);
    }
}

// Participants can update their own response when a valid token cookie is present.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['participant_update'])) {
    if ($participantResponse === null) {
        $formError = 'Deine Antwort konnte nicht gefunden werden.';
    } else {
        $participantName = trim((string) ($_POST['participant'] ?? ''));
        $peopleCount = (int) ($_POST['people_count'] ?? 0);
        $foodText = trim((string) ($_POST['food_text'] ?? ''));

        if ($participantName === '' || !in_array($participantName, $participants, true)) {
            $formError = 'Bitte einen Teilnehmer auswählen.';
        } elseif ($peopleCount <= 0) {
            $formError = 'Bitte eine gültige Personenzahl angeben.';
        } elseif ($foodText === '') {
            $formError = 'Bitte ein Essen angeben.';
        } else {
            $normalizedFood = ucfirst($foodText);
            ensure_food_entry($pdo, $normalizedFood, $timestamp);
            update_response_for_participant(
                $pdo,
                (int) $participantResponse['id'],
                $participantName,
                $peopleCount,
                $normalizedFood
            );
            $participantResponse = fetch_response_for_token(
                $pdo,
                (int) $participantResponse['id'],
                (string) $cookieToken
            );
            $successMessage = 'Deine Antwort wurde aktualisiert.';
            $foodEntries = fetch_food_entries($pdo);
        }
    }
}

// Allow participants to delete their own response when authenticated via cookie.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['participant_delete'])) {
    if ($participantResponse === null) {
        $formError = 'Deine Antwort konnte nicht gefunden werden.';
    } else {
        delete_response_for_participant($pdo, (int) $participantResponse['id']);
        setcookie('participant_access', '', time() - 3600);
        $participantResponse = null;
        $successMessage = 'Deine Antwort wurde gelöscht.';
        $foodEntries = fetch_food_entries($pdo);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['survey_submit'])) {
    if (empty($_SESSION['gate_passed'])) {
        $formError = 'Bitte zuerst das Tor-Formular korrekt ausfüllen.';
    } else {
        $participantName = trim((string) ($_POST['participant'] ?? ''));
        $peopleCount = (int) ($_POST['people_count'] ?? 0);
        $foodText = trim((string) ($_POST['food_text'] ?? ''));

        if ($participantName === '' || !in_array($participantName, $participants, true)) {
            $formError = 'Bitte einen Teilnehmer auswählen.';
        } elseif ($peopleCount <= 0) {
            $formError = 'Bitte eine gültige Personenzahl angeben.';
        } elseif ($foodText === '') {
            $formError = 'Bitte ein Essen angeben.';
        } else {
            $normalizedFood = ucfirst($foodText);

            ensure_food_entry($pdo, $normalizedFood, $timestamp);
            $responseId = insert_response($pdo, $participantName, $peopleCount, $normalizedFood, $timestamp);
            $token = bin2hex(random_bytes(16));
            store_response_token($pdo, $responseId, $token, $timestamp);
            setcookie('participant_access', $responseId . ':' . $token, time() + 60 * 60 * 24 * 30);
            $participantResponse = fetch_response_for_token($pdo, $responseId, $token);

            $successMessage = 'Danke! Deine Antwort wurde gespeichert.';
            $foodEntries = fetch_food_entries($pdo);
        }
    }
}

$surveyUnlocked = !empty($_SESSION['gate_passed']) || $participantResponse !== null;
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($surveyTitle) ?></title>
    <link rel="stylesheet" href="assets/bootstrap/css/bootstrap.min.css">
</head>
<body class="bg-light">
    <div class="container py-5">
        <h1 class="mb-4"><?= h($surveyTitle) ?></h1>

        <?php if (empty($_SESSION['gate_passed']) && $participantResponse === null): ?>
            <section class="card shadow-sm mb-4">
                <div class="card-body">
                    <h2 class="h4">Torfrage</h2>
                    <?php if ($gateError !== ''): ?>
                        <p class="text-danger"><?= h($gateError) ?></p>
                    <?php endif; ?>
                    <form method="post">
                        <?php for ($index = 0; $index < $gateQuestionCount; $index++): ?>
                            <?php $question = $gateQuestions[$index]['question'] ?? ''; ?>
                            <label class="form-label" for="gate_answer_<?= $index + 1 ?>"><?= h($question) ?></label>
                            <input class="form-control mb-2" type="text" id="gate_answer_<?= $index + 1 ?>" name="gate_answer_<?= $index + 1 ?>" required>
                        <?php endfor; ?>
                        <button class="btn btn-primary w-100 my-3" type="submit" name="gate_check" value="1">Prüfen</button>
                    </form>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($surveyUnlocked): ?>
            <?php if ($hintsContent !== ''): ?>
                <section class="card shadow-sm mb-4">
                    <div class="card-body">
                        <h2 class="h5">Hinweise</h2>
                        <?= render_rich_text($hintsContent) ?>
                    </div>
                </section>
            <?php endif; ?>

            <section class="card shadow-sm">
                <div class="card-body">
                    <h2 class="h4">Teilnahme &amp; Umfrage</h2>
                    <?php if ($formError !== ''): ?>
                        <p class="text-danger"><?= h($formError) ?></p>
                    <?php endif; ?>
                    <?php if ($successMessage !== ''): ?>
                        <p class="text-success fw-semibold"><?= h($successMessage) ?></p>
                    <?php endif; ?>
                    <?php if ($participantResponse !== null): ?>
                        <p class="mb-3">Du hast bereits eine Antwort gespeichert. Du kannst sie hier bearbeiten oder löschen.</p>
                        <form method="post">
                            <label class="form-label" for="participant">Teilnehmer auswählen</label>
                            <select class="form-select" id="participant" name="participant" required>
                                <option value="">Bitte wählen</option>
                                <?php foreach ($participants as $participant): ?>
                                    <option value="<?= h($participant) ?>" <?= $participant === $participantResponse['participant_name'] ? 'selected' : '' ?>>
                                        <?= h($participant) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>

                            <label class="form-label" for="people_count">Wie viele Personen bringt ihr mit?</label>
                            <input class="form-control" type="number" id="people_count" name="people_count" min="1" value="<?= h((string) $participantResponse['people_count']) ?>" required>

                            <label class="form-label" for="food_text">Welches Essen bringt ihr mit?</label>
                            <?php if (!empty($foodEntries)): ?>
                                <p class="mb-2">Bereits eingetragen:</p>
                                <ul class="mb-3">
                                    <?php foreach ($foodEntries as $entry): ?>
                                        <li><?= h($entry) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                            <input class="form-control" type="text" id="food_text" name="food_text" value="<?= h($participantResponse['food_text']) ?>" required>

                            <button class="btn btn-success w-100 my-3" type="submit" name="participant_update" value="1">Antwort aktualisieren</button>
                        </form>
                        <form method="post" onsubmit="return confirm('Möchtest du deine Antwort wirklich löschen?');">
                            <button class="btn btn-outline-danger w-100" type="submit" name="participant_delete" value="1">Antwort löschen</button>
                        </form>
                    <?php else: ?>
                        <form method="post">
                            <label class="form-label" for="participant">Teilnehmer auswählen</label>
                            <select class="form-select" id="participant" name="participant" required>
                                <option value="">Bitte wählen</option>
                                <?php foreach ($participants as $participant): ?>
                                    <option value="<?= h($participant) ?>"><?= h($participant) ?></option>
                                <?php endforeach; ?>
                            </select>

                            <label class="form-label" for="people_count">Wie viele Personen bringt ihr mit?</label>
                            <input class="form-control" type="number" id="people_count" name="people_count" min="1" required>

                            <label class="form-label" for="food_text">Welches Essen bringt ihr mit?</label>
                            <?php if (!empty($foodEntries)): ?>
                                <p class="mb-2">Bereits eingetragen:</p>
                                <ul class="mb-3">
                                    <?php foreach ($foodEntries as $entry): ?>
                                        <li><?= h($entry) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                            <input class="form-control" type="text" id="food_text" name="food_text" placeholder="z.B. Kartoffelsalat" required>

                            <button class="btn btn-success w-100 my-3" type="submit" name="survey_submit" value="1">Antwort speichern</button>
                        </form>
                    <?php endif; ?>
                </div>
            </section>
            <?php if ($footerContent !== ''): ?>
                <footer class="mt-4 text-center text-muted small">
                    <?= render_rich_text($footerContent) ?>
                </footer>
            <?php endif; ?>
        <?php else: ?>
            <section class="card shadow-sm">
                <div class="card-body">
                    <h2 class="h4">Teilnahme &amp; Umfrage</h2>
                    <p class="mb-0">Bitte zuerst die Torfrage korrekt beantworten, damit die Umfrage freigeschaltet wird.</p>
                </div>
            </section>
        <?php endif; ?>
    </div>
    <script src="assets/bootstrap/js/bootstrap.bundle.min.js"></script>
</body>
</html>
