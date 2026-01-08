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
    <link rel="stylesheet" href="assets/bootstrap/css/bootstrap.min.css">
</head>
<body class="bg-light">
    <div class="container py-5">
        <h1 class="mb-4">Erst-Setup</h1>
        <div class="row">
            <div class="col-12 col-md-6 col-lg-5">
                <div class="card shadow-sm">
                    <div class="card-body">
                        <?php if ($error !== ''): ?>
                            <p class="text-danger"><?= h($error) ?></p>
                        <?php endif; ?>
                        <?php if ($success !== ''): ?>
                            <p class="text-success fw-semibold"><?= h($success) ?></p>
                            <p><a class="link-primary" href="index.php">Zur Umfrage</a></p>
                        <?php else: ?>
                            <form method="post">
                                <label class="form-label" for="admin_user">Admin Benutzername</label>
                                <input class="form-control" type="text" id="admin_user" name="admin_user" required>
                                <label class="form-label" for="admin_password">Admin Passwort</label>
                                <input class="form-control" type="password" id="admin_password" name="admin_password" required>
                                <button class="btn btn-primary w-100" type="submit">Setup starten</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script src="assets/bootstrap/js/bootstrap.bundle.min.js"></script>
</body>
</html>
