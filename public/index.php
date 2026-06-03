<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use Slim\Flash\Messages;
use Valitron\Validator;
use App\Url;
use App\Check;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use DOMDocument;
use Exception;

require __DIR__ . '/../vendor/autoload.php';

session_start();

$app = AppFactory::create();
$app->addErrorMiddleware(true, true, true);
$app->addBodyParsingMiddleware();

$flash = new Messages();
$templatePath = __DIR__ . '/../templates';

function render($response, $templatePath, $layout, $contentTemplate, $data = [], $flash = null) {
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

// Главная страница
$app->get('/', function (Request $request, Response $response) use ($templatePath, $flash) {
    return render($response, $templatePath, 
        $templatePath . '/layouts/main.php',
        $templatePath . '/index.php',
        ['url' => '', 'errors' => []],
        $flash
    );
});

// Список всех URL
$app->get('/urls', function (Request $request, Response $response) use ($templatePath, $flash) {
    $urls = Url::findAll();
    
    return render($response, $templatePath,
        $templatePath . '/layouts/main.php',
        $templatePath . '/urls/index.php',
        ['urls' => $urls],
        $flash
    );
});

// Просмотр конкретного URL
$app->get('/urls/{id}', function (Request $request, Response $response, $args) use ($templatePath, $flash) {
    $id = (int) $args['id'];
    $url = Url::findById($id);
    
    if (!$url) {
        $response->getBody()->write('Страница не найдена');
        return $response->withStatus(404);
    }
    
    $checks = Check::findByUrlId($id);
    
    return render($response, $templatePath,
        $templatePath . '/layouts/main.php',
        $templatePath . '/urls/show.php',
        ['url' => $url, 'checks' => $checks],
        $flash
    );
});

// Добавление нового URL
$app->post('/urls', function (Request $request, Response $response) use ($flash) {
    $data = $request->getParsedBody();
    $url = trim($data['url'] ?? '');
    
    $validator = new Validator(['url' => $url]);
    $validator->rule('required', 'url')->message('URL не должен быть пустым');
    $validator->rule('url', 'url')->message('Некорректный URL');
    $validator->rule('lengthMax', 'url', 255)->message('URL превышает 255 символов');
    
    if (!$validator->validate()) {
        $errors = $validator->errors();
        $flash->addMessage('error', reset($errors['url']));
        return $response->withHeader('Location', '/')->withStatus(302);
    }
    
    $result = Url::save($url);
    
    if ($result && isset($result['id'])) {
        $flash->addMessage('success', 'Страница успешно добавлена');
    } else {
        $flash->addMessage('info', 'Страница уже существует');
    }
    
    return $response->withHeader('Location', '/urls')->withStatus(302);
});

// Запуск проверки URL (ПОЛНАЯ ВЕРСИЯ)
$app->post('/urls/{id}/checks', function (Request $request, Response $response, $args) use ($flash) {
    $id = (int) $args['id'];
    $url = Url::findById($id);
    
    if (!$url) {
        $flash->addMessage('error', 'Страница не найдена');
        return $response->withHeader('Location', '/urls')->withStatus(302);
    }
    
    $client = new Client(['allow_redirects' => true, 'timeout' => 30]);
    
    try {
        $responseHttp = $client->get($url['name']);
        $html = (string) $responseHttp->getBody();
        $statusCode = $responseHttp->getStatusCode();
        
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML($html);
        libxml_clear_errors();
        
        $titleNodes = $doc->getElementsByTagName('title');
        $title = $titleNodes->length > 0 ? trim($titleNodes->item(0)->textContent) : '';
        
        $h1Nodes = $doc->getElementsByTagName('h1');
        $h1 = $h1Nodes->length > 0 ? trim($h1Nodes->item(0)->textContent) : '';
        
        $description = '';
        $metas = $doc->getElementsByTagName('meta');
        foreach ($metas as $meta) {
            if (strtolower($meta->getAttribute('name')) === 'description') {
                $description = trim($meta->getAttribute('content'));
                break;
            }
        }
        
        Check::save($id, [
            'status_code' => $statusCode,
            'h1' => $h1,
            'title' => $title,
            'description' => $description
        ]);
        
        $flash->addMessage('success', 'Страница успешно проверена');
        
    } catch (RequestException $e) {
        $flash->addMessage('error', 'Ошибка проверки: ' . $e->getMessage());
    } catch (Exception $e) {
        $flash->addMessage('error', 'Произошла ошибка при проверке');
    }
    
    return $response->withHeader('Location', '/urls/' . $id)->withStatus(302);
});

$app->run();
