<?php

/** Optional admin plugins, in dependency order. Paths are relative to the site root. */
final class AdminAssets
{
    private const ROOT = 'application/themes/admin/assets/';
    private const GROUPS = [
        'datatables' => ['css' => ['vendor/datatables/media/css/dataTables.bootstrap5.min.css'], 'js' => ['vendor/datatables/media/js/jquery.dataTables.min.js', 'vendor/datatables/media/js/dataTables.bootstrap5.min.js']],
        'charts' => ['js' => ['vendor/apexcharts/apexcharts.min.js']],
        'codemirror' => ['css' => ['vendor/codemirror/lib/codemirror.css', 'vendor/codemirror/theme/ayu-mirage.css'], 'js' => ['vendor/codemirror/lib/codemirror.js', 'vendor/codemirror/addon/selection/active-line.js', 'vendor/codemirror/addon/edit/matchbrackets.js', 'vendor/codemirror/mode/javascript/javascript.js', 'vendor/codemirror/mode/xml/xml.js', 'vendor/codemirror/mode/css/css.js', 'vendor/codemirror/mode/htmlmixed/htmlmixed.js']],
        'imagesloader' => ['css' => ['vendor/imagesloader/jquery.imagesloader.css'], 'js' => ['vendor/imagesloader/jquery.imagesloader-1.0.1.js']],
        'dropzone' => ['css' => ['vendor/dropzone/basic.css', 'vendor/dropzone/dropzone.css'], 'js' => ['vendor/dropzone/dropzone.js']],
        'carousel' => ['css' => ['vendor/owl.carousel/assets/owl.carousel.css', 'vendor/owl.carousel/assets/owl.theme.default.css'], 'js' => ['vendor/owl.carousel/owl.carousel.js']],
        'datepicker' => ['css' => ['vendor/bootstrap-datepicker/css/bootstrap-datepicker3.css'], 'js' => ['vendor/bootstrap-datepicker/js/bootstrap-datepicker.js']],
        'select2' => ['css' => ['vendor/select2/css/select2.css', 'vendor/select2-bootstrap-theme/select2-bootstrap.min.css'], 'js' => ['vendor/select2/js/select2.min.js']],
        'multiselect' => ['css' => ['vendor/bootstrap-multiselect/css/bootstrap-multiselect.css'], 'js' => ['vendor/bootstrapv5-multiselect/js/bootstrap-multiselect.js', 'js/theme.js']],
        'spinner' => ['js' => ['vendor/fuelux/js/spinner.js']],
        'popup' => ['css' => ['vendor/magnific-popup/magnific-popup.css'], 'js' => ['vendor/magnific-popup/jquery.magnific-popup.js']],
        'jqueryui' => ['css' => ['vendor/jquery-ui/jquery-ui.css', 'vendor/jquery-ui/jquery-ui.theme.css'], 'js' => ['vendor/jquery-ui/jquery-ui.js', 'vendor/jqueryui-touch-punch/jquery.ui.touch-punch.js']],
        'sparkline' => ['js' => ['vendor/jquery-sparkline/jquery.sparkline.js']],
        'flot' => ['js' => ['vendor/flot/jquery.flot.js', 'vendor/flot.tooltip/jquery.flot.tooltip.js', 'vendor/flot/jquery.flot.pie.js', 'vendor/flot/jquery.flot.categories.js', 'vendor/flot/jquery.flot.resize.js']],
        'morris' => ['css' => ['vendor/morris/morris.css'], 'js' => ['vendor/raphael/raphael.js', 'vendor/morris/morris.js']],
        'gauge' => ['js' => ['vendor/gauge/gauge.js']],
        'liquidmeter' => ['js' => ['vendor/snap.svg/snap.svg.js', 'vendor/liquid-meter/liquid.meter.js']],
        'appear' => ['js' => ['vendor/jquery-appear/jquery.appear.js']],
        'switchery' => ['css' => ['https://cdnjs.cloudflare.com/ajax/libs/switchery/0.8.2/switchery.min.css'], 'js' => ['https://cdnjs.cloudflare.com/ajax/libs/switchery/0.8.2/switchery.min.js']],
    ];
    private const MARKERS = [
        'datatables' => '/(?:\.dataTable\s*\(|\.DataTable\s*\(|data-admin-assets=["\'][^"\']*\bdatatables\b)/i',
        'charts' => '/\bApexCharts\b/',
        'codemirror' => '/\bCodeMirror\b|data-plugin-codemirror/',
        'imagesloader' => '/\bimagesloader\b/i',
        'dropzone' => '/\bdropzone\b/i',
        'carousel' => '/owl-carousel|\.owlCarousel\s*\(/',
        'datepicker' => '/data-plugin-datepicker|data-provide=["\']datepicker|\.datepicker\s*\(/',
        'select2' => '/data-plugin-selectTwo|data-plugin-select2|\.select2\s*\(/i',
        'multiselect' => '/data-plugin-multiselect|\.multiselect\s*\(|themePluginMultiSelect/i',
        'spinner' => '/data-plugin-spinner|\.spinner\s*\(/',
        'popup' => '/\.magnificPopup\s*\(|data-plugin-lightbox|class=["\'][^"\']*popup-with/',
        'jqueryui' => '/\.(?:sortable|draggable|droppable|resizable|autocomplete)\s*\(|data-plugin-sortable/',
        'sparkline' => '/\.sparkline\s*\(|data-plugin-sparkline/',
        'flot' => '/\$\.plot\s*\(/',
        'morris' => '/\bMorris\./',
        'gauge' => '/new Gauge\s*\(/',
        'liquidmeter' => '/\.liquidMeter\s*\(/',
        'appear' => '/data-appear|\.appear\s*\(/',
        'switchery' => '/\bSwitchery\b|class=["\'][^"\']*\bjs-switch\b/',
    ];

    public static function forContent(string $content, array $required = []): array
    {
        $enabled = array_fill_keys($required, true);
        foreach (self::MARKERS as $name => $pattern) if (preg_match($pattern, $content)) $enabled[$name] = true;
        if (preg_match('/\b(?:icp|iconpicker)\b/', $content)) $enabled['iconpicker'] = true;
        if (isset($enabled['jqueryui'])) $enabled = ['jqueryui' => true] + $enabled;
        $assets = ['css' => [], 'js' => [], 'groups' => array_keys($enabled)];
        foreach ($enabled as $name => $_) {
            if ($name === 'iconpicker') {
                $assets['css'][] = 'node_modules/fontawesome-iconpicker/dist/css/fontawesome-iconpicker.min.css';
                $assets['js'][] = 'node_modules/fontawesome-iconpicker/dist/js/fontawesome-iconpicker.min.js';
                continue;
            }
            if (!isset(self::GROUPS[$name])) throw new InvalidArgumentException('Unknown admin asset group: ' . $name);
            foreach (['css', 'js'] as $type) foreach (self::GROUPS[$name][$type] ?? [] as $path) $assets[$type][] = str_starts_with($path, 'https://') ? $path : self::ROOT . $path;
        }
        foreach (['css', 'js'] as $type) $assets[$type] = array_values(array_unique($assets[$type]));
        return $assets;
    }
}
