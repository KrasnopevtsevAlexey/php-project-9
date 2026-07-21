<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

// Просто вызываем метод get(), который сам атомарно создаст нужную структуру в правильном месте
\App\Connection::get();
echo "База данных успешно инициализирована!\n";
