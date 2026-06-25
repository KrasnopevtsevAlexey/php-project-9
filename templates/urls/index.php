<div class="row">
    <div class="col-12">
        <h1 class="mb-4">Сайты</h1>
        
        <?php if (isset($flashMessages) && $flashMessages) : ?>
            <div class="alert alert-<?= $flashMessages['type'] === 'error' ? 'danger' : $flashMessages['type'] ?> 
            alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($flashMessages['message']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        
        <table class="table table-striped" data-test="urls">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Имя</th>
                    <th>Дата создания</th>
                    <th>Последняя проверка</th>
                    <th>Код ответа</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($urls as $url) : ?>
                <tr>
                    <td><?= htmlspecialchars($url['id']) ?></td>
                    <td><a href="/urls/<?= $url['id'] ?>"><?= htmlspecialchars($url['name']) ?></a></td>
                    <td><?= htmlspecialchars($url['created_at']) ?></td>
                    <td><?= htmlspecialchars($url['last_check_date'] ?? '') ?></td>
                    <td><?= htmlspecialchars($url['last_status_code'] ?? '') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
