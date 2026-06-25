<div class="row justify-content-center">
    <div class="col-md-10 col-lg-8">
        <div class="card shadow-sm bg-light">
            <div class="card-body p-5">
                <div class="text-center mb-4">
                    <h1 class="display-5 mb-3">Анализатор страниц</h1>
                    <p class="lead text-muted">Бесплатная проверка SEO-параметров сайта</p>
                </div>
                
                <form action="/urls" method="post">
                    <div class="input-group input-group-lg">
                        <input 
                            type="url" 
                            name="url" 
                            class="form-control <?= isset($errors['url']) ? 'is-invalid' : '' ?>" 
                            placeholder="https://example.com" 
                            value="<?= htmlspecialchars($url ?? '') ?>"
                            required
                        >
                        <button type="submit" class="btn btn-primary">Проверить</button>
                    </div>
                    <?php if (isset($errors['url'])) : ?>
                        <div class="invalid-feedback d-block mt-2">
                            <?= htmlspecialchars($errors['url']) ?>
                        </div>
                    <?php endif; ?>
                </form>
                
                <div class="text-center text-muted small mt-4">
                    <p>Введите URL для анализа заголовков, мета-тегов и других SEO-параметров</p>
                </div>
            </div>
        </div>
    </div>
</div>
