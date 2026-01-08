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
$defaultParticipants = [
    'Andreas',
    'Maria',
    'Lena',
    'Thomas',
    'Sabine',
];

$seedTimestamp = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
seed_guest_list($pdo, $defaultParticipants, $seedTimestamp);
seed_settings($pdo);

$participants = fetch_guest_list($pdo);
$settings = fetch_settings($pdo);
$surveyTitle = $settings['survey_title'];
$gateQuestionCount = (int) $settings['gate_question_count'];
$gateQuestions = $settings['gate_questions'];
$gateError = '';
$formError = '';
$successMessage = '';
$foodEntries = fetch_food_entries($pdo);

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
            $timestamp = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
            $normalizedFood = ucfirst($foodText);

            ensure_food_entry($pdo, $normalizedFood, $timestamp);
            insert_response($pdo, $participantName, $peopleCount, $normalizedFood, $timestamp);

            $successMessage = 'Danke! Deine Antwort wurde gespeichert.';
            $foodEntries = fetch_food_entries($pdo);
        }
    }
}
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
                <?php if (!empty($_SESSION['gate_passed'])): ?>
                    <p class="text-success fw-semibold mb-0">Super! Du darfst teilnehmen.</p>
                <?php endif; ?>
            </div>
        </section>

        <?php if (!empty($_SESSION['gate_passed'])): ?>
            <section class="card shadow-sm">
                <div class="card-body">
                    <h2 class="h4">Teilnahme &amp; Umfrage</h2>
                    <?php if ($formError !== ''): ?>
                        <p class="text-danger"><?= h($formError) ?></p>
                    <?php endif; ?>
                    <?php if ($successMessage !== ''): ?>
                        <p class="text-success fw-semibold"><?= h($successMessage) ?></p>
                    <?php endif; ?>
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
                </div>
            </section>
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
