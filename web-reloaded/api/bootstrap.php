<?php
declare(strict_types=1);

function app_root(): string
{
    return dirname(__DIR__, 2);
}

function app_data_file(string $filename): string
{
    return app_root() . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . ltrim($filename, DIRECTORY_SEPARATOR);
}

function app_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }
    $base = require __DIR__ . '/config.php';
    $localFile = __DIR__ . '/config.local.php';
    if (is_file($localFile)) {
        $local = require $localFile;
        if (is_array($local)) {
            $base = array_replace_recursive($base, $local);
        }
    }
    $config = $base;
    return $config;
}

function start_app_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $config = app_config();
    if (!empty($config['session_name']) && is_string($config['session_name'])) {
        session_name($config['session_name']);
    }
    session_start([
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
        'use_strict_mode' => true,
    ]);
}

function send_json(int $statusCode, array $payload): never
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function request_json(): array
{
    $rawBody = file_get_contents('php://input');
    $decoded = json_decode($rawBody ?: '{}', true);
    return is_array($decoded) ? $decoded : [];
}

function truncate_text(mixed $value, int $maxLength): string
{
    return mb_substr(trim((string)($value ?? '')), 0, $maxLength);
}

function ensure_json_store(string $path): void
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    if (!is_file($path)) {
        file_put_contents($path, "[]\n", LOCK_EX);
    }
}

function read_json_store(string $path): array
{
    ensure_json_store($path);
    $decoded = json_decode((string)file_get_contents($path), true);
    return is_array($decoded) ? $decoded : [];
}

function write_json_store(string $path, array $entries): void
{
    ensure_json_store($path);
    file_put_contents(
        $path,
        json_encode(array_values($entries), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL,
        LOCK_EX
    );
}

function request_ip(): string
{
    $candidates = [
        $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '',
        $_SERVER['HTTP_CLIENT_IP'] ?? '',
        $_SERVER['REMOTE_ADDR'] ?? '',
    ];
    foreach ($candidates as $candidate) {
        if (!is_string($candidate) || trim($candidate) === '') {
            continue;
        }
        $ip = trim(explode(',', $candidate)[0]);
        if ($ip !== '') {
            return $ip;
        }
    }
    return 'unknown';
}

function append_auth_log(string $sessionType, string $name, string $event = 'login'): void
{
    $normalizedSession = trim($event) === 'logout'
        ? sprintf('logout:%s', $sessionType !== '' ? $sessionType : 'unknown')
        : ($sessionType !== '' ? $sessionType : 'unknown');

    $entries = read_json_store(app_data_file('login-log.json'));
    $entries[] = [
        'session' => $normalizedSession,
        'name' => $name !== '' ? $name : 'Anonimo',
        'date' => date(DATE_ATOM),
        'ip' => request_ip(),
    ];
    write_json_store(app_data_file('login-log.json'), $entries);
}

function current_auth_state(): array
{
    start_app_session();
    $sessionType = $_SESSION['session_type'] ?? '';
    $userName = $_SESSION['user_name'] ?? '';
    $isVa = $sessionType === 'va' && $userName !== '';
    $isGeneric = $sessionType === 'generic';
    return [
        'authenticated' => $isVa || $isGeneric,
        'sessionType' => $sessionType ?: 'none',
        'userName' => is_string($userName) ? $userName : '',
        'isVa' => $isVa,
        'isGeneric' => $isGeneric,
    ];
}

function require_va_session(): array
{
    $state = current_auth_state();
    if (!$state['isVa']) {
        send_json(403, ['error' => 'Contenuto riservato alla sessione VA']);
    }
    return $state;
}

function current_admin_state(): array
{
    start_app_session();
    return [
        'authenticated' => !empty($_SESSION['godpanel_admin']) && $_SESSION['godpanel_admin'] === true,
    ];
}

function require_admin_session(): array
{
    $state = current_admin_state();
    if (!$state['authenticated']) {
        send_json(403, ['error' => 'Accesso admin richiesto']);
    }
    return $state;
}

function media_catalog_for_session(string $sessionType, bool $includePrivate): array
{
    if ($sessionType !== 'va') {
        return [];
    }
    $items = [
        ['id' => 'coppa', 'title' => 'La coppa si smonta', 'subtitle' => '19 febbraio 2008 · Corsa campestre', 'kind' => 'video', 'note' => 'Registrato e montato interamente in classe con il Nokia N70.'],
        ['id' => 'cento_giorni', 'title' => '100 Giorni 15 Marzo 2008 - Roma', 'subtitle' => '11-15 marzo 2008 · Archivio principale', 'kind' => 'video', 'note' => 'Video locale estratto dall’archivio originale.'],
        ['id' => 'lisbona1', 'title' => 'Gita a Lisbona - Primo tempo', 'subtitle' => 'Dal 14 aprile 2008', 'kind' => 'video', 'note' => 'Conversione locale da WMV a MP4.'],
        ['id' => 'lisbona2', 'title' => 'Gita a Lisbona - Secondo tempo', 'subtitle' => 'Dal 14 aprile 2008', 'kind' => 'video', 'note' => 'Conversione locale da WMV a MP4.'],
        ['id' => 'lisbona3', 'title' => 'Gita a Lisbona - Terzo tempo', 'subtitle' => 'Dal 14 aprile 2008', 'kind' => 'video', 'note' => 'Conversione locale da WMV a MP4.'],
        ['id' => 'lisbona4', 'title' => 'Gita a Lisbona - Quarto tempo', 'subtitle' => 'Dal 14 aprile 2008 · Incompleto', 'kind' => 'video', 'note' => 'Conversione locale da WMV a MP4.'],
        ['id' => 'cena1', 'title' => 'Cena coi prof - Parte 1', 'subtitle' => '10 giugno 2008 · Compito in classe', 'kind' => 'video', 'note' => 'Streaming locale MP4.'],
        ['id' => 'cena2', 'title' => 'Cena coi prof - Parte 2', 'subtitle' => '10 giugno 2008 · Indovina cosa ho in testa', 'kind' => 'video', 'note' => 'Streaming locale MP4.'],
        ['id' => 'cena2026foto', 'title' => 'Cena coi prof 2026', 'subtitle' => '27 febbraio 2026 · Foto ricordo', 'kind' => 'image', 'note' => 'Scatto della cena coi prof aggiunto nell’archivio memorial.'],
    ];
    if ($includePrivate) {
        array_unshift($items, ['id' => 'va0708', 'title' => 'V A 2007/2008', 'subtitle' => 'Settembre 2007 - giugno 2008', 'kind' => 'video', 'note' => 'Archivio principale della classe VA 2007/2008.']);
    }
    return $items;
}
