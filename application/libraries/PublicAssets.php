<?php

/** Select optional dependencies without changing third-party themes that use their own assets. */
final class PublicAssets
{
    public static function version(string $path): string
    {
        $path = preg_split('/[?#]/', $path, 2)[0];
        if (preg_match('~^https?://~i', $path)) {
            if (!str_starts_with($path, base_url())) return 'external';
            $path = substr($path, strlen(base_url()));
        }
        if (!is_file($path)) {
            $path = ltrim($path, '/\\');
            if (!preg_match('~^(?:application|node_modules|writable)[/\\\\]~', $path)) $path = 'application/' . $path;
            $path = FCPATH . $path;
        }
        return is_file($path) ? substr(hash('sha256', filemtime($path) . ':' . filesize($path)), 0, 16) : '0';
    }

    public static function select(array $assets, string $module, string $method, string $content, bool $slider, string $theme, array $sideboxes, array $required = []): array
    {
        $groups = array_fill_keys($required, true);
        foreach ($required as $group) if (!in_array($group, ['datatables', 'carousel', 'slider'], true)) throw new InvalidArgumentException('Unknown public asset group: ' . $group);
        if (in_array($module, ['armory', 'online'], true) || ($module === 'gm' && in_array($method, ['account_banned', 'ip_banned'], true))
            || preg_match('/\.(?:DataTable|dataTable)\s*\(/', $content)) $groups['datatables'] = true;
        if (str_contains($content, 'owl-carousel') || str_contains($content, 'owlCarousel(')) $groups['carousel'] = true;
        if ($slider) $groups[$theme === 'heaven' ? 'carousel' : 'slider'] = true;
        foreach (['css', 'js'] as $type) {
            if (!isset($assets[$type]['parts']['all'])) continue;
            $part = &$assets[$type]['parts']['all'];
            $part['files'] = array_values(array_filter($part['files'], static function($file) use ($groups, $sideboxes) {
                $path = str_replace('\\', '/', (string) $file);
                if (str_contains($path, '/datatables/') && !isset($groups['datatables'])) return false;
                if (str_contains($path, '/owl.carousel/') && !isset($groups['carousel'])) return false;
                if (str_ends_with($path, '/flux.min.js') && !isset($groups['slider'])) return false;
                if (preg_match('~/modules/sidebox_([^/]+)/~', $path, $match) && !in_array($match[1], $sideboxes, true)) return false;
                return true;
            }));
            // Different themes and dependency combinations must never share a minified cache file.
            $fingerprint = array_map(static fn($file) => is_string($file) && is_file($file) ? [$file, filemtime($file), filesize($file)] : $file, $part['files']);
            $part['name'] = 'public-' . substr(hash('sha256', json_encode([$theme, $type, $fingerprint])), 0, 16) . '.min.' . $type;
            unset($part);
        }
        return $assets;
    }
}
