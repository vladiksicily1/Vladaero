<?php
namespace VladAero\Helpers;

/**
 * FirecrawlService — Web Search и URL Extraction через Firecrawl API
 *
 * Methods:
 * - search($query, $limit) — поиск в интернете
 * - scrapeUrl($url, $formats) — извлечение контента со страницы
 * - map($url, $limit) — карта ссылок сайта
 * - checkStatus($jobId) — проверка статуса async задачи
 */
class FirecrawlService
{
    private string $apiKey;
    private string $baseUrl;

    public function __construct()
    {
        $config = $GLOBALS['config']['firecrawl'] ?? [];
        $this->apiKey = $config['api_key'] ?? '';
        $this->baseUrl = rtrim($config['base_url'] ?? 'https://api.firecrawl.dev/v1', '/');
    }

    public function isConfigured(): bool
    {
        return !empty($this->apiKey);
    }

    /**
     * Web Search — поиск в интернете
     */
    public function search(string $query, int $limit = 5, array $options = []): array
    {
        if (!$this->isConfigured()) {
            return ['error' => 'Firecrawl API key not configured'];
        }

        $payload = array_merge([
            'query' => $query,
            'limit' => $limit,
            'scrapeOptions' => [
                'onlyMainContent' => true,
                'formats' => ['markdown'],
            ],
        ], $options);

        $result = $this->post('/search', $payload);
        if (isset($result['error'])) return $result;

        $data = $result['data'] ?? [];
        $results = [];
        foreach ($data as $item) {
            $results[] = [
                'title'   => $item['title'] ?? '',
                'url'     => $item['url'] ?? '',
                'snippet' => mb_substr($item['markdown'] ?? $item['description'] ?? '', 0, 500),
                'score'   => $item['score'] ?? 0,
            ];
        }

        return ['results' => $results, 'query' => $query];
    }

    /**
     * Scrape URL — извлечение контента со страницы
     */
    public function scrapeUrl(string $url, array $formats = ['markdown'], array $options = []): array
    {
        if (!$this->isConfigured()) {
            return ['error' => 'Firecrawl API key not configured'];
        }

        $payload = array_merge([
            'url' => $url,
            'formats' => $formats,
            'onlyMainContent' => true,
            'timeout' => 30000,
        ], $options);

        $result = $this->post('/scrape', $payload);
        if (isset($result['error'])) return $result;

        $data = $result['data'] ?? $result;
        return [
            'url'        => $data['url'] ?? $url,
            'title'      => $data['metadata']['title'] ?? '',
            'description'=> $data['metadata']['description'] ?? '',
            'markdown'   => $data['markdown'] ?? '',
            'html'       => $data['html'] ?? '',
            'metadata'   => $data['metadata'] ?? [],
        ];
    }

    /**
     * Map — карта ссылок сайта
     */
    public function map(string $url, int $limit = 50): array
    {
        if (!$this->isConfigured()) {
            return ['error' => 'Firecrawl API key not configured'];
        }

        $result = $this->post('/map', [
            'url' => $url,
            'limit' => $limit,
            'sitemapOnly' => false,
        ]);
        if (isset($result['error'])) return $result;

        return ['links' => $result['data'] ?? [], 'url' => $url];
    }

    /**
     * Batch scrape — пакетная обработка URL
     */
    public function batchScrape(array $urls, array $formats = ['markdown']): array
    {
        if (!$this->isConfigured()) {
            return ['error' => 'Firecrawl API key not configured'];
        }

        $payload = [
            'urls' => $urls,
            'formats' => $formats,
            'onlyMainContent' => true,
        ];

        $result = $this->post('/batch/scrape', $payload);
        if (isset($result['error'])) return $result;

        return ['job_id' => $result['id'] ?? '', 'status' => $result['status'] ?? 'unknown'];
    }

    /**
     * Проверка статуса async задачи
     */
    public function checkStatus(string $jobId, string $type = 'scrape'): array
    {
        if (!$this->isConfigured()) {
            return ['error' => 'Firecrawl API key not configured'];
        }

        return $this->get("/batch/{$type}/{$jobId}");
    }

    /**
     * Извлечение markdown из URL + обрезка до лимита токенов
     */
    public function extractContent(string $url, int $maxChars = 8000): array
    {
        $result = $this->scrapeUrl($url);
        if (isset($result['error'])) return $result;

        $content = $result['markdown'] ?? '';
        if (mb_strlen($content) > $maxChars) {
            $content = mb_substr($content, 0, $maxChars) . "\n\n[...обрезано...]";
        }

        return [
            'url'     => $result['url'],
            'title'   => $result['title'],
            'content' => $content,
            'length'  => mb_strlen($content),
        ];
    }

    // ─── HTTP Methods ────────────────────────────────────────────

    private function post(string $endpoint, array $data): array
    {
        return $this->request('POST', $endpoint, $data);
    }

    private function get(string $endpoint): array
    {
        return $this->request('GET', $endpoint);
    }

    private function request(string $method, string $endpoint, ?array $data = null): array
    {
        $url = $this->baseUrl . $endpoint;

        $ch = curl_init($url);
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apiKey,
        ];

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => $headers,
        ];

        if ($method === 'POST' && $data !== null) {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = json_encode($data);
        }

        curl_setopt_array($ch, $options);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return ['error' => "cURL error: {$error}"];
        }

        if ($httpCode >= 400) {
            $body = json_decode($response, true);
            $msg = $body['error'] ?? $body['message'] ?? "HTTP {$httpCode}";
            return ['error' => "Firecrawl error: {$msg}"];
        }

        return json_decode($response, true) ?? [];
    }
}
