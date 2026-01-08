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

$authError = '';
$actionMessage = '';

if (isset($_POST['logout'])) {
    unset($_SESSION['admin_authenticated']);
    redirect('admin.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_login'])) {
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($username === $config['admin_user'] && password_verify($password, $config['admin_password_hash'])) {
        $_SESSION['admin_authenticated'] = true;
        redirect('admin.php');
    }

    $authError = 'Login fehlgeschlagen.';
}

if (!empty($_SESSION['admin_authenticated'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_response'])) {
        $id = (int) ($_POST['response_id'] ?? 0);

        // Delete response and linked participant in a transaction.
        $pdo->beginTransaction();
        $participantIdStmt = $pdo->prepare('SELECT participant_id FROM responses WHERE id = :id');
        $participantIdStmt->execute([':id' => $id]);
        $participantId = (int) $participantIdStmt->fetchColumn();

        $deleteResponseStmt = $pdo->prepare('DELETE FROM responses WHERE id = :id');
        $deleteResponseStmt->execute([':id' => $id]);

        if ($participantId > 0) {
            $deleteParticipantStmt = $pdo->prepare('DELETE FROM participants WHERE id = :id');
            $deleteParticipantStmt->execute([':id' => $participantId]);
        }

        $pdo->commit();
        $actionMessage = 'Eintrag gelöscht.';
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_response'])) {
        $id = (int) ($_POST['response_id'] ?? 0);
        $participantName = trim((string) ($_POST['participant_name'] ?? ''));
        $peopleCount = (int) ($_POST['people_count'] ?? 0);
        $foodText = trim((string) ($_POST['food_text'] ?? ''));

        if ($participantName !== '' && $peopleCount > 0 && $foodText !== '') {
            $timestamp = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);

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
            $actionMessage = 'Eintrag aktualisiert.';
        } else {
            $actionMessage = 'Bitte alle Felder ausfüllen.';
        }
    }
}

$editingId = (int) ($_GET['edit'] ?? 0);

$responses = [];
if (!empty($_SESSION['admin_authenticated'])) {
    // Query all responses with participant data for the admin table.
    $stmt = $pdo->query(
        'SELECT responses.id, responses.people_count, responses.food_text, responses.created_at, participants.name AS participant_name
         FROM responses
         JOIN participants ON participants.id = responses.participant_id
         ORDER BY responses.created_at DESC'
    );
    $responses = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Adminbereich</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 2rem; background: #f2f2f2; }
        .card { background: #fff; padding: 1.5rem; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
        table { width: 100%; border-collapse: collapse; margin-top: 1rem; }
        th, td { padding: 0.5rem; border-bottom: 1px solid #ddd; text-align: left; }
        .error { color: #b00020; }
        .success { color: #2f7d32; }
        form.inline { display: inline; }
    </style>
</head>
<body>
    <h1>Adminbereich</h1>
    <div class="card">
        <?php if (empty($_SESSION['admin_authenticated'])): ?>
            <?php if ($authError !== ''): ?>
                <p class="error"><?= h($authError) ?></p>
            <?php endif; ?>
            <form method="post">
                <label for="username">Benutzername</label>
                <input type="text" id="username" name="username" required>
                <label for="password">Passwort</label>
                <input type="password" id="password" name="password" required>
                <button type="submit" name="admin_login" value="1">Einloggen</button>
            </form>
        <?php else: ?>
            <form method="post">
                <button type="submit" name="logout" value="1">Abmelden</button>
            </form>

            <?php if ($actionMessage !== ''): ?>
                <p class="success"><?= h($actionMessage) ?></p>
            <?php endif; ?>

            <table>
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
                                        <input type="text" name="participant_name" value="<?= h($response['participant_name']) ?>" required>
                                    </td>
                                    <td>
                                        <input type="number" name="people_count" min="1" value="<?= h((string) $response['people_count']) ?>" required>
                                    </td>
                                    <td>
                                        <input type="text" name="food_text" value="<?= h($response['food_text']) ?>" required>
                                    </td>
                                    <td><?= h($response['created_at']) ?></td>
                                    <td>
                                        <input type="hidden" name="response_id" value="<?= h((string) $response['id']) ?>">
                                        <button type="submit" name="update_response" value="1">Speichern</button>
                                    </td>
                                </form>
                            </tr>
                        <?php else: ?>
                            <tr>
                                <td><?= h($response['participant_name']) ?></td>
                                <td><?= h((string) $response['people_count']) ?></td>
                                <td><?= h($response['food_text']) ?></td>
                                <td><?= h($response['created_at']) ?></td>
                                <td>
                                    <a href="admin.php?edit=<?= h((string) $response['id']) ?>">Bearbeiten</a>
                                    <form method="post" class="inline" onsubmit="return confirm('Wirklich löschen?');">
                                        <input type="hidden" name="response_id" value="<?= h((string) $response['id']) ?>">
                                        <button type="submit" name="delete_response" value="1">Löschen</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</body>
</html>
