<div class="row justify-content-center">
    <div class="col-md-8 col-lg-6">
        <div class="card shadow-sm bg-light">
            <div class="card-body p-5">
                <div class="text-center mb-4">
                    <h1 class="display-5 mb-3">Анализатор страниц</h1>
                    <p class="lead text-muted">Бесплатная проверка SEO-параметров сайта</p>
                </div>

                <form action="<?= $routeParser->urlFor('urls.store') ?>" method="post">
                    <div class="input-group input-group-lg">
                        <input
                            type="url"
                            name="url"
                            class="form-control"
                            placeholder="https://example.com"
                            value="<?= htmlspecialchars($url ?? '') ?>"
                            required
                        >
                        <button type="submit" class="btn btn-primary">Проверить</button>
                    </div>
                </form>

                <div class="text-center text-muted small mt-4">
                    <p>Введите URL для анализа заголовков, мета-тегов и других SEO-параметров</p>
                </div>
            </div>
        </div>
    </div>
</div>
