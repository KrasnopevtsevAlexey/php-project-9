<div class="row">
    <div class="col-12">
        <h1 class="mb-4">Сайты</h1>

        <table class="table table-striped" data-test="urls">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Имя</th>
                    <th>Дата создания</th>
                    <th>Код ответа</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($urls as $url) : ?>
                <tr>
                    <td><?= htmlspecialchars((string) $url['id']) ?></td>
                    <td>
                        <a href="<?= $routeParser->urlFor('urls.show', ['id' => (string) $url['id']]) ?>">
                            <?= htmlspecialchars($url['name']) ?>
                        </a>
                    </td>
                    <td><?= htmlspecialchars($url['created_at']) ?></td>
                    <td><?= htmlspecialchars((string) ($url['last_status_code'] ?? '')) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
