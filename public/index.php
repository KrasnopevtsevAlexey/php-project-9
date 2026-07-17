<?php

declare(strict_types=1);

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use Slim\Routing\RouteContext;
use Slim\Exception\HttpNotFoundException;
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

$app = AppFactory::create();
$app->addBodyParsingMiddleware();

$templatePath = __DIR__ . '/../templates';

function setFlash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function getFlash(): ?array
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $flash;
}

function render(Response $response, string $templatePath, string $layout, string $contentTemplate, array $data = []): Response
{
    extract($data);
    $flashMessages = getFlash();

    ob_start();
    require $contentTemplate;
    $content = ob_get_clean();

    ob_start();
    require $layout;
    $html = ob_get_clean();

    $response->getBody()->write($html);
    return $response->withHeader('Content-Type', 'text/html');
}

// Настройка кастомного обработчика ошибок
$errorMiddleware = $app->addErrorMiddleware(false, true, true);
$errorMiddleware->setDefaultErrorHandler(
    function (Request $request, Throwable $exception, bool $displayErrorDetails) use ($app, $templatePath) {
        $response = $app->getResponseFactory()->createResponse();
        $routeParser = RouteContext::fromRequest($request)->getRouteParser();

        $code = 500;
        $title = 'Внутренняя ошибка сервера';
        $message = 'Произошла непредвиденная ошибка. Мы уже работаем над её исправлением.';

        if ($exception instanceof HttpNotFoundException) {
            $code = 404;
            $title = 'Страница не найдена';
            $message = 'Запрашиваемый вами адрес или страница не существуют на нашем сервисе.';
        }

        $response = render(
            $response,
            $templatePath,
            $templatePath . '/layouts/main.php',
            $templatePath . '/error.php',
            [
                'code' => $code,
                'title' => $title,
                'message' => $message,
                'routeParser' => $routeParser
            ]
        );

        return $response->withStatus($code);
    }
);

// Главная страница
$app->get('/', function (Request $request, Response $response) use ($templatePath) {
    $url = $_SESSION['invalid_url'] ?? '';
    unset($_SESSION['invalid_url']);
    
    $routeParser = RouteContext::fromRequest($request)->getRouteParser();

    return render(
        $response,
        $templatePath,
        $templatePath . '/layouts/main.php',
        $templatePath . '/index.php',
        ['url' => $url, 'routeParser' => $routeParser]
    );
})->setName('home');

// Список сайтов
$app->get('/urls', function (Request $request, Response $response) use ($templatePath) {
    $urls = Url::findAll();
    $routeParser = RouteContext::fromRequest($request)->getRouteParser();

    return render(
        $response,
        $templatePath,
        $templatePath . '/layouts/main.php',
        $templatePath . '/urls/index.php',
        ['urls' => $urls, 'routeParser' => $routeParser]
    );
})->setName('urls.index');

// Просмотр конкретного сайта
$app->get('/urls/{id}', function (Request $request, Response $response, array $args) use ($templatePath) {
    $id = (int) $args['id'];
    $url = Url::findById($id);
    $routeParser = RouteContext::fromRequest($request)->getRouteParser();

    if (!$url) {
        // Отрендерим подшаблон ошибки внутри основного макета для несуществующего ID
        $response = render(
            $response,
            $templatePath,
            $templatePath . '/layouts/main.php',
            $templatePath . '/error.php',
            [
                'code' => 404,
                'title' => 'Сайт не найден',
                'message' => "Сайт с идентификатором ID {$id} отсутствует в базе данных.",
                'routeParser' => $routeParser
            ]
        );
        return $response->withStatus(404);
    }

    $checks = Check::findByUrlId($id);

    return render(
        $response,
        $templatePath,
        $templatePath . '/layouts/main.php',
        $templatePath . '/urls/show.php',
        ['url' => $url, 'checks' => $checks, 'routeParser' => $routeParser]
    );
})->setName('urls.show');

// Добавление сайта
$app->post('/urls', function (Request $request, Response $response) use ($templatePath) {
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
        setFlash('danger', $firstError);
        
        $response = render(
            $response,
            $templatePath,
            $templatePath . '/layouts/main.php',
            $templatePath . '/index.php',
            ['url' => $url, 'routeParser' => $routeParser]
        );

        return $response->withStatus(422);
    }

    $result = Url::save($url);

    if ($result && isset($result['id'])) {
        if (isset($result['is_new']) && $result['is_new'] === true) {
            setFlash('success', 'Страница успешно добавлена');
        } else {
            setFlash('info', 'Страница уже существует');
        }
        $redirectUrl = $routeParser->urlFor('urls.show', ['id' => (string) $result['id']]);
        return $response->withHeader('Location', $redirectUrl)->withStatus(302);
    }

    setFlash('danger', 'Ошибка при сохранении URL');
    $redirectUrl = $routeParser->urlFor('home');
    return $response->withHeader('Location', $redirectUrl)->withStatus(302);
})->setName('urls.store');

// Проверка сайта
$app->post('/urls/{id}/checks', function (Request $request, Response $response, array $args) {
    $id = (int) $args['id'];
    $url = Url::findById($id);
    $routeParser = RouteContext::fromRequest($request)->getRouteParser();

    if (!$url) {
        setFlash('danger', 'Страница не найдена');
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

        setFlash('success', 'Страница успешно проверена');
    } catch (RequestException | Exception $e) {
        setFlash('danger', 'Произошла ошибка при проверке, не удалось подключиться');
    }

    $redirectUrl = $routeParser->urlFor('urls.show', ['id' => (string) $id]);
    return $response->withHeader('Location', $redirectUrl)->withStatus(302);
})->setName('urls.checks.store');

$app->run();
