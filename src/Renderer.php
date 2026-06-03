<?php

namespace App;

use Slim\Views\PhpRenderer;

class Renderer
{
    private PhpRenderer $renderer;
    
    public function __construct(string $templatePath)
    {
        $this->renderer = new PhpRenderer($templatePath);
    }
    
    public function render($response, string $template, array $data = [])
    {
        return $this->renderer->render($response, $template, $data);
    }
    
    public function renderWithLayout($response, string $layout, string $contentTemplate, array $data = [])
    {
        // Рендерим контент
        $content = $this->renderer->fetch($contentTemplate, $data);
        
        // Добавляем контент в данные для layout
        $data['content'] = $content;
        
        // Рендерим layout с контентом
        return $this->renderer->render($response, $layout, $data);
    }
}
