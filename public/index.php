<?php

declare(strict_types=1);

// СТРОГО ПЕРВАЯ СТРОКА: Инициализируем сессию до загрузки зависимостей фреймворка
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require __DIR__ . '/../vendor/autoload.php';

// Фронт-контроллерный роутинг статики для встроенного PHP-сервера
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
use GuzzleHttp\Exception\RequestException;
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

// Кастомный обработчик ошибок
$errorMiddleware = $app->addErrorMiddleware(false, true, true);
$errorMiddleware->setDefaultErrorHandler(
    function (Request $request, Throwable $exception, bool $displayErrorDetails) use ($app) {
        $response = $app->getResponseFactory()->createResponse();
        $routeParser = RouteContext::fromRequest($request)->getRouteParser();
        $renderer = $app->getContainer()->get('renderer');

        $code = 500;
        $title = 'Внутренняя ошибка сервера';
        $message = 'Произошла непредвиденная ошибка. Мы уже работаем над её исправлением.';

        if ($exception instanceof HttpNotFoundException) {
            $code = 404;
            $title = 'Страница не найдена';
            $message = 'Запрашиваемый вами адрес или страница не существуют на нашем сервисе.';
        } else {
            error_log(sprintf(
                ' Error [%d]: %s in %s:%d',
                $code,
                $exception->getMessage(),
                $exception->getFile(),
                $exception->getLine()
            ));
        }

        return $renderer->render($response, 'error.php', [
            'code' => $code,
            'title' => $title,
            'message' => $message,
            'routeParser' => $routeParser,
            'flashMessages' => []
        ])->withStatus($code);
    }
);

// Главная страница
$app->get('/', function (Request $request, Response $response) use ($app) {
    $routeParser = RouteContext::fromRequest($request)->getRouteParser();
    $container = $app->getContainer();
    $renderer = $container->get('renderer');
    $flash = $container->get('flash');

    $url = $_SESSION['invalid_url'] ?? '';
    unset($_SESSION['invalid_url']);

    $renderer->addAttribute('flashMessages', $flash->getMessages() ?: []);

    return $renderer->render($response, 'index.php', [
        'url' => $url,
        'routeParser' => $routeParser
    ]);
})->setName('home');

// Список сайтов
$app->get('/urls', function (Request $request, Response $response) use ($app) {
    $urls = Url::findAll();
    $routeParser = RouteContext::fromRequest($request)->getRouteParser();
    $container = $app->getContainer();
    $renderer = $container->get('renderer');
    $flash = $container->get('flash');

    $renderer->addAttribute('flashMessages', $flash->getMessages() ?: []);

    return $renderer->render($response, 'urls/index.php', [
        'urls' => $urls,
        'routeParser' => $routeParser
    ]);
})->setName('urls.index');

// Просмотр конкретного сайта
$app->get('/urls/{id:[0-9]+}', function (Request $request, Response $response, array $args) use ($app) {
    $id = (int) $args['id'];
    $url = Url::findById($id);
    $routeParser = RouteContext::fromRequest($request)->getRouteParser();
    $container = $app->getContainer();
    $renderer = $container->get('renderer');
    $flash = $container->get('flash');

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

    $renderer->addAttribute('flashMessages', $flash->getMessages() ?: []);

    return $renderer->render($response, 'urls/show.php', [
        'url' => $url,
        'checks' => $checks,
        'routeParser' => $routeParser
    ]);
})->setName('urls.show');

// Добавление сайта
$app->post('/urls', function (Request $request, Response $response) use ($app) {
    $data = $request->getParsedBody();
    $url = trim($data['url'] ?? '');

    $routeParser = RouteContext::fromRequest($request)->getRouteParser();
    $container = $app->getContainer();
    $renderer = $container->get('renderer');
    $flash = $container->get('flash');

    $validator = new Validator(['url' => $url]);
    $validator->rule('required', 'url')->message('URL не должен быть пустым');
    $validator->rule('url', 'url')->message('Некорректный URL');
    $validator->rule('lengthMax', 'url', 255)->message('URL превышает 255 символов');

    if (!$validator->validate()) {
        $errors = $validator->errors();
        $firstError = array_shift($errors['url']);

        $flash->addMessage('danger', $firstError);
        $_SESSION['invalid_url'] = $url;

        $renderer->addAttribute('flashMessages', $flash->getMessages() ?: []);

        $response = $renderer->render($response, 'index.php', [
            'url' => $url,
            'routeParser' => $routeParser
        ]);

        return $response->withStatus(422);
    }

    $parsed = parse_url($url);
    $normalizedName = strtolower(($parsed['scheme'] ?? 'http') . '://' . ($parsed['host'] ?? ''));

    $existingUrl = Url::findByName($normalizedName);

    if ($existingUrl) {
        $flash->addMessage('info', 'Страница уже существует');
        $redirectUrl = $routeParser->urlFor('urls.show', ['id' => (string) $existingUrl['id']]);
        return $response->withHeader('Location', $redirectUrl)->withStatus(302);
    }

    $createdAt = \Carbon\Carbon::now()->toDateTimeString();
    $newId = Url::save($normalizedName, $createdAt);

    $flash->addMessage('success', 'Страница успешно добавлена');
    $redirectUrl = $routeParser->urlFor('urls.show', ['id' => $newId]);
    return $response->withHeader('Location', $redirectUrl)->withStatus(302);
})->setName('urls.store');

// Проверка сайта
$app->post('/urls/{id:[0-9]+}/checks', function (Request $request, Response $response, array $args) use ($app) {
    $id = (int) $args['id'];
    $url = Url::findById($id);

    $routeParser = RouteContext::fromRequest($request)->getRouteParser();
    $container = $app->getContainer();
    $flash = $container->get('flash');

    if (!$url) {
        $flash->addMessage('danger', 'Страница не найдена');
        $redirectUrl = $routeParser->urlFor('urls.index');
        return $response->withHeader('Location', $redirectUrl)->withStatus(302);
    }

    $client = new Client([
        'allow_redirects' => true,
        'timeout' => 30,
        'connect_timeout' => 10,
        'verify' => false,
    ]);

    try {
        $responseHttp = $client->get($url['name']);
        $statusCode = $responseHttp->getStatusCode();
        $html = (string) $responseHttp->getBody();

        $crawler = new Crawler($html);

        $title = '';
        $titleNode = $crawler->filter('title')->first();
        if ($titleNode->count() > 0) {
            $title = trim($titleNode->text());
        }

        $h1 = '';
        $h1Node = $crawler->filter('h1')->first();
        if ($h1Node->count() > 0) {
            $h1 = trim($h1Node->text());
        }

        $description = '';
        $metaNode = $crawler->filter('meta[name="description"]')->first();
        if ($metaNode->count() > 0) {
            $description = trim($metaNode->attr('content') ?? '');
        }

        $createdAt = \Carbon\Carbon::now()->toDateTimeString();

        Check::save($id, [
            'status_code' => $statusCode,
            'h1' => !empty($h1) ? $h1 : null,
            'title' => !empty($title) ? $title : null,
            'description' => !empty($description) ? $description : null,
            'created_at' => $createdAt
        ]);

        $flash->addMessage('success', 'Страница успешно проверена');
    } catch (\GuzzleHttp\Exception\ConnectException $e) {
        $flash->addMessage('danger', 'Произошла ошибка при проверке, не удалось подключиться');
    } catch (\GuzzleHttp\Exception\RequestException $e) {
        $flash->addMessage('danger', 'Произошла ошибка при проверке: сервер ответил с ошибкой');
    } catch (Exception $e) {
        $flash->addMessage('danger', 'Произошла непредвиденная ошибка при проверке');
    }

    $redirectUrl = $routeParser->urlFor('urls.show', ['id' => (string) $id]);
    return $response->withHeader('Location', $redirectUrl)->withStatus(302);
})->setName('urls.checks.store');

$app->run();
