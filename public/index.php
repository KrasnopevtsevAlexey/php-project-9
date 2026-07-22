<?php

declare(strict_types=1);

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
use Slim\Flash\Messages;
use DI\Container;
use Valitron\Validator;
use App\Url;
use App\Check;
use GuzzleHttp\Client;
use Symfony\Component\DomCrawler\Crawler;

$container = new Container();

$container->set('flash', function () {
    return new Messages();
});

$container->set('renderer', function () {
    $renderer = new PhpRenderer(__DIR__ . '/../templates');
    $renderer->setLayout('layouts/main.php');
    return $renderer;
});

AppFactory::setContainer($container);
$app = AppFactory::create();
$app->addBodyParsingMiddleware();

// MIDDLEWARE СЕССИЙ: Игнорирует curl-запросы Caddy и фоновые утилиты, защищая куки от перезаписи
$app->add(function (Request $request, $handler) {
    $userAgent = $request->getHeaderLine('User-Agent');
    $uri = $request->getUri()->getPath();

    // Не стартуем сессию для curl-проверок Caddy и статических файлов
    $isCurl = str_contains(strtolower($userAgent), 'curl');
    $isAsset = str_ends_with($uri, '.css') || str_ends_with($uri, '.js') || str_ends_with($uri, '.ico');

    if (!$isCurl && !$isAsset && session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    return $handler->handle($request);
});

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

// Главная страница
$app->get('/', function (Request $request, Response $response) {
    $routeParser = RouteContext::fromRequest($request)->getRouteParser();
    $renderer = $this->get('renderer');
    $flash = $this->get('flash');

    $url = '';
    if (session_status() === PHP_SESSION_ACTIVE) {
        $url = $_SESSION['invalid_url'] ?? '';
        unset($_SESSION['invalid_url']);
    }

    // Извлекаем флеш безопасно
    $flashMessages = (session_status() === PHP_SESSION_ACTIVE) ? ($flash->getMessages() ?: []) : [];

    return $renderer->render($response, 'index.php', [
        'url' => $url,
        'routeParser' => $routeParser,
        'flashMessages' => $flashMessages
    ]);
})->setName('home');

// Список сайтов
$app->get('/urls', function (Request $request, Response $response) {
    $urls = Url::findAll();
    $routeParser = RouteContext::fromRequest($request)->getRouteParser();
    $renderer = $this->get('renderer');
    $flash = $this->get('flash');

    $flashMessages = (session_status() === PHP_SESSION_ACTIVE) ? ($flash->getMessages() ?: []) : [];

    return $renderer->render($response, 'urls/index.php', [
        'urls' => $urls,
        'routeParser' => $routeParser,
        'flashMessages' => $flashMessages
    ]);
})->setName('urls.index');

// Просмотр конкретного сайта
$app->get('/urls/{id:[0-9]+}', function (Request $request, Response $response, array $args) {
    $id = (int) $args['id'];
    $url = Url::findById($id);
    $routeParser = RouteContext::fromRequest($request)->getRouteParser();
    $renderer = $this->get('renderer');
    $flash = $this->get('flash');

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
    $flashMessages = (session_status() === PHP_SESSION_ACTIVE) ? ($flash->getMessages() ?: []) : [];

    return $renderer->render($response, 'urls/show.php', [
        'url' => $url,
        'checks' => $checks,
        'routeParser' => $routeParser,
        'flashMessages' => $flashMessages
    ]);
})->setName('urls.show');

// Добавление сайта
$app->post('/urls', function (Request $request, Response $response) {
    $data = $request->getParsedBody();
    $url = trim($data['url'] ?? '');

    $routeParser = RouteContext::fromRequest($request)->getRouteParser();
    $renderer = $this->get('renderer');
    $flash = $this->get('flash');

    $validator = new Validator(['url' => $url]);
    $validator->rule('required', 'url')->message('URL не должен быть пустым');
    $validator->rule('url', 'url')->message('Некорректный URL');
    $validator->rule('lengthMax', 'url', 255)->message('URL превышает 255 символов');

    if (!$validator->validate()) {
        $errors = $validator->errors();
        $firstError = array_shift($errors['url']);

        if (session_status() === PHP_SESSION_ACTIVE) {
            $flash->addMessage('danger', $firstError);
            $_SESSION['invalid_url'] = $url;
        }

        $flashMessages = (session_status() === PHP_SESSION_ACTIVE) ? ($flash->getMessages() ?: []) : [];

        return $renderer->render($response, 'index.php', [
            'url' => $url,
            'routeParser' => $routeParser,
            'flashMessages' => $flashMessages
        ])->withStatus(422);
    }

    $parsed = parse_url($url);
    $normalizedName = strtolower(($parsed['scheme'] ?? 'http') . '://' . ($parsed['host'] ?? ''));

    $existingUrl = Url::findByName($normalizedName);

    if ($existingUrl) {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $flash->addMessage('info', 'Страница уже существует');
        }
        $redirectUrl = $routeParser->urlFor('urls.show', ['id' => (string) $existingUrl['id']]);
        return $response->withHeader('Location', $redirectUrl)->withStatus(302);
    }

    $createdAt = \Carbon\Carbon::now()->toDateTimeString();
    $newId = Url::save($normalizedName, $createdAt);

    if (session_status() === PHP_SESSION_ACTIVE) {
        $flash->addMessage('success', 'Страница успешно добавлена');
    }

    $redirectUrl = $routeParser->urlFor('urls.show', ['id' => $newId]);
    return $response->withHeader('Location', $redirectUrl)->withStatus(302);
})->setName('urls.store');

// Проверка сайта
$app->post('/urls/{id:[0-9]+}/checks', function (Request $request, Response $response, array $args) {
    $id = (int) $args['id'];
    $url = Url::findById($id);

    $routeParser = RouteContext::fromRequest($request)->getRouteParser();
    $flash = $this->get('flash');

    if (!$url) {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $flash->addMessage('danger', 'Страница не найдена');
        }
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

        if (session_status() === PHP_SESSION_ACTIVE) {
            $flash->addMessage('success', 'Страница успешно проверена');
        }
    } catch (\Exception $e) {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $flash->addMessage('danger', 'Произошла ошибка при проверке, не удалось подключиться');
        }
    }

    $redirectUrl = $routeParser->urlFor('urls.show', ['id' => (string) $id]);
    return $response->withHeader('Location', $redirectUrl)->withStatus(302);
})->setName('urls.checks.store');

$app->run();
