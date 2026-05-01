<?php
/**
 * Список subreddit'ов для мониторинга
 * Формат: 'название' => ['category' => 'категория', 'priority' => приоритет]
 */
return [
    // Технологии и IT (высокий приоритет)
    'technology' => ['category' => 'IT', 'priority' => 10],
    'programming' => ['category' => 'IT', 'priority' => 10],
    'webdev' => ['category' => 'IT', 'priority' => 9],
    'javascript' => ['category' => 'IT', 'priority' => 8],
    'php' => ['category' => 'IT', 'priority' => 8],
    'Python' => ['category' => 'IT', 'priority' => 8],
    'MachineLearning' => ['category' => 'IT', 'priority' => 9],
    'artificial' => ['category' => 'IT', 'priority' => 9], // r/artificial (AI)
    'LocalLLaMA' => ['category' => 'IT', 'priority' => 8], // Local AI models
    'StableDiffusion' => ['category' => 'IT', 'priority' => 7],
    
    // Гаджеты и устройства
    'gadgets' => ['category' => 'Gadgets', 'priority' => 10],
    'Android' => ['category' => 'Gadgets', 'priority' => 9],
    'apple' => ['category' => 'Gadgets', 'priority' => 8],
    'hardware' => ['category' => 'Gadgets', 'priority' => 8],
    'buildapc' => ['category' => 'Gadgets', 'priority' => 7],
    
    // Сети и администрирование
    'networking' => ['category' => 'IT', 'priority' => 8],
    'homelab' => ['category' => 'IT', 'priority' => 7],
    'sysadmin' => ['category' => 'IT', 'priority' => 8],
    'selfhosted' => ['category' => 'IT', 'priority' => 7],
    
    // Игры
    'gaming' => ['category' => 'Gaming', 'priority' => 9],
    'pcgaming' => ['category' => 'Gaming', 'priority' => 8],
    'Games' => ['category' => 'Gaming', 'priority' => 8],
    'truegaming' => ['category' => 'Gaming', 'priority' => 7],
    
    // Кино и сериалы
    'movies' => ['category' => 'Entertainment', 'priority' => 9],
    'television' => ['category' => 'Entertainment', 'priority' => 8],
    'TrueFilm' => ['category' => 'Entertainment', 'priority' => 7],
    'MovieDetails' => ['category' => 'Entertainment', 'priority' => 6],
    
    // Спорт
    'soccer' => ['category' => 'Sports', 'priority' => 9],
    'football' => ['category' => 'Sports', 'priority' => 8],
    'sports' => ['category' => 'Sports', 'priority' => 8],
    'biathlon' => ['category' => 'Sports', 'priority' => 7], // если есть
    
    // Домоводство и DIY
    'HomeImprovement' => ['category' => 'Lifestyle', 'priority' => 6],
    'DIY' => ['category' => 'Lifestyle', 'priority' => 6],
    'gardening' => ['category' => 'Lifestyle', 'priority' => 6],
    'vegetablegardening' => ['category' => 'Lifestyle', 'priority' => 5],
    
    // Разное интересное
    'Futurology' => ['category' => 'Science', 'priority' => 7],
    'science' => ['category' => 'Science', 'priority' => 8],
    'space' => ['category' => 'Science', 'priority' => 6],
    'Documentaries' => ['category' => 'Entertainment', 'priority' => 5],
];