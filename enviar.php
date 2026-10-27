<?php
/**
 * Contact form handler - Carrocerias Alejandra Rodriguez SpA
 *
 * NOTE: per-proposal copy of ../enviar.php. Any fix must be applied to
 * BOTH copies, otherwise the proposals drift apart.
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
// Flow: the form notifies the owner through the web@ mailbox, and web@ then
// answers the visitor at whatever address the form was filled in with.
// Production destination, as deployed on the FTP. The local checkout overrides
// it with the owner's Gmail through the git-ignored mail.local.php, so test
// mail never reaches a client mailbox.
const DESTINATARIO   = 'ventas@arodriguez.cl';
const ASUNTO_PREFIJO = '[Web] Consulta de cotización';
// Reply-To of the notification itself: both mails are sent by web@arodriguez.cl
// (the gateway), and the team answers from that mailbox.
const CORREO_RESPUESTA = 'web@arodriguez.cl';
// Public site, linked from the logo and the footer of every email.
const URL_SITIO = 'https://arodriguez.cl/';
// ------------------------------------------------------------------
// Outbound mail
//
// Two paths:
//   1. SMTP with user/password (preferred). Credentials come from the
//      environment in production (MAIL_SMTP_*) so nothing secret is ever
//      committed; the git-ignored mail.local.php overrides them for tests.
//   2. The local MTA via mail(), used when SMTP is not configured.
//
// The local MTA cannot deliver from this machine: the outgoing IP is absent
// from the SPF of arodriguez.cl and Gmail rejects the message with
// "550-5.7.1 ... not authorized to send email directly to our servers".
// Authenticated SMTP is what actually gets mail out.
// ------------------------------------------------------------------

/** SMTP settings: environment first (production), then mail.local.php, then defaults. */
function smtp_config(): array
{
    $local = __DIR__ . '/mail.local.php';
    $extra = is_file($local) ? (array) require $local : [];
    $definidos = [
        'host'      => 'smtp.gmail.com',
        'port'      => 465,
        'user'      => '',
        'password'  => '',
        'from'      => '',
        // Business notification recipient. The repository default is the
        // production mailbox; only the git-ignored local config overrides it,
        // so the deployed file never has to be edited by hand.
        'destinatario' => '',
        // true: upgrade to TLS with STARTTLS on non-465 ports. Only turn it
        // off against a local test sink; never against a real provider.
        'starttls'  => true,
    ];
    $cfg = [];
    foreach ($definidos as $clave => $por_defecto) {
        $entorno = getenv('MAIL_SMTP_' . strtoupper($clave));
        $cfg[$clave] = ($entorno !== false && $entorno !== '')
            ? $entorno
            : ($extra[$clave] ?? $por_defecto);
    }
    $cfg['port']     = (int) $cfg['port'];
    $cfg['starttls'] = filter_var($cfg['starttls'], FILTER_VALIDATE_BOOLEAN);
    return $cfg;
}

/** Reads a (possibly multi-line) SMTP reply and returns [code, text]. */
function smtp_reply($socket): array
{
    $texto = '';
    do {
        $linea = fgets($socket, 1024);
        if ($linea === false) {
            break;
        }
        $texto .= $linea;
    } while (isset($linea[3]) && $linea[3] === '-');
    return [(int) substr($texto, 0, 3), $texto];
}

/** Sends one command and asserts the reply code. */
function smtp_paso($socket, string $comando, int $esperado): bool
{
    fwrite($socket, $comando . "\r\n");
    [$codigo] = smtp_reply($socket);
    return $codigo === $esperado;
}

/**
 * Sends one message over SMTP. Implicit TLS on port 465, STARTTLS otherwise.
 * Returns true only when the server accepted the message with code 250.
 */
function enviar_smtp(array $cfg, string $para, string $asunto, string $texto, string $html, string $de, string $responder_a): bool
{
    $host   = (string) $cfg['host'];
    $puerto = (int) $cfg['port'];
    $tls    = $puerto === 465;
    // Non-465 ports upgrade with STARTTLS unless the config says otherwise.
    $arranque_tls = !$tls && !empty($cfg['starttls']);
    $ctx    = stream_context_create(['ssl' => [
        'verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $host,
    ]]);
    $errno = 0;
    $errstr = '';
    $socket = @stream_socket_client(
        ($tls ? 'ssl://' : 'tcp://') . $host . ':' . $puerto,
        $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $ctx
    );
    if (!$socket) {
        return false;
    }
    stream_set_timeout($socket, 20);

    [$codigo] = smtp_reply($socket);
    if ($codigo !== 220 || !smtp_paso($socket, 'EHLO ' . gethostname(), 250)) {
        fclose($socket);
        return false;
    }
    if ($arranque_tls) {
        $ok = smtp_paso($socket, 'STARTTLS', 220)
            && @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)
            && smtp_paso($socket, 'EHLO ' . gethostname(), 250);
        if (!$ok) {
            fclose($socket);
            return false;
        }
    }
    $ok = smtp_paso($socket, 'AUTH LOGIN', 334)
        && smtp_paso($socket, base64_encode((string) $cfg['user']), 334)
        && smtp_paso($socket, base64_encode((string) $cfg['password']), 235)
        && smtp_paso($socket, 'MAIL FROM:<' . $cfg['user'] . '>', 250)
        && smtp_paso($socket, 'RCPT TO:<' . $para . '>', 250)
        && smtp_paso($socket, 'DATA', 354);
    if (!$ok) {
        fclose($socket);
        return false;
    }

    $cabeceras = [
        'From: ' . $de,
        'To: ' . $para,
        'Subject: ' . $asunto,
        'Date: ' . date('r'),
        // Message-ID must carry our own domain, not the SMTP relay's host.
        'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . DOMINIO_ENVIO . '>',
        'MIME-Version: 1.0',
    ];
    if ($responder_a !== '') {
        $cabeceras[] = 'Reply-To: ' . $responder_a;
    }

    // Plain text always travels, HTML only when a template rendered. Clients
    // that cannot show HTML (and spam filters) rely on the text part.
    $texto = str_replace(["\r\n", "\r", "\n"], "\r\n", $texto);
    if ($html !== '') {
        $borde_alt = 'a_' . bin2hex(random_bytes(8));
        $alternativa = '--' . $borde_alt . "\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n\r\n"
            . $texto . "\r\n"
            . '--' . $borde_alt . "\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n\r\n"
            . str_replace(["\r\n", "\r", "\n"], "\r\n", $html) . "\r\n"
            . '--' . $borde_alt . "--\r\n";

        // The logo is attached as a CID referenced by <img src="cid:logo"> in
        // the template, wrapped in multipart/related. A data: URI would be
        // stripped by Gmail and an external URL is not usable yet.
        $logo = logo_ruta();
        if ($logo !== '') {
            $borde_rel = 'r_' . bin2hex(random_bytes(8));
            $cabeceras[] = 'Content-Type: multipart/related; boundary="' . $borde_rel . '"';
            $mensaje = '--' . $borde_rel . "\r\n"
                . 'Content-Type: multipart/alternative; boundary="' . $borde_alt . '"' . "\r\n\r\n"
                . $alternativa . "\r\n"
                . '--' . $borde_rel . "\r\n"
                . 'Content-Type: image/png; name="logo.png"' . "\r\n"
                . 'Content-ID: <logo>' . "\r\n"
                . 'Content-Disposition: inline; filename="logo.png"' . "\r\n"
                . "Content-Transfer-Encoding: base64\r\n\r\n"
                . chunk_split(base64_encode((string) file_get_contents($logo)), 76, "\r\n")
                . '--' . $borde_rel . "--\r\n";
        } else {
            $cabeceras[] = 'Content-Type: multipart/alternative; boundary="' . $borde_alt . '"';
            $mensaje = $alternativa;
        }
    } else {
        $cabeceras[] = "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit";
        $mensaje = $texto;
    }

    // Dot stuffing: only lines that already start with "." get an extra dot.
    // Prefixing every line would deliver a stray "." at the start of each line.
    $envoltura = preg_replace('/^\./m', '..', implode("\r\n", $cabeceras) . "\r\n\r\n" . $mensaje);
    $aceptado  = smtp_paso($socket, $envoltura . "\r\n.", 250);
    smtp_paso($socket, 'QUIT', 221);
    fclose($socket);
    return $aceptado;
}

/** Escapes a value for the HTML templates. Callers must use this for every user input. */
function e(string $v): string
{
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

/**
 * Path of the logo attached to every HTML email. The site is not published
 * yet, so an external URL would render as a broken image; the logo travels as
 * a CID attachment inside multipart/related, which Gmail, Outlook and Apple
 * Mail all render. Returns an empty string when the file is missing so the
 * message still goes out showing the alt text.
 */
function logo_ruta(): string
{
    $ruta = __DIR__ . '/img/logo-email.png';
    return is_file($ruta) ? $ruta : '';
}

/**
 * Renders a template from templates/emails/. Returns an empty string when the
 * file is missing so a broken deploy degrades to the plain-text email instead
 * of losing the enquiry.
 */
function plantilla(string $archivo, array $datos): string
{
    $ruta = __DIR__ . '/templates/emails/' . basename($archivo);
    if (!is_file($ruta)) {
        return '';
    }
    $html = (string) file_get_contents($ruta);
    $html = str_replace('{{LOGO}}', logo_ruta() !== '' ? 'cid:logo' : '', $html);
    $html = str_replace('{{SITIO}}', e(URL_SITIO), $html);
    foreach ($datos as $clave => $valor) {
        $html = str_replace('{{' . strtoupper($clave) . '}}', (string) $valor, $html);
    }
    return $html;
}

/**
 * Sends one message, preferring authenticated SMTP and falling back to the
 * local MTA when SMTP credentials are not configured.
 */
function enviar_correo(string $para, string $asunto, string $texto, string $html, string $cabeceras, string $responder_a = ''): bool
{
    $cfg = smtp_config();
    if ($cfg['user'] === '' || $cfg['password'] === '') {
        return (bool) @mail($para, $asunto, $texto, $cabeceras);
    }
    $desde = $cfg['from'] !== '' ? $cfg['from'] : $cfg['user'];
    return enviar_smtp($cfg, $para, $asunto, $texto, $html, 'Carrocerias A Rodriguez <' . $desde . '>', $responder_a);
}

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

// From domain is fixed on purpose: deriving it from HTTP_HOST made requests
// sent by IP ("10.105.22.228:8080") or with a spoofed Host header build a
// non-existent domain, and providers such as Gmail then bounced the message
// with "550-5.7.1 'From' header has non compliant domain name" (RFC 5322),
// losing the enquiry while the visitor was told it had been sent. SPF/DMARC
// also require the From domain to be one we actually control.
const DOMINIO_ENVIO = 'arodriguez.cl';
$host = DOMINIO_ENVIO;

$cabeceras = implode("\r\n", [
    'From: Web Carrocerias A Rodriguez <no-reply@' . $host . '>',
    'Reply-To: ' . $email,
    'MIME-Version: 1.0',
    'Content-Type: text/plain; charset=UTF-8',
    'X-Mailer: PHP/' . PHP_VERSION,
]);

// HTML body for the notification, rendered from templates/emails/. Every value
// goes through e() because $nombre and $mensaje are user input.
$filaEmpresa = '';
if ($empresa !== '') {
    $filaEmpresa = '<tr>'
        . '<td width="120" style="padding:11px 14px;font-size:12px;font-weight:800;color:#888888;text-transform:uppercase;letter-spacing:.4px;border-bottom:1px solid #eeeeee;background:#fafafa">Empresa</td>'
        . '<td style="padding:11px 14px;font-size:15px;color:#111111;border-bottom:1px solid #eeeeee">' . e($empresa) . '</td>'
        . '</tr>';
}
$htmlNegocio = plantilla('consulta_negocio.html', [
    'NOMBRE'       => e($nombre),
    'NOMBRE_CORTO' => e(mb_substr($nombre, 0, 40)),
    'FILA_EMPRESA' => $filaEmpresa,
    'TELEFONO'     => $telefono !== '' ? e($telefono) : 'No informado',
    'EMAIL'        => e($email),
    'FECHA'        => e($fecha),
    'IP'           => e($ip),
    'MENSAJE'      => nl2br(e($mensaje)),
]);

$asunto = '=?UTF-8?B?' . base64_encode(ASUNTO_PREFIJO . ' - ' . $nombre) . '?=';
// Destination rule: production reads the constant below; the local checkout
// overrides it through mail.local.php so test mail never reaches a client.
$destinatario = smtp_config()['destinatario'] !== '' ? smtp_config()['destinatario'] : DESTINATARIO;
$ok           = enviar_correo($destinatario, $asunto, $cuerpo, $htmlNegocio, $cabeceras, $email);

// Acknowledgement to the person who wrote in. Sent only after the main
// notification succeeded, and never allowed to break the submission: if this
// fails the enquiry is already with us and the visitor must see the same
// result either way.
if ($ok && $email !== '') {
    $lineasAviso = [
        'Hola ' . $nombre . ':',
        '',
        'Gracias por escribirnos. Recibimos tu consulta y ya la estamos revisando.',
        '',
        'Te contactaremos a la brevedad al correo que nos dejaste'
            . ($telefono !== '' ? ' o al teléfono ' . $telefono : '') . '.',
        '',
        'Si quieres adelantarnos algo mientras tanto, puedes responder este mismo',
        'mensaje o escribirnos por WhatsApp al +56 9 6847 6557.',
        '',
        'Un saludo,',
        'Carrocerías A. Rodríguez',
    ];
    $asuntoAviso = '=?UTF-8?B?' . base64_encode('Recibimos tu consulta - Carrocerías A. Rodríguez') . '?=';
    $cabecerasAviso = implode("\r\n", [
        'From: Carrocerias A Rodriguez <no-reply@' . $host . '>',
        'Reply-To: ' . CORREO_RESPUESTA,
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'X-Mailer: PHP/' . PHP_VERSION,
    ]);
    $htmlAviso = plantilla('acuse_cliente.html', [
        'NOMBRE'   => e($nombre),
        'TELEFONO' => $telefono !== '' ? ' o al teléfono ' . e($telefono) : '',
    ]);
    // The visitor's Reply must reach the notified destination (DESTINATARIO or
    // its mail.local.php override), not the gateway mailbox.
    enviar_correo($email, $asuntoAviso, implode("\r\n", $lineasAviso) . "\r\n", $htmlAviso, $cabecerasAviso, $destinatario);
}

header('Location: ' . $origen . '?envio=' . ($ok ? 'ok' : 'error') . '#contacto');
exit;
