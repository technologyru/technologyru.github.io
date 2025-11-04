<?php
class MirrorSystem {
    private $target_url = 'https://bou1er.ru/sravnicar';
    private $allowed_domains = ['bou1er.ru', 'localhost'];
    
    public function getPage() {
        // Получаем путь из запроса
        $request_uri = $_SERVER['REQUEST_URI'];
        $full_url = $this->target_url . $request_uri;
        
        // Проверяем безопасность URL
        if (!$this->isSafeUrl($full_url)) {
            http_response_code(403);
            return "Доступ запрещен";
        }
        
        // Создаем контекст для запроса
        $context = $this->createContext();
        
        // Получаем контент с целевого сайта
        $content = $this->fetchContent($full_url, $context);
        
        if ($content === false) {
            http_response_code(502);
            return "Ошибка получения данных с целевого сайта";
        }
        
        // Обрабатываем контент (заменяем ссылки, пути и т.д.)
        $processed_content = $this->processContent($content, $full_url);
        
        // Устанавливаем правильные заголовки
        $this->setHeaders();
        
        return $processed_content;
    }
    
    private function isSafeUrl($url) {
        $parsed = parse_url($url);
        
        // Проверяем, что домен разрешен
        if (!in_array($parsed['host'], $this->allowed_domains)) {
            return false;
        }
        
        // Проверяем протокол
        if (!in_array($parsed['scheme'], ['http', 'https'])) {
            return false;
        }
        
        return true;
    }
    
    private function createContext() {
        $options = [
            'http' => [
                'method' => $_SERVER['REQUEST_METHOD'],
                'header' => $this->getFilteredHeaders(),
                'timeout' => 30,
                'follow_location' => 1,
                'max_redirects' => 5,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'MirrorBot/1.0'
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false
            ]
        ];
        
        // Добавляем данные POST если есть
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $options['http']['content'] = file_get_contents('php://input');
        }
        
        return stream_context_create($options);
    }
    
    private function getFilteredHeaders() {
        $headers = [];
        
        foreach ($_SERVER as $key => $value) {
            if (strpos($key, 'HTTP_') === 0) {
                $header = str_replace(' ', '-', ucwords(str_replace('_', ' ', strtolower(substr($key, 5)))));
                
                // Исключаем некоторые заголовки
                if (!in_array($header, ['Host', 'Origin', 'Referer'])) {
                    $headers[] = "$header: $value";
                }
            }
        }
        
        return implode("\r\n", $headers);
    }
    
    private function fetchContent($url, $context) {
        try {
            $content = file_get_contents($url, false, $context);
            return $content;
        } catch (Exception $e) {
            error_log("Mirror error: " . $e->getMessage());
            return false;
        }
    }
    
    private function processContent($content, $base_url) {
        // Получаем базовый URL для замены
        $base_domain = parse_url($base_url, PHP_URL_SCHEME) . '://' . parse_url($base_url, PHP_URL_HOST);
        $current_domain = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
        
        // Заменяем абсолютные URL
        $content = preg_replace_callback(
            '/(href|src|action)=["\']([^"\']*)["\']/i',
            function($matches) use ($base_domain, $current_domain) {
                $attr = $matches[1];
                $url = $matches[2];
                
                // Если это абсолютный URL целевого домена
                if (strpos($url, $base_domain) === 0) {
                    $url = str_replace($base_domain, $current_domain, $url);
                }
                // Если это относительный URL
                elseif (strpos($url, '//') !== 0 && strpos($url, 'http') !== 0) {
                    if (strpos($url, '/') === 0) {
                        $url = $current_domain . $url;
                    } else {
                        $url = $current_domain . '/' . $url;
                    }
                }
                
                return $attr . '="' . $url . '"';
            },
            $content
        );
        
        // Заменяем URL в CSS
        $content = preg_replace_callback(
            '/url\(["\']?([^)"\']+)["\']?\)/i',
            function($matches) use ($base_domain, $current_domain) {
                $url = $matches[1];
                
                if (strpos($url, $base_domain) === 0) {
                    $url = str_replace($base_domain, $current_domain, $url);
                } elseif (strpos($url, '//') !== 0 && strpos($url, 'http') !== 0) {
                    if (strpos($url, '/') === 0) {
                        $url = $current_domain . $url;
                    } else {
                        $url = $current_domain . '/' . $url;
                    }
                }
                
                return 'url("' . $url . '")';
            },
            $content
        );
        
        return $content;
    }
    
    private function setHeaders() {
        // Убираем лишние заголовки
        header_remove('X-Powered-By');
        header_remove('Server');
        
        // Устанавливаем безопасные заголовки
        header("X-Content-Type-Options: nosniff");
        header("X-Frame-Options: SAMEORIGIN");
        header("X-XSS-Protection: 1; mode=block");
    }
}

// Использование
$mirror = new MirrorSystem();
echo $mirror->getPage();
?>
