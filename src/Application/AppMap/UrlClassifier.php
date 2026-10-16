<?php

namespace FlowEngine\Application\AppMap;

/**
 * Separa URL de recurso estático (`asset`) de chamada a serviço (`http`).
 */
final class UrlClassifier
{
    private const ASSET_EXTENSIONS = [
        'png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp', 'ico', 'svg',
        'woff', 'woff2', 'ttf', 'otf', 'eot',
        'css', 'js', 'mjs', 'map', 'pdf',
    ];

    private const CDN_HOSTS = [
        'cdnjs.cloudflare.com',
        'cdn.jsdelivr.net',
        'unpkg.com',
        'fonts.googleapis.com',
        'fonts.gstatic.com',
        'code.jquery.com',
        'stackpath.bootstrapcdn.com',
        'maxcdn.bootstrapcdn.com',
        'ajax.googleapis.com',
    ];

    public function kind(string $host, string $path): string
    {
        if (in_array(strtolower($host), self::CDN_HOSTS, true)) {
            return 'asset';
        }

        // O regex de URL do detector leva pontuação final junto (`url(x.png)`).
        $extension = strtolower(pathinfo(rtrim($path, ').,;'), PATHINFO_EXTENSION));

        return in_array($extension, self::ASSET_EXTENSIONS, true) ? 'asset' : 'http';
    }
}
