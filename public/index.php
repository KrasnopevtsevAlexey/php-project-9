<?php

declare(strict_types=1);

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

// Главная страница
$app->get('/', function (Request $request, Response $response) {
    $routeParser = RouteContext::fromRequest($request)->getRouteParser();
    $renderer = $this->get('renderer');
    $queryParams = $request->getQueryParams();

    $flashMessages = [];
    if (isset($queryParams['error'])) {
        $flashMessages['danger'] = [base64_decode($queryParams['error'])];
    }

    return $renderer->render($response, 'index.php', [
        'url' => $queryParams['url'] ?? '',
        'routeParser' => $routeParser,
        'flashMessages' => $flashMessages
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
        'flashMessages' => []
    ]);
})->setName('urls.index');

// Просмотр конкретного сайта
$app->get('/urls/{id:[0-9]+}', function (Request $request, Response $response, array $args) {
    $id = (int) $args['id'];
    $url = Url::findById($id);
    $routeParser = RouteContext::fromRequest($request)->getRouteParser();
    $renderer = $this->get('renderer');
    $queryParams = $request->getQueryParams();

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

    // СБОРКА АЛЕРТОВ ИЗ URL-ПАРАМЕТРОВ (Замена сессиям)
    $flashMessages = [];
    if (isset($queryParams['success'])) {
        $flashMessages['success'] = ['Страница успешно добавлена'];
    } elseif (isset($queryParams['exists'])) {
        $flashMessages['info'] = ['Страница уже существует'];
    } elseif (isset($queryParams['checked'])) {
        $flashMessages['success'] = ['Страница успешно проверена'];
    } elseif (isset($queryParams['check_error'])) {
        $flashMessages['danger'] = [base64_decode($queryParams['check_error'])];
    }

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

    $validator = new Validator(['url' => $url]);
    $validator->rule('required', 'url')->message('URL не должен быть пустым');
    $validator->rule('url', 'url')->message('Некорректный URL');
    $validator->rule('lengthMax', 'url', 255)->message('URL превышает 255 символов');

    if (!$validator->validate()) {
        $errors = $validator->errors();
        $firstError = array_shift($errors['url']);

        // При ошибке валидации перенаправляем на GET / с параметром ошибки
        $redirectUrl = $routeParser->urlFor('home') . '?error=' . base64_encode($firstError) . '&url=' . urlencode($url);
        return $response->withHeader('Location', $redirectUrl)->withStatus(302);
    }

    $parsed = parse_url($url);
    $normalizedName = strtolower(($parsed['scheme'] ?? 'http') . '://' . ($parsed['host'] ?? ''));

    $existingUrl = Url::findByName($normalizedName);

    if ($existingUrl) {
        $redirectUrl = $routeParser->urlFor('urls.show', ['id' => (string) $existingUrl['id']]) . '?exists=1';
        return $response->withHeader('Location', $redirectUrl)->withStatus(302);
    }

    $createdAt = \Carbon\Carbon::now()->toDateTimeString();
    $newId = Url::save($normalizedName, $createdAt);

    // Добавляем флаг ?success=1 прямо в URL редиректа!
    $redirectUrl = $routeParser->urlFor('urls.show', ['id' => $newId]) . '?success=1';
    return $response->withHeader('Location', $redirectUrl)->withStatus(302);
})->setName('urls.store');

// Проверка сайта
$app->post('/urls/{id:[0-9]+}/checks', function (Request $request, Response $response, array $args) use ($app) {
    $id = (int) $args['id'];
    $url = Url::findById($id);

    $routeParser = $app->getRouteCollector()->getRouteParser();

    if (!$url) {
        $redirectUrl = $routeParser->urlFor('urls.index');
        return $response->withHeader('Location', $redirectUrl)->withStatus(302);
    }

    $client = new Client([
        'allow_redirects' => true,
        'timeout' => 5,
        'connect_timeout' => 3,
        'verify' => false,
    ]);

    $checkParam = 'checked=1';

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
    } catch (\GuzzleHttp\Exception\ConnectException $e) {
        $checkParam = 'check_error=' . base64_encode('Произошла ошибка при проверке, не удалось подключиться');
    } catch (\GuzzleHttp\Exception\RequestException $e) {
        $checkParam = 'check_error=' . base64_encode('Произошла ошибка при проверке: сервер ответил с ошибкой');
    } catch (\Exception $e) {
        $checkParam = 'check_error=' . base64_encode('Произошла непредвиденная ошибка при проверке');
    }

    $redirectUrl = $routeParser->urlFor('urls.show', ['id' => (string) $id]) . '?' . $checkParam;
    return $response->withHeader('Location', $redirectUrl)->withStatus(302);
})->setName('urls.checks.store');

$app->run();
