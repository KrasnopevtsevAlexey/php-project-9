<?php

declare(strict_types=1);

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
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
$app->addErrorMiddleware(true, true, true);
$app->addBodyParsingMiddleware();

$templatePath = __DIR__ . '/../templates';

function setFlash($type, $message)
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
    
}

function getFlash()
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $flash;
}

function render($response, $templatePath, $layout, $contentTemplate, $data = [])
{
    extract($data);
    
    // Получаем flash-сообщение ДО рендеринга шаблона
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

$app->get('/', function (Request $request, Response $response) use ($templatePath) {
    return render(
        $response,
        $templatePath,
        $templatePath . '/layouts/main.php',
        $templatePath . '/index.php',
        ['url' => '', 'errors' => []]
    );
});

$app->get('/urls', function (Request $request, Response $response) use ($templatePath) {
    $urls = Url::findAll();

    return render(
        $response,
        $templatePath,
        $templatePath . '/layouts/main.php',
        $templatePath . '/urls/index.php',
        ['urls' => $urls]
    );
});

$app->get('/urls/{id}', function (Request $request, Response $response, $args) use ($templatePath) {
    $id = (int) $args['id'];
    $url = Url::findById($id);

    if (!$url) {
        $response->getBody()->write('Страница не найдена');
        return $response->withStatus(404);
    }

    $checks = Check::findByUrlId($id);

    return render(
        $response,
        $templatePath,
        $templatePath . '/layouts/main.php',
        $templatePath . '/urls/show.php',
        ['url' => $url, 'checks' => $checks]
    );
});

$app->post('/urls', function (Request $request, Response $response) {
    $data = $request->getParsedBody();
    $url = trim($data['url'] ?? '');

    $validator = new Validator(['url' => $url]);
    $validator->rule('required', 'url')->message('URL не должен быть пустым');
    $validator->rule('url', 'url')->message('Некорректный URL');
    $validator->rule('lengthMax', 'url', 255)->message('URL превышает 255 символов');

    if (!$validator->validate()) {
        $errors = $validator->errors();
        setFlash('error', reset($errors['url']));
        return $response->withHeader('Location', '/')->withStatus(422);
    }

    $result = Url::save($url);

    if ($result && isset($result['id'])) {
        if (isset($result['is_new']) && $result['is_new'] === true) {
            setFlash('success', 'Страница успешно добавлена');
        } else {
            setFlash('info', 'Страница уже существует');
        }
        return $response->withHeader('Location', '/urls/' . $result['id'])->withStatus(302);
    } else {
        setFlash('error', 'Ошибка при сохранении URL');
        return $response->withHeader('Location', '/')->withStatus(302);
    }
});

$app->post('/urls/{id}/checks', function (Request $request, Response $response, $args) {
    $id = (int) $args['id'];
    $url = Url::findById($id);

    if (!$url) {
        setFlash('error', 'Страница не найдена');
        return $response->withHeader('Location', '/urls')->withStatus(302);
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
    } catch (RequestException $e) {
        if ($e->hasResponse()) {
            setFlash('error', 'Ошибка проверки: HTTP ' . $e->getResponse()->getStatusCode());
        } else {
            setFlash('error', 'Произошла ошибка при проверке: Не удалось подключиться к серверу');
        }
    } catch (Exception $e) {
        setFlash('error', 'Произошла ошибка при проверке');
    }

    return $response->withHeader('Location', '/urls/' . $id)->withStatus(302);
});

$app->run();
