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
use Exception;
use Symfony\Component\DomCrawler\Crawler;

require __DIR__ . '/../vendor/autoload.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Создаем DI-контейнер и регистрируем системные компоненты
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
    $url = $_SESSION['invalid_url'] ?? '';
    unset($_SESSION['invalid_url']);

    $routeParser = RouteContext::fromRequest($request)->getRouteParser();
    $renderer = $this->get('renderer');
    $flash = $this->get('flash');

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
$app->get('/urls/{id}', function (Request $request, Response $response, array $args) {
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
$app->post('/urls/{id}/checks', function (Request $request, Response $response, array $args) {
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

        Check::save($id, [
            'status_code' => $statusCode,
            'h1' => $h1,
            'title' => $title,
            'description' => $description,
        ]);

        $flash->addMessage('success', 'Страница успешно проверена');
    } catch (RequestException | Exception $e) {
        $flash->addMessage('danger', 'Произошла ошибка при проверке, не удалось подключиться');
    }

    $redirectUrl = $routeParser->urlFor('urls.show', ['id' => (string) $id]);
    return $response->withHeader('Location', $redirectUrl)->withStatus(302);
})->setName('urls.checks.store');

$app->run();
