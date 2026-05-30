<div class="row">
    <div class="col-md-8 mx-auto">
        <h1 class="display-4 text-center mb-4">Анализатор страниц</h1>
        <p class="text-center mb-4">Проверьте SEO-параметры любого сайта</p>
        
        <form action="/urls" method="post" class="mb-3">
            <div class="input-group">
                <input 
                    type="url" 
                    name="url" 
                    class="form-control form-control-lg <?= isset($errors['url']) ? 'is-invalid' : '' ?>" 
                    placeholder="https://example.com" 
                    value="<?= htmlspecialchars($url ?? '') ?>"
                    required
                >
                <button type="submit" class="btn btn-primary btn-lg">Проверить</button>
            </div>
            <?php if (isset($errors['url'])): ?>
                <div class="invalid-feedback d-block">
                    <?= htmlspecialchars($errors['url']) ?>
                </div>
            <?php endif; ?>
        </form>
    </div>
</div>
