<?php
namespace VladAero\Helpers;

/**
 * OpenGraph - генерация OG-метатегов
 */
class OpenGraph
{
    private static array $defaults = [
        'og:site_name' => 'VladAero — Авиационный портал',
        'og:type' => 'website',
        'og:locale' => 'ru_RU',
        'og:image' => '/img/og-default.png',
        'twitter:card' => 'summary_large_image',
    ];

    public static function render(array $data = []): string
    {
        $data = array_merge(self::$defaults, $data);
        $html = '';
        foreach ($data as $prop => $content) {
            if (!$content) continue;
            $name = str_starts_with($prop, 'twitter:') ? 'name' : 'property';
            $html .= "<meta {$name}=\"{$prop}\" content=\"" . e($content) . "\">\n";
        }
        return $html;
    }

    public static function article(array $article, string $baseUrl): string
    {
        return self::render([
            'og:type' => 'article',
            'og:title' => $article['title'] ?? '',
            'og:description' => mb_substr(strip_tags($article['excerpt'] ?? $article['content'] ?? ''), 0, 160),
            'og:url' => $baseUrl,
            'og:image' => $article['cover_image'] ?? self::$defaults['og:image'],
            'article:published_time' => $article['published_at'] ?? '',
            'article:author' => $article['author_name'] ?? '',
        ]);
    }
}
