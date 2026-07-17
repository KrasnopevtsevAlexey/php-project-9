<?php

declare(strict_types=1);

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

require __DIR__ . '/../vendor/autoload.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$container = new Container();

$container->set('flash', function () {
    return new Messages();
});

$container->set('renderer', function () {
    // Безопасно проверяем и разворачиваем таблицы ОДИН РАЗ при сборке контейнера фреймворка
    try {
        $pdo = \App\Connection::get();
        $pdo->query("SELECT 1 FROM urls LIMIT 1");
    } catch (\PDOException $e) {
        $driverName = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        if ($driverName === 'sqlite') {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS urls (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name VARCHAR(255) NOT NULL UNIQUE,
                    created_at DATETIME NOT NULL
                );
                CREATE TABLE IF NOT EXISTS url_checks (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    url_id INTEGER NOT NULL,
                    status_code INTEGER,
                    h1 VARCHAR(1000),
                    title VARCHAR(1000),
                    description VARCHAR(1000),
                    created_at DATETIME NOT NULL,
                    FOREIGN KEY (url_id) REFERENCES urls(id) ON DELETE CASCADE
                );
            ");
        } else {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS urls (
                    id SERIAL PRIMARY KEY,
                    name VARCHAR(255) NOT NULL UNIQUE,
                    created_at TIMESTAMP NOT NULL
                );
                CREATE TABLE IF NOT EXISTS url_checks (
                    id SERIAL PRIMARY KEY,
                    url_id INTEGER NOT NULL REFERENCES urls(id) ON DELETE CASCADE,
                    status_code INTEGER,
                    h1 VARCHAR(1000),
                    title VARCHAR(1000),
                    description VARCHAR(1000),
                    created_at TIMESTAMP NOT NULL
                );
            ");
        }
    }

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
            error_log(sprintf(' Error [%d]: %s in %s:%d', $code, $exception->getMessage(), $exception->getFile(), $exception->getLine()));
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
    $flash = $this->get('flash');

    // Получаем ранее сохраненный невалидный URL из флеш-данных пакета
    $invalidUrls = $flash->getMessage('invalid_url') ?? [];
    $url = array_shift($invalidUrls) ?? '';

    return $renderer->render($response, 'index.php', [
        'url' => $url,
        'routeParser' => $routeParser,
        'flashMessages' => $flash->getMessages()
    ]);
})->setName('home');

// Список сайтов
$app->get('/urls', function (Request $request, Response $response) {
    $urls = Url::findAll();
    $routeParser = RouteContext::fromRequest($request)->getRouteParser();
    $renderer = $this->get('renderer');
    $flash = $this->get('flash');

    return $renderer->render($response, 'urls/index.php', [
        'urls' => $urls,
        'routeParser' => $routeParser,
        'flashMessages' => $flash->getMessages()
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

    return $renderer->render($response, 'urls/show.php', [
        'url' => $url,
        'checks' => $checks,
        'routeParser' => $routeParser,
        'flashMessages' => $flash->getMessages()
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

        $flash->addMessage('danger', $firstError);

        $flash->addMessage('invalid_url', $url);

        $response = $renderer->render($response, 'index.php', [
            'url' => $url,
            'routeParser' => $routeParser,
            'flashMessages' => $flash->getMessages()
        ]);

        return $response->withStatus(422);
    }

    $result = Url::save($url);

    if ($result && isset($result['id'])) {
        if (isset($result['is_new']) && $result['is_new'] === true) {
            $flash->addMessage('success', 'Страница успешно добавлена');
        } else {
            $flash->addMessage('info', 'Страница уже существует');
        }

        $redirectUrl = $routeParser->urlFor('urls.show', ['id' => (string) $result['id']]);
        return $response->withHeader('Location', $redirectUrl)->withStatus(302);
    }

    $flash->addMessage('danger', 'Ошибка при сохранении URL');
    $redirectUrl = $routeParser->urlFor('home');
    return $response->withHeader('Location', $redirectUrl)->withStatus(302);
})->setName('urls.store');

// Проверка сайта
$app->post('/urls/{id:[0-9]+}/checks', function (Request $request, Response $response, array $args) {
    $id = (int) $args['id'];
    $url = Url::findById($id);

    $routeParser = RouteContext::fromRequest($request)->getRouteParser();
    $flash = $this->get('flash');

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

        // Выносим работу со временем и нормализацию пустых тегов в слой контроллера
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
        // Ловим ошибку сети, когда сайта вообще не существует или он недоступен
        $flash->addMessage('danger', 'Произошла ошибка при проверке, не удалось подключиться');
    } catch (\GuzzleHttp\Exception\RequestException $e) {
        // Ловим ошибку, когда сервер ответил, но вернул плохой статус (404, 500 и т.д.)
        $flash->addMessage('danger', 'Произошла ошибка при проверке: сервер ответил с ошибкой');
    } catch (Exception $e) {
        // Для всех остальных непредвиденных исключений
        $flash->addMessage('danger', 'Произошла непредвиденная ошибка при проверке');
    }

    $redirectUrl = $routeParser->urlFor('urls.show', ['id' => (string) $id]);
    return $response->withHeader('Location', $redirectUrl)->withStatus(302);
})->setName('urls.checks.store');


$app->run();
