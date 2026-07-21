<?php

declare(strict_types=1);

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
use DI\Container;
use Valitron\Validator;
use App\Url;
use App\Check;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
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

// Самописная функция для чтения Flash, которая не зависит от сторонних библиотек
function getFlashMessages(): array
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $messages = $_SESSION['my_flash'] ?? [];
    $_SESSION['my_flash'] = []; // Очищаем сессию сразу после чтения
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

        $_SESSION['my_flash'] = ['danger' => [$firstError]];
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
        $_SESSION['my_flash'] = ['info' => ['Страница уже существует']];
        $redirectUrl = $routeParser->urlFor('urls.show', ['id' => (string) $existingUrl['id']]);
        return $response->withHeader('Location', $redirectUrl)->withStatus(302);
    }

    $createdAt = \Carbon\Carbon::now()->toDateTimeString();
    $newId = Url::save($normalizedName, $createdAt);

    $_SESSION['my_flash'] = ['success' => ['Страница успешно добавлена']];
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
        $_SESSION['my_flash'] = ['danger' => ['Страница не найдена']];
        $redirectUrl = $routeParser->urlFor('urls.index');
        return $response->withHeader('Location', $redirectUrl)->withStatus(302);
    }

    $client = new Client([
        'allow_redirects' => true,
        'timeout' => 5,
        'connect_timeout' => 3,
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

        $_SESSION['my_flash'] = ['success' => ['Страница успешно проверена']];
    } catch (\GuzzleHttp\Exception\ConnectException $e) {
        $_SESSION['my_flash'] = ['danger' => ['Произошла ошибка при проверке, не удалось подключиться']];
    } catch (\GuzzleHttp\Exception\RequestException $e) {
        $_SESSION['my_flash'] = ['danger' => ['Произошла ошибка при проверке: сервер ответил с ошибкой']];
    } catch (\Exception $e) {
        $_SESSION['my_flash'] = ['danger' => ['Произошла непредвиденная ошибка при проверке']];
    }

    $redirectUrl = $routeParser->urlFor('urls.show', ['id' => (string) $id]);
    return $response->withHeader('Location', $redirectUrl)->withStatus(302);
})->setName('urls.checks.store');

$app->run();
