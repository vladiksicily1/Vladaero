<?php
namespace VladAero\Controllers\Admin;

use VladAero\Core\{Controller, Session, Database};
use VladAero\Helpers\FirecrawlService;

/**
 * AiSettingsController — Настройки ИИ и Firecrawl
 */
class AiSettingsController extends Controller
{
    public function index()
    {
        $this->requireAdmin();
        $config = $this->config;
        $firecrawl = new FirecrawlService();
        $this->view('admin.ai-settings', [
            'ai'       => $config['ai'] ?? [],
            'firecrawl'=> $config['firecrawl'] ?? [],
            'fcOk'     => $firecrawl->isConfigured(),
        ]);
    }

    public function save()
    {
        $this->requireAdmin();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { $this->redirect('/admin/ai-settings'); }
        $this->verifyCsrf();

        $installedFile = PROJECT_ROOT . '/config/installed.php';
        if (!file_exists($installedFile)) {
            Session::setFlash('error', 'Файл конфигурации не найден');
            $this->redirect('/admin/ai-settings');
        }

        $current = require $installedFile;

        // Merge AI settings
        $current['ai'] = [
            'enabled'     => true,
            'provider'    => 'openai_compatible',
            'base_url'    => $this->input('ai_base_url') ?? '',
            'api_key'     => $this->input('ai_api_key') ?? '',
            'model_id'    => $this->input('ai_model_id') ?? '',
            'temperature'=> (float)($this->input('ai_temperature') ?? 0.7),
            'max_tokens'  => (int)($this->input('ai_max_tokens') ?? 4096),
            'streaming'   => true,
        ];

        $current['firecrawl'] = [
            'api_key'  => $this->input('fc_api_key') ?? '',
            'base_url' => $this->input('fc_base_url') ?: 'https://api.firecrawl.dev/v1',
        ];

        // Rewrite config file
        $content = "<?php\nreturn " . var_export($current, true) . ";\n";
        file_put_contents($installedFile, $content);

        // Reload config
        $GLOBALS['config'] = array_merge($GLOBALS['config'], $current);

        Session::setFlash('success', 'Настройки ИИ сохранены');
        $this->redirect('/admin/ai-settings');
    }

    public function testAi()
    {
        $this->requireAdmin();
        $apiKey = $this->input('ai_api_key') ?? ($this->config['ai']['api_key'] ?? '');
        $baseUrl = $this->input('ai_base_url') ?? ($this->config['ai']['base_url'] ?? 'https://api.openai.com/v1');
        $model = $this->input('ai_model_id') ?? ($this->config['ai']['model_id'] ?? 'gpt-4o-mini');

        if (!$apiKey) {
            $this->json(['success' => false, 'message' => 'API ключ не указан']);
            return;
        }

        $url = rtrim($baseUrl, '/') . '/chat/completions';
        $payload = json_encode([
            'model' => $model,
            'messages' => [['role' => 'user', 'content' => 'Say "VladAero AI test OK" in 5 words or less.']],
            'max_tokens' => 50,
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $apiKey, 'Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            $data = json_decode($response, true);
            $reply = $data['choices'][0]['message']['content'] ?? 'OK';
            $this->json(['success' => true, 'message' => "✅ AI работает! Ответ: {$reply}"]);
        } else {
            $this->json(['success' => false, 'message' => "❌ HTTP {$httpCode}: {$response}"]);
        }
    }

    public function testFirecrawl()
    {
        $this->requireAdmin();
        $apiKey = $this->input('fc_api_key') ?? ($this->config['firecrawl']['api_key'] ?? '');

        if (!$apiKey) {
            $this->json(['success' => false, 'message' => 'Firecrawl API ключ не указан']);
            return;
        }

        $fc = new FirecrawlService();
        // Override key for test
        $result = $fc->search('aviation safety 2024', 1);

        if (isset($result['error'])) {
            $this->json(['success' => false, 'message' => "❌ {$result['error']}"]);
        } else {
            $count = count($result['results'] ?? []);
            $this->json(['success' => true, 'message' => "✅ Firecrawl работает! Найдено {$count} результат(ов)"]);
        }
    }

    public function fetchModels()
    {
        $this->requireAdmin();
        $baseUrl = $this->input('ai_base_url') ?? ($this->config['ai']['base_url'] ?? '');
        $apiKey = $this->input('ai_api_key') ?? ($this->config['ai']['api_key'] ?? '');

        if (!$baseUrl || !$apiKey) {
            $this->json(['success' => false, 'message' => 'Укажите URL и ключ']);
            return;
        }

        $url = rtrim($baseUrl, '/') . '/models';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $apiKey],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($response, true);
        $models = [];
        foreach ($data['data'] ?? [] as $m) {
            $models[] = $m['id'] ?? '';
        }
        sort($models);

        $this->json(['success' => true, 'models' => $models]);
    }

    private function requireAdmin() { $this->requireRole('admin'); }
}
