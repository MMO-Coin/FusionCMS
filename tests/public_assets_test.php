<?php

if (PHP_SAPI !== 'cli') exit(1);
require __DIR__ . '/../application/libraries/PublicAssets.php';
function checkPublic($condition, $message) { if (!$condition) throw new RuntimeException($message); echo "PASS: $message\n"; }
foreach (['default', 'heaven'] as $theme) {
    $files = ['/node_modules/jquery/dist/jquery.min.js', '/node_modules/datatables/media/js/jquery.dataTables.min.js', '/node_modules/owl.carousel/dist/owl.carousel.min.js',
        '/application/js/flux.min.js', '/themes/' . $theme . '/modules/sidebox_poll/js/poll.js', '/themes/' . $theme . '/assets/js/app.js'];
    $assets = ['js' => ['parts' => ['all' => ['name' => 'all.min.js', 'files' => $files]]], 'css' => ['parts' => ['all' => ['name' => 'all.min.css', 'files' => ['/node_modules/datatables/media/css/jquery.dataTables.min.css', '/node_modules/owl.carousel/dist/assets/owl.carousel.min.css', '/theme.css']]]]];
    $select = static fn($module, $content = '', $slider = false, $sideboxes = [], $required = []) => PublicAssets::select($assets, $module, 'index', $content, $slider, $theme, $sideboxes, $required);
    $login = $select('auth'); $armory = $select('armory'); $news = $select('news', '<div class="owl-carousel">'); $slider = $select('news', '', true);
    $scripts = static fn($result) => implode('\n', $result['js']['parts']['all']['files']);
    checkPublic(!preg_match('/datatables|owl.carousel|flux.min|sidebox_poll/', $scripts($login)), "$theme basic pages omit optional plugins and inactive sideboxes");
    checkPublic(str_contains($scripts($armory), '/datatables/') && !str_contains($scripts($armory), '/owl.carousel/'), "$theme Armory receives only its table dependency");
    checkPublic(str_contains($scripts($news), '/owl.carousel/'), "$theme rendered carousels receive their dependency");
    checkPublic(str_contains($scripts($slider), $theme === 'heaven' ? '/owl.carousel/' : '/flux.min.js'), "$theme slider dependencies match the theme");
    checkPublic(str_contains($scripts($select('news', '', false, ['poll'])), '/sidebox_poll/'), "$theme visible sidebox scripts remain available");
    checkPublic(str_contains($scripts($select('custom', '', false, [], ['datatables'])), '/datatables/'), "$theme custom modules can declare dependencies");
    checkPublic($login['js']['parts']['all']['name'] !== $armory['js']['parts']['all']['name'] && $login === $select('auth'), "$theme cache names separate asset combinations and remain stable");
}
try { PublicAssets::select([], '', '', '', false, 'default', [], ['typo']); throw new LogicException('Expected dependency error'); }
catch (InvalidArgumentException $expected) { echo "PASS: Unknown public asset declarations fail clearly\n"; }
