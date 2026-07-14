<?php

declare(strict_types=1);

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use Slim\Flash\Messages;
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

$app->add(function ($request, $handler) {
    $flash = new Messages();
    $request = $request->withAttribute('flash', $flash);
    return $handler->handle($request);
});

$app->addErrorMiddleware(true, true, true);
$app->addBodyParsingMiddleware();

$templatePath = __DIR__ . '/../templates';

function render($response, $templatePath, $layout, $contentTemplate, $data = [], $flash = null)
{
    extract($data);
    ob_start();
    require $contentTemplate;
    $content = ob_get_clean();

    $flashMessages = null;
    if ($flash) {
        $messages = $flash->getMessages();
        if (!empty($messages)) {
            foreach ($messages as $type => $msgArray) {
                if (!empty($msgArray)) {
                    $flashMessages = ['type' => $type, 'message' => $msgArray[0]];
                    break;
                }
            }
        }
    }

    ob_start();
    require $layout;
    $html = ob_get_clean();

    $response->getBody()->write($html);
    return $response->withHeader('Content-Type', 'text/html');
}

$app->get('/', function (Request $request, Response $response) use ($templatePath) {
    $flash = $request->getAttribute('flash');
    return render(
        $response,
        $templatePath,
        $templatePath . '/layouts/main.php',
        $templatePath . '/index.php',
        ['url' => '', 'errors' => []],
        $flash
    );
});

$app->get('/urls', function (Request $request, Response $response) use ($templatePath) {
    $flash = $request->getAttribute('flash');
    $urls = Url::findAll();

    return render(
        $response,
        $templatePath,
        $templatePath . '/layouts/main.php',
        $templatePath . '/urls/index.php',
        ['urls' => $urls],
        $flash
    );
});

$app->get('/urls/{id}', function (Request $request, Response $response, $args) use ($templatePath) {
    $flash = $request->getAttribute('flash');
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
        ['url' => $url, 'checks' => $checks],
        $flash
    );
});

$app->post('/urls', function (Request $request, Response $response) {
    $flash = $request->getAttribute('flash');
    $data = $request->getParsedBody();
    $url = trim($data['url'] ?? '');

    $validator = new Validator(['url' => $url]);
    $validator->rule('required', 'url')->message('URL не должен быть пустым');
    $validator->rule('url', 'url')->message('Некорректный URL');
    $validator->rule('lengthMax', 'url', 255)->message('URL превышает 255 символов');

    if (!$validator->validate()) {
        $errors = $validator->errors();
        $flash->addMessage('error', reset($errors['url']));
        return $response->withHeader('Location', '/')->withStatus(422);
    }

    $result = Url::save($url);

    if ($result && isset($result['id'])) {
        if (isset($result['is_new']) && $result['is_new'] === true) {
            $flash->addMessage('success', 'Страница успешно добавлена');
        } else {
            $flash->addMessage('info', 'Страница уже существует');
        }
        return $response->withHeader('Location', '/urls/' . $result['id'])->withStatus(302);
    } else {
        $flash->addMessage('error', 'Ошибка при сохранении URL');
        return $response->withHeader('Location', '/')->withStatus(302);
    }
});

$app->post('/urls/{id}/checks', function (Request $request, Response $response, $args) {
    $flash = $request->getAttribute('flash');
    $id = (int) $args['id'];
    $url = Url::findById($id);

    if (!$url) {
        $flash->addMessage('error', 'Страница не найдена');
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

        $flash->addMessage('success', 'Страница успешно проверена');
    } catch (RequestException $e) {
        if ($e->hasResponse()) {
            $flash->addMessage('error', 'Ошибка проверки: HTTP ' . $e->getResponse()->getStatusCode());
        } else {
            $flash->addMessage('error', 'Произошла ошибка при проверке: Не удалось подключиться к серверу');
        }
    } catch (Exception $e) {
        $flash->addMessage('error', 'Произошла ошибка при проверке');
    }

    return $response->withHeader('Location', '/urls/' . $id)->withStatus(302);
});

$app->run();
