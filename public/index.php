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
$participants = [
    'Andreas mit Familie',
    'Maria',
    'Lena',
    'Thomas',
    'Sabine',
];

$gateError = '';
$formError = '';
$successMessage = '';
$foodEntries = fetch_food_entries($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['gate_check'])) {
    $givenName = strtolower(trim((string) ($_POST['given_name'] ?? '')));

    // Gate status is stored in session to unlock the survey flow.
    if ($givenName === 'ilse') {
        $_SESSION['gate_passed'] = true;
    } else {
        $gateError = 'Bitte den richtigen Vornamen eingeben.';
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
    <title>Omas 90. Geburtstag</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 2rem; background: #f8f4f0; }
        .card { background: #fff; padding: 1.5rem; margin-bottom: 1.5rem; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
        label { display: block; margin-bottom: 0.5rem; font-weight: bold; }
        input, select, button, textarea { width: 100%; padding: 0.5rem; margin-bottom: 1rem; }
        .error { color: #b00020; }
        .success { color: #2f7d32; }
        ul { padding-left: 1.2rem; }
    </style>
</head>
<body>
    <h1>Einladung zum 90. Geburtstag</h1>

    <section class="card">
        <h2>Torfrage</h2>
        <?php if ($gateError !== ''): ?>
            <p class="error"><?= h($gateError) ?></p>
        <?php endif; ?>
        <form method="post">
            <label for="given_name">Wie lautet der Vorname von Oma?</label>
            <input type="text" id="given_name" name="given_name" required>
            <button type="submit" name="gate_check" value="1">Prüfen</button>
        </form>
        <?php if (!empty($_SESSION['gate_passed'])): ?>
            <p class="success">Super! Du darfst teilnehmen.</p>
        <?php endif; ?>
    </section>

    <section class="card">
        <h2>Teilnahme &amp; Umfrage</h2>
        <?php if ($formError !== ''): ?>
            <p class="error"><?= h($formError) ?></p>
        <?php endif; ?>
        <?php if ($successMessage !== ''): ?>
            <p class="success"><?= h($successMessage) ?></p>
        <?php endif; ?>
        <form method="post">
            <label for="participant">Teilnehmer auswählen</label>
            <select id="participant" name="participant" required>
                <option value="">Bitte wählen</option>
                <?php foreach ($participants as $participant): ?>
                    <option value="<?= h($participant) ?>"><?= h($participant) ?></option>
                <?php endforeach; ?>
            </select>

            <label for="people_count">Wie viele Personen bringt ihr mit?</label>
            <input type="number" id="people_count" name="people_count" min="1" required>

            <label for="food_text">Welches Essen bringt ihr mit?</label>
            <?php if (!empty($foodEntries)): ?>
                <p>Bereits eingetragen:</p>
                <ul>
                    <?php foreach ($foodEntries as $entry): ?>
                        <li><?= h($entry) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <input type="text" id="food_text" name="food_text" placeholder="z.B. Kartoffelsalat" required>

            <button type="submit" name="survey_submit" value="1">Antwort speichern</button>
        </form>
    </section>
</body>
</html>
