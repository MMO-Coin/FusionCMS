<?php

require_once __DIR__ . '/../application/libraries/ConfigEditor.php';

$cases = [
    'preserves siblings in mixed nested arrays' => [
        "array('list' => ['value' => 'old'])",
        ['settings.list.value' => "A 'quoted' value", 'settings.list.enabled' => true],
        ['list' => ['value' => "A 'quoted' value", 'enabled' => true]],
    ],
    'preserves indexed entries with trailing commas' => [
        "[['gold' => 3,],]",
        ['settings.0.silver' => 4, 'settings.1.gold' => 5],
        [['gold' => 3, 'silver' => 4], ['gold' => 5]],
    ],
    'creates a missing path under an existing scalar' => [
        "'old'",
        ['settings.child.enabled' => true],
        ['child' => ['enabled' => true]],
    ],
    'escapes quotes and backslashes in values' => [
        "['value' => 'old']",
        ['settings.value' => "C:\\server\\'; throw new Exception('injected'); //"],
        ['value' => "C:\\server\\'; throw new Exception('injected'); //"],
    ],
];

foreach ($cases as $name => [$source, $changes, $expected]) {
    $file = tempnam(sys_get_temp_dir(), 'fusion-config-');
    if ($file === false) {
        throw new RuntimeException('Unable to create a temporary config file.');
    }

    try {
        file_put_contents($file, "<?php\n\$config['settings'] = $source;\n");
        $editor = new ConfigEditor($file);
        foreach ($changes as $path => $value) {
            $editor->set($path, $value);
        }
        $editor->save();

        $config = [];
        include $file;
        if ($config['settings'] !== $expected) {
            throw new RuntimeException("Failed: $name");
        }

        echo "PASS: $name\n";
    } finally {
        unlink($file);
    }
}
