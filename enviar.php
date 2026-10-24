<?php
/**
 * Contact form handler - Carrocerias Alejandra Rodriguez SpA
 *
 * Receives the landing pages' contact forms (POST), validates the input
 * and emails the inquiry to the sales address. Dependency-free: uses PHP mail().
 *
 * Expected POST fields:
 *   nombre   (required)
 *   telefono (required, Chilean mobile; normalized to "+56 9 XXXX XXXX")
 *   email    (required) (domain must resolve)
 *   mensaje  (required) (minimum 6 characters, real text)
 *   empresa  (optional)
 *   origen   (hidden: page file name, used to redirect back)
 *   website  (honeypot - hidden field, must stay empty)
 *   correo2  (second honeypot lure - must stay empty)
 *   ts       (hidden: page load timestamp, used by the fill-time anti-bot trap)
 */

// ------------------------- configuration -------------------------
const DESTINATARIO   = 'ventas@arodriguez.cl';
const ASUNTO_PREFIJO = '[Web] Consulta de cotización';
// ------------------------------------------------------------------

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: index.html');
    exit;
}

/** Single-line field: strips tags, CR/LF and control chars (header-injection guard). */
function campo(string $key, int $maxLen): string
{
    $v = isset($_POST[$key]) ? (string) $_POST[$key] : '';
    $v = trim($v);
    $v = strip_tags($v);
    $v = str_replace(["\r", "\n", '%0a', '%0d'], ' ', $v);
    return function_exists('mb_substr') ? mb_substr($v, 0, $maxLen) : substr($v, 0, $maxLen);
}

/** Multiline field (body only): keeps \n, strips \r and tags. */
function campo_multilinea(string $key, int $maxLen): string
{
    $v = isset($_POST[$key]) ? (string) $_POST[$key] : '';
    $v = trim($v);
    $v = strip_tags($v);
    $v = str_replace("\r", '', $v);
    return function_exists('mb_substr') ? mb_substr($v, 0, $maxLen) : substr($v, 0, $maxLen);
}

/** Normalizes a Chilean mobile number to "+56 9 XXXX XXXX". Returns '' when invalid. */
function normalizar_telefono(string $raw): string
{
    $d = preg_replace('/[^0-9]/', '', $raw);
    if ($d === null || $d === '') {
        return '';
    }
    if (strlen($d) === 11 && substr($d, 0, 2) === '56') {
        // already has country code: 56 9 XXXX XXXX
    } elseif (strlen($d) === 9 && $d[0] === '9') {
        $d = '56' . $d;
    } else {
        return '';
    }
    if (!preg_match('/^569\d{8}$/', $d)) {
        return '';
    }
    return '+56 9 ' . substr($d, 3, 4) . ' ' . substr($d, 7, 4);
}

/** Real-data check: minimum length, contains letters, not junk ("aaaaaa", "123456"). */
function datos_reales(string $v, int $min): bool
{
    $v = trim($v);
    $len = function_exists('mb_strlen') ? mb_strlen($v) : strlen($v);
    if ($len < $min) {
        return false;
    }
    if (!preg_match('/\p{L}/u', $v)) {
        return false;
    }
    $solo_letras = preg_replace('/[^A-Za-zÁÉÍÓÚáéíóúÑñÜü]/', '', $v);
    if ($solo_letras !== null && $solo_letras !== '' && preg_match('/^(.)\1*$/u', $solo_letras)) {
        return false;
    }
    return true;
}

/** Checks that the email domain actually resolves (MX or A record). */
function dominio_correo_valido(string $email): bool
{
    if (!function_exists('checkdnsrr')) {
        return true;
    }
    $pos = strrchr($email, '@');
    if ($pos === false) {
        return false;
    }
    $dominio = substr($pos, 1);
    if ($dominio === '' || strcasecmp($dominio, 'localhost') === 0) {
        return false;
    }
    return (bool) (checkdnsrr($dominio, 'MX') || checkdnsrr($dominio, 'A'));
}

// Redirect back to the page the form was submitted from. Accepts a root
// file ("index.html") or one nested folder ("propuesta-1-clara/index.html").
$origen = campo('origen', 120);
if (!preg_match('#^[a-z0-9_\-]+(/[a-z0-9_\-]+)?\.(html?|php)$#i', $origen)) {
    $origen = 'index.html';
}

// Honeypot: humans never see this field, spam bots fill it. Pretend success.
if (isset($_POST['website']) && trim((string) $_POST['website']) !== '') {
    header('Location: ' . $origen . '?envio=ok#contacto');
    exit;
}

// Second honeypot lure with a different field-name pattern (blanket-filling bots).
if (isset($_POST['correo2']) && trim((string) $_POST['correo2']) !== '') {
    header('Location: ' . $origen . '?envio=ok#contacto');
    exit;
}

// Fill-time trap: the page records a timestamp; submissions under 3 seconds
// (or with a forged future timestamp) come from bots, not people.
$ts = isset($_POST['ts']) ? (int) $_POST['ts'] : 0;
$ahora = time();
if ($ts > 0 && (($ahora - $ts >= 0 && $ahora - $ts < 3) || ($ts - $ahora > 300))) {
    header('Location: ' . $origen . '?envio=bloqueado#contacto');
    exit;
}

// Simple per-IP rate limit: max 5 submissions per hour, file based, fails open.
function limite_envios(string $ip): bool
{
    $dir = sys_get_temp_dir() . '/ar-spam';
    if (!@mkdir($dir, 0700, true) && !is_dir($dir)) {
        return true; // storage unavailable: never block real customers
    }
    $file = $dir . '/' . md5($ip) . '.json';
    $ahora = time();
    $hits = [];
    if (is_file($file)) {
        $data = json_decode((string) @file_get_contents($file), true);
        if (is_array($data) && isset($data['hits']) && is_array($data['hits'])) {
            $hits = $data['hits'];
        }
    }
    $hits = array_values(array_filter($hits, function ($t) use ($ahora) {
        return ($ahora - (int) $t) < 3600;
    }));
    if (count($hits) >= 5) {
        return false;
    }
    $hits[] = $ahora;
    @file_put_contents($file, json_encode(['hits' => $hits]), LOCK_EX);
    return true;
}
if (!limite_envios($_SERVER['REMOTE_ADDR'] ?? 'desconocida')) {
    header('Location: ' . $origen . '?envio=bloqueado#contacto');
    exit;
}

$nombre   = campo('nombre', 120);
$empresa  = campo('empresa', 120);
$telefono = normalizar_telefono(campo('telefono', 24));
$email    = campo('email', 160);
// Spam heuristics on the raw message (before sanitization): promo spam
// carries several links or markup that real customers never type.
$mensaje_raw = isset($_POST['mensaje']) ? (string) $_POST['mensaje'] : '';
$enlaces = preg_match_all('~https?://|www\.|\[url~i', $mensaje_raw);
if ($enlaces > 3 || preg_match('~\[(?:url|img)\]|<(?:script|iframe)~i', $mensaje_raw)) {
    header('Location: ' . $origen . '?envio=bloqueado#contacto');
    exit;
}
$mensaje  = campo_multilinea('mensaje', 4000);

$invalidos = [];
if (!datos_reales($nombre, 6)) {
    $invalidos[] = 'nombre';
}
if ($telefono === '') {
    $invalidos[] = 'telefono';
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !dominio_correo_valido($email)) {
    $invalidos[] = 'email';
}
if (!datos_reales($mensaje, 6)) {
    $invalidos[] = 'mensaje';
}

if ($invalidos) {
    header('Location: ' . $origen . '?envio=incompleto#contacto');
    exit;
}

$fecha = date('d/m/Y H:i');
$ip    = $_SERVER['REMOTE_ADDR'] ?? 'desconocida';

$lineas = [
    'Nueva consulta desde la web',
    '----------------------------------------',
    'Nombre:   ' . $nombre,
];
if ($empresa !== '') {
    $lineas[] = 'Empresa:  ' . $empresa;
}
if ($telefono !== '') {
    $lineas[] = 'Teléfono: ' . $telefono;
}
$lineas[] = 'Email:    ' . $email;
$lineas[] = 'Fecha:    ' . $fecha . '  (IP: ' . $ip . ')';
$lineas[] = '----------------------------------------';
$lineas[] = '';
$lineas[] = 'Mensaje:';
$lineas[] = $mensaje;
$cuerpo = implode("\r\n", $lineas) . "\r\n";

$host = preg_replace('/[^a-z0-9.\-]/i', '', $_SERVER['HTTP_HOST'] ?? '');
if ($host === '') {
    $host = 'arodriguez.cl';
}

$cabeceras = implode("\r\n", [
    'From: Web Carrocerias A Rodriguez <no-reply@' . $host . '>',
    'Reply-To: ' . $email,
    'MIME-Version: 1.0',
    'Content-Type: text/plain; charset=UTF-8',
    'X-Mailer: PHP/' . PHP_VERSION,
]);

$asunto = '=?UTF-8?B?' . base64_encode(ASUNTO_PREFIJO . ' - ' . $nombre) . '?=';
$ok     = @mail(DESTINATARIO, $asunto, $cuerpo, $cabeceras);

header('Location: ' . $origen . '?envio=' . ($ok ? 'ok' : 'error') . '#contacto');
exit;
