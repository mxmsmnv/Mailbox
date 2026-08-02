<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$files = array_merge([$root . '/Mailbox.module.php', $root . '/ProcessMailbox.module.php'], glob($root . '/src/*.php') ?: []);
$expected = [];

foreach($files as $file) {
    $relative = ltrim(str_replace($root, '', $file), '/');
    $domain = strpos($relative, 'ProcessMailbox') !== false ? 'ProcessMailbox.module.php' : 'Mailbox.module.php';
    $tokens = token_get_all((string) file_get_contents($file));
    for($i = 0, $count = count($tokens); $i < $count - 5; $i++) {
        if(!is_array($tokens[$i]) || $tokens[$i][0] !== T_VARIABLE || $tokens[$i][1] !== '$this') continue;
        $cursor = $i + 1;
        while($cursor < $count && is_array($tokens[$cursor]) && $tokens[$cursor][0] === T_WHITESPACE) $cursor++;
        if(!is_array($tokens[$cursor] ?? null) || $tokens[$cursor][0] !== T_OBJECT_OPERATOR) continue;
        $cursor++;
        while($cursor < $count && is_array($tokens[$cursor]) && $tokens[$cursor][0] === T_WHITESPACE) $cursor++;
        if(!is_array($tokens[$cursor] ?? null) || $tokens[$cursor][1] !== '_') continue;
        $cursor++;
        while($cursor < $count && ((is_array($tokens[$cursor]) && $tokens[$cursor][0] === T_WHITESPACE) || $tokens[$cursor] === '(')) $cursor++;
        if(!is_array($tokens[$cursor] ?? null) || $tokens[$cursor][0] !== T_CONSTANT_ENCAPSED_STRING) continue;
        $phrase = eval('return ' . $tokens[$cursor][1] . ';');
        if(is_string($phrase) && $phrase !== '') $expected[$domain . "\0" . $phrase] = true;
    }
}
ksort($expected);

$languages = ['German' => 'de', 'French' => 'fr', 'Italian' => 'it', 'Spanish' => 'es', 'Dutch' => 'nl'];
$formatTokens = static function(string $text): array {
    preg_match_all('/%\d*\$?[bcdeEfFgGosuxX]/', $text, $matches);
    sort($matches[0]);
    return $matches[0];
};

foreach($languages as $language => $code) {
    $path = $root . '/languages/' . $language . '.csv';
    if(!is_file($path)) throw new RuntimeException('Missing Mailbox language file: ' . $language);
    $handle = fopen($path, 'rb');
    if(!$handle) throw new RuntimeException('Unable to read Mailbox language file: ' . $language);
    $header = fgetcsv($handle, 0, ',', '"', '');
    if($header !== ['en', $code, 'description', 'file', 'hash']) throw new RuntimeException('Invalid language header: ' . $language);
    $actual = [];
    while(($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
        if(count($row) !== 5) throw new RuntimeException('Invalid language row width: ' . $language);
        [$english, $translation, $description, $file, $hash] = $row;
        if($english === '' || trim($translation) === '') throw new RuntimeException('Empty language value: ' . $language);
        if($description !== '' || !in_array($file, ['site/modules/Mailbox/Mailbox.module.php', 'site/modules/Mailbox/ProcessMailbox.module.php'], true)) throw new RuntimeException('Invalid language textdomain: ' . $language);
        if($hash !== md5($english)) throw new RuntimeException('Invalid language hash: ' . $language . ' / ' . $english);
        if($formatTokens($english) !== $formatTokens($translation)) throw new RuntimeException('Changed format placeholder: ' . $language . ' / ' . $english);
        if(strpos($translation, '__MBX') !== false) throw new RuntimeException('Internal translation marker leaked: ' . $language);
        $key = basename($file) . "\0" . $english;
        if(isset($actual[$key])) throw new RuntimeException('Duplicate language row: ' . $language . ' / ' . $english);
        $actual[$key] = true;
    }
    fclose($handle);
    ksort($actual);
    if(array_keys($expected) !== array_keys($actual)) {
        $missing = array_diff_key($expected, $actual);
        $extra = array_diff_key($actual, $expected);
        throw new RuntimeException(sprintf('%s language coverage mismatch: %d missing, %d extra.', $language, count($missing), count($extra)));
    }
}

fwrite(STDOUT, sprintf("Mailbox language smoke tests passed (%d strings × %d languages).\n", count($expected), count($languages)));
