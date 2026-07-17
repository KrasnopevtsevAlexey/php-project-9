<div class="row justify-content-center mt-5">
    <div class="col-md-8 col-lg-6 text-center">
        <div class="card shadow-sm bg-white p-5">
            <div class="card-body">
                <div class="display-1 text-danger fw-bold mb-4">
                    <i class="bi bi-exclamation-octagon-fill"></i> <?= htmlspecialchars((string) $code) ?>
                </div>
                <h2 class="h4 text-dark fw-bold mb-3"><?= htmlspecialchars($title) ?></h2>
                <p class="text-muted mb-4"><?= htmlspecialchars($message) ?></p>
                <a href="<?= $routeParser->urlFor('home') ?>" class="btn btn-primary btn-lg">
                    <i class="bi bi-house-door-fill me-2"></i> На главную
                </a>
            </div>
        </div>
    </div>
</div>
