<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use Slim\Views\PhpRenderer;
use Slim\Flash\Messages;

require __DIR__ . '/../vendor/autoload.php';

$app = AppFactory::create();

// Настройка шаблонизатора
$renderer = new PhpRenderer(__DIR__ . '/../templates');

// Добавляем middleware для flash-сообщений
$app->add(function ($request, $handler) {
    $response = $handler->handle($request);
    return $response;
});

$app->addBodyParsingMiddleware();
$app->addErrorMiddleware(true, true, true);

// Главная страница
$app->get('/', function (Request $request, Response $response) use ($renderer) {
    $flash = [];
    // Получаем flash-сообщения из сессии (позже)
    
    return $renderer->render($response, 'index.php', [
        'url' => '',
        'errors' => [],
        'flash' => $flash
    ]);
});

// Обработка URL
$app->post('/urls', function (Request $request, Response $response) {
    $data = $request->getParsedBody();
    $url = $data['url'] ?? '';
    
    // Простая валидация
    if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
        // Тут позже добавим flash-сообщение
        $response->getBody()->write("Ошибка: некорректный URL");
        return $response;
    }
    
    $response->getBody()->write("URL добавлен: " . htmlspecialchars($url));
    return $response;
});

$app->run();