<?php

declare(strict_types=1);

session_start();

require __DIR__ . '/../src/helpers.php';
require __DIR__ . '/../src/db.php';

$configPath = __DIR__ . '/../config/config.php';

if (file_exists($configPath)) {
    redirect('index.php');
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $adminUser = trim((string) ($_POST['admin_user'] ?? ''));
    $adminPassword = (string) ($_POST['admin_password'] ?? '');

    if ($adminUser === '' || $adminPassword === '') {
        $error = 'Bitte Benutzername und Passwort angeben.';
    } else {
        $dbPath = __DIR__ . '/../data/survey.sqlite';
        if (!is_dir(dirname($dbPath))) {
            mkdir(dirname($dbPath), 0777, true);
        }

        $pdo = get_db($dbPath);
        initialize_schema($pdo);

        $configContents = "<?php\n\nreturn [\n" .
            "    'admin_user' => '" . addslashes($adminUser) . "',\n" .
            "    'admin_password_hash' => '" . password_hash($adminPassword, PASSWORD_DEFAULT) . "',\n" .
            "    'db_path' => __DIR__ . '/../data/survey.sqlite',\n" .
            "];\n";

        if (!is_dir(dirname($configPath))) {
            mkdir(dirname($configPath), 0777, true);
        }

        file_put_contents($configPath, $configContents);
        $success = 'Setup abgeschlossen! Du kannst jetzt starten.';
    }
}
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Setup</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 2rem; background: #f8f4f0; }
        .card { background: #fff; padding: 1.5rem; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); max-width: 420px; }
        .error { color: #b00020; }
        .success { color: #2f7d32; }
        label { display: block; margin-bottom: 0.5rem; font-weight: bold; }
        input, button { width: 100%; padding: 0.5rem; margin-bottom: 1rem; }
    </style>
</head>
<body>
    <h1>Erst-Setup</h1>
    <div class="card">
        <?php if ($error !== ''): ?>
            <p class="error"><?= h($error) ?></p>
        <?php endif; ?>
        <?php if ($success !== ''): ?>
            <p class="success"><?= h($success) ?></p>
            <p><a href="index.php">Zur Umfrage</a></p>
        <?php else: ?>
            <form method="post">
                <label for="admin_user">Admin Benutzername</label>
                <input type="text" id="admin_user" name="admin_user" required>
                <label for="admin_password">Admin Passwort</label>
                <input type="password" id="admin_password" name="admin_password" required>
                <button type="submit">Setup starten</button>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>
