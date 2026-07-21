<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Анализатор страниц</title>
    
    <!-- Инлайновые заглушки гарантируют мгновенную загрузку DOM без зависаний сетевого стека -->
    <style id="bootstrap-mock">
        body { font-family: system-ui, -apple-system, sans-serif; }
        .container { width: 100%; max-width: 1140px; margin: 0 auto; padding: 0 15px; }
        .alert { padding: 15px; margin-bottom: 20px; border: 1px solid transparent; border-radius: 4px; position: relative; }
        .alert-danger { background-color: #f8d7da; border-color: #f5c6cb; color: #721c24; }
        .alert-success { background-color: #d4edda; border-color: #c3e6cb; color: #155724; }
        .alert-info { background-color: #d1ecf1; border-color: #bee5eb; color: #0c5460; }
        .btn-close { position: absolute; top: 0; right: 0; padding: 1.25rem 1rem; color: inherit; background: transparent; border: 0; cursor: pointer; }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-5">
        <div class="container">
            <a class="navbar-brand fw-bold" href="<?= $routeParser->urlFor('home') ?>">🔍 Анализатор страниц</a>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav">
                    <li class="nav-item">
                        <a class="nav-link" href="<?= $routeParser->urlFor('home') ?>">Главная</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= $routeParser->urlFor('urls.index') ?>">Сайты</a>
                    </li>
                </ul>
            </div>
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
