<?php

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require __DIR__ . '/../vendor/autoload.php';

// Фронт-контроллерный роутинг статики встроенного PHP-сервера
if (PHP_SAPI === 'cli-server') {
    $url = parse_url($_SERVER['REQUEST_URI']);
    $file = __DIR__ . ($url['path'] ?? '');
    if (is_file($file)) {
        return false;
    }
}

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use Slim\Routing\RouteContext;
use Slim\Exception\HttpNotFoundException;
use Slim\Views\PhpRenderer;
use DI\Container;
use Valitron\Validator;
use App\Url;
use App\Check;
use GuzzleHttp\Client;
use Symfony\Component\DomCrawler\Crawler;

$container = new Container();

$container->set('renderer', function () {
    $renderer = new PhpRenderer(__DIR__ . '/../templates');
    $renderer->setLayout('layouts/main.php');
    return $renderer;
});

AppFactory::setContainer($container);
$app = AppFactory::create();
$app->addBodyParsingMiddleware();

// Кастомный обработчик ошибок
$errorMiddleware = $app->addErrorMiddleware(false, true, true);
$errorMiddleware->setDefaultErrorHandler(
    function (Request $request, Throwable $exception, bool $displayErrorDetails) use ($app) {
        $response = $app->getResponseFactory()->createResponse();
        $routeParser = $app->getRouteCollector()->getRouteParser();
        $renderer = $app->getContainer()->get('renderer');

        $code = 500;
        return $renderer->render($response, 'error.php', [
            'code' => $code,
            'title' => 'Внутренняя ошибка сервера',
            'message' => $exception->getMessage(),
            'routeParser' => $routeParser,
            'flashMessages' => []
        ])->withStatus($code);
    }
);

// Функция атомарного чтения Flash, устойчивая к параллельным микрозапросам
function getFlashMessages(): array
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $messages = $_SESSION['flash_messages'] ?? [];
    $_SESSION['flash_messages'] = []; // Безопасное ручное обнуление
    return $messages;
}

// Главная страница
$app->get('/', function (Request $request, Response $response) {
    $routeParser = RouteContext::fromRequest($request)->getRouteParser();
    $renderer = $this->get('renderer');

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $url = $_SESSION['invalid_url'] ?? '';
    unset($_SESSION['invalid_url']);

    return $renderer->render($response, 'index.php', [
        'url' => $url,
        'routeParser' => $routeParser,
        'flashMessages' => getFlashMessages()
    ]);
})->setName('home');

// Список сайтов
$app->get('/urls', function (Request $request, Response $response) {
    $urls = Url::findAll();
    $routeParser = RouteContext::fromRequest($request)->getRouteParser();
    $renderer = $this->get('renderer');

    return $renderer->render($response, 'urls/index.php', [
        'urls' => $urls,
        'routeParser' => $routeParser,
        'flashMessages' => getFlashMessages()
    ]);
})->setName('urls.index');

// Просмотр конкретного сайта
$app->get('/urls/{id:[0-9]+}', function (Request $request, Response $response, array $args) {
    $id = (int) $args['id'];
    $url = Url::findById($id);
    $routeParser = RouteContext::fromRequest($request)->getRouteParser();
    $renderer = $this->get('renderer');

    if (!$url) {
        return $renderer->render($response, 'error.php', [
            'code' => 404,
            'title' => 'Сайт не найден',
            'message' => "Сайт с идентификатором ID {$id} отсутствует в базе данных.",
            'routeParser' => $routeParser,
            'flashMessages' => []
        ])->withStatus(404);
    }

    $checks = Check::findByUrlId($id);

    return $renderer->render($response, 'urls/show.php', [
        'url' => $url,
        'checks' => $checks,
        'routeParser' => $routeParser,
        'flashMessages' => getFlashMessages()
    ]);
})->setName('urls.show');

// Добавление сайта
$app->post('/urls', function (Request $request, Response $response) {
    $data = $request->getParsedBody();
    $url = trim($data['url'] ?? '');

    $routeParser = RouteContext::fromRequest($request)->getRouteParser();
    $renderer = $this->get('renderer');

    $validator = new Validator(['url' => $url]);
    $validator->rule('required', 'url')->message('URL не должен быть пустым');
    $validator->rule('url', 'url')->message('Некорректный URL');
    $validator->rule('lengthMax', 'url', 255)->message('URL превышает 255 символов');

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (!$validator->validate()) {
        $errors = $validator->errors();
        $firstError = array_shift($errors['url']);

        $_SESSION['flash_messages'] = ['danger' => [$firstError]];
        $_SESSION['invalid_url'] = $url;

        return $renderer->render($response, 'index.php', [
            'url' => $url,
            'routeParser' => $routeParser,
            'flashMessages' => getFlashMessages()
        ])->withStatus(422);
    }

    $parsed = parse_url($url);
    $normalizedName = strtolower(($parsed['scheme'] ?? 'http') . '://' . ($parsed['host'] ?? ''));

    $existingUrl = Url::findByName($normalizedName);

    if ($existingUrl) {
        $_SESSION['flash_messages'] = ['info' => ['Страница уже существует']];
        $redirectUrl = $routeParser->urlFor('urls.show', ['id' => (string) $existingUrl['id']]);
        return $response->withHeader('Location', $redirectUrl)->withStatus(302);
    }

    $createdAt = \Carbon\Carbon::now()->toDateTimeString();
    $newId = Url::save($normalizedName, $createdAt);

    $_SESSION['flash_messages'] = ['success' => ['Страница успешно добавлена']];
    $redirectUrl = $routeParser->urlFor('urls.show', ['id' => $newId]);
    return $response->withHeader('Location', $redirectUrl)->withStatus(302);
})->setName('urls.store');

// Проверка сайта
$app->post('/urls/{id:[0-9]+}/checks', function (Request $request, Response $response, array $args) use ($app) {
    $id = (int) $args['id'];
    $url = Url::findById($id);

    $routeParser = $app->getRouteCollector()->getRouteParser();

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (!$url) {
        $_SESSION['flash_messages'] = ['danger' => ['Страница не найдена']];
        $redirectUrl = $routeParser->urlFor('urls.index');
        return $response->withHeader('Location', $redirectUrl)->withStatus(302);
    }

    $client = new Client(['timeout' => 4, 'connect_timeout' => 2, 'verify' => false]);

    try {
        $responseHttp = $client->get($url['name']);
        $statusCode = $responseHttp->getStatusCode();
        $html = (string) $responseHttp->getBody();

        $crawler = new Crawler($html);
        $title = $crawler->filter('title')->count() > 0 ? trim($crawler->filter('title')->first()->text()) : null;
        $h1 = $crawler->filter('h1')->count() > 0 ? trim($crawler->filter('h1')->first()->text()) : null;
        $description = $crawler->filter('meta[name="description"]')->count() > 0 ? trim($crawler->filter('meta[name="description"]')->first()->attr('content') ?? '') : null;

        Check::save($id, [
            'status_code' => $statusCode,
            'h1' => !empty($h1) ? $h1 : null,
            'title' => !empty($title) ? $title : null,
            'description' => !empty($description) ? $description : null,
            'created_at' => \Carbon\Carbon::now()->toDateTimeString()
        ]);

        $_SESSION['flash_messages'] = ['success' => ['Страница успешно проверена']];
    } catch (\Exception $e) {
        $_SESSION['flash_messages'] = ['danger' => ['Произошла ошибка при проверке, не удалось подключиться']];
    }

    $redirectUrl = $routeParser->urlFor('urls.show', ['id' => (string) $id]);
    return $response->withHeader('Location', $redirectUrl)->withStatus(302);
})->setName('urls.checks.store');

$app->run();
