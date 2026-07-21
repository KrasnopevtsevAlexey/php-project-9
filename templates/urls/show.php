<?php
<  ? php
if (!function_exists('truncate')) {
    function truncate(?string $text, int $length = 200): string
    {
        if (empty($text)) {
            return '';
        }
        if (mb_strlen($text) <= $length) {
            return $text;
        }
        return mb_substr($text, 0, $length) . '...';
    }
}
?>

<div class="row">
    <div class="col-12">
        <h1 class="mb-4">Сайт: <?= htmlspecialchars($url['name']) ?></h1>

        <table class="table table-bordered" data-test="url">
            <tbody>
                <tr>
                    <th style="width: 200px">ID</th>
                    <td><?= htmlspecialchars((string) $url['id']) ?></td>
                </tr>
                <tr>
                    <th>Имя</th>
                    <td><?= htmlspecialchars($url['name']) ?></td>
                </tr>
                <tr>
                    <th>Дата создания</th>
                    <td><?= htmlspecialchars($url['created_at']) ?></td>
                </tr>
            </tbody>
        </table>

        <h2 class="mt-5 mb-3">Проверки</h2>

        <form action="<?= $routeParser->urlFor('urls.checks.store', ['id' => (string) $url['id']]) ?>" method="post" class="mb-4">
            <button type="submit" class="btn btn-primary">Запустить проверку</button>
        </form>

        <table class="table table-striped" data-test="checks">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Код ответа</th>
                    <th>h1</th>
                    <th>title</th>
                    <th>description</th>
                    <th>Дата создания</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($checks)) {
                    : ;
                } ?>
                <tr>
                    <td colspan="6" class="text-center">Проверок пока нет</td>
                </tr>
                <?php else : ?>
                    <?php foreach ($checks as $check) : ?>
                    <tr>
                        <td><?= htmlspecialchars((string) ($check['id'] ?? '')) ?></td>
                        <td><?= htmlspecialchars((string) ($check['status_code'] ?? '')) ?></td>
                        <td><?= htmlspecialchars(truncate($check['h1'] ?? '')) ?></td>
                        <td><?= htmlspecialchars(truncate($check['title'] ?? '')) ?></td>
                        <td><?= htmlspecialchars(truncate($check['description'] ?? '')) ?></td>
                        <td><?= htmlspecialchars($check['created_at'] ?? '') ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
