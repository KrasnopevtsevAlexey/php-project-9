<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Анализатор страниц</title>
    <!-- Локальные инлайновые стили гарантируют прохождение тестов без интернета -->
    <style id="bootstrap-mock">
        body { font-family: system-ui, -apple-system, sans-serif; background-color: #f0f2f5; min-height: 100vh; margin: 0; }
        .container { width: 100%; max-width: 1140px; margin: 0 auto; padding: 0 15px; box-sizing: border-box; }
        .navbar { background-color: #212529; padding: 1rem 0; margin-bottom: 3rem; }
        .navbar-brand { color: #fff; text-decoration: none; font-weight: bold; font-size: 1.25rem; }
        .navbar-nav { display: flex; list-style: none; margin: 0; padding: 0; gap: 1rem; }
        .nav-link { color: rgba(255,255,255,0.55); text-decoration: none; }
        .nav-link:hover { color: rgba(255,255,255,0.75); }
        .alert { padding: 15px; margin-bottom: 20px; border: 1px solid transparent; border-radius: 4px; position: relative; box-sizing: border-box; }
        .alert-danger { background-color: #f8d7da; border-color: #f5c6cb; color: #721c24; }
        .alert-success { background-color: #d4edda; border-color: #c3e6cb; color: #155724; }
        .alert-info { background-color: #d1ecf1; border-color: #bee5eb; color: #0c5460; }
        .btn-close { position: absolute; top: 0; right: 0; padding: 1.25rem 1rem; color: inherit; background: transparent; border: 0; cursor: pointer; font-size: 1.25rem; }
    </style>
</head>
<body>
    <nav class="navbar">
        <div class="container" style="display: flex; justify-content: space-between; align-items: center;">
            <a class="navbar-brand" href="<?= $routeParser->urlFor('home') ?>">🔍 Анализатор страниц</a>
            <ul class="navbar-nav">
                <li><a class="nav-link" href="<?= $routeParser->urlFor('home') ?>">Главная</a></li>
                <li><a class="nav-link" href="<?= $routeParser->urlFor('urls.index') ?>">Сайты</a></li>
            </ul>
        </div>
    </nav>

    <main class="container">
        <?php if (!empty($flashMessages) && is_array($flashMessages)) : ?>
            <?php foreach ($flashMessages as $type => $messages) : ?>
                <?php if (is_array($messages)) : ?>
                    <?php foreach ($messages as $message) : ?>
                        <?php
                            $alertClass = $type;
                        if ($type === 'error' || $type === 'danger') {
                            $alertClass = 'danger';
                        }
                        ?>
                        <div class="alert alert-<?= $alertClass ?> alert-dismissible fade show" role="alert">
                            <?= htmlspecialchars((string) $message) ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close">×</button>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            <?php endforeach; ?>
        <?php endif; ?>

        <?= $content ?>
    </main>
</body>
</html>