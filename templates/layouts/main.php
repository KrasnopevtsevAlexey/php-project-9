<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Анализатор страниц</title>
    <link href="https://jsdelivr.net" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css">
    <style>
        body {
            background-color: #f0f2f5;
            min-height: 100vh;
        }
        .card {
            border: none;
            border-radius: 15px;
        }
        .alert-danger {
            background-color: #ffe6e6;
            border-color: #ff9999;
            color: #cc0000;
            border-left: 4px solid #ff0000;
        }
        .alert-success {
            background-color: #d4edda;
            border-color: #c3e6cb;
            color: #155724;
        }
        .alert-info {
            background-color: #d1ecf1;
            border-color: #bee5eb;
            color: #0c5460;
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-5">
        <div class="container">
            <a class="navbar-brand fw-bold" href="/">🔍 Анализатор страниц</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav">
                    <li class="nav-item">
                        <a class="nav-link" href="/">Главная</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="/urls">Сайты</a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <main class="container">
        <?php if (isset($flashMessages) && $flashMessages) : ?>
            <div class="alert alert-<?= $flashMessages['type'] === 'error' ? 'danger' : $flashMessages['type'] ?> alert-dismissible fade show" role="alert">
                <?php if ($flashMessages['type'] === 'error') : ?>
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                <?php elseif ($flashMessages['type'] === 'success') : ?>
                    <i class="bi bi-check-circle-fill me-2"></i>
                <?php endif; ?>
                <?= htmlspecialchars($flashMessages['message']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <?= $content ?>
    </main>

    <script src="https://jsdelivr.net"></script>
</body>
</html>
