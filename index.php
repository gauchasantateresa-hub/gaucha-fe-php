<?php
/**
 * Facturador Electrónico CR v4.4 — Gaucha Sur
 * API PHP usando firmador de CRLibre (probado)
 */

date_default_timezone_set("America/Costa_Rica");

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, X-Webhook-Secret");
header("Content-Type: application/json; charset=utf-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Config
define('CERT_PATH',    __DIR__ . '/certificado.p12');
define('CERT_PIN',     getenv('CERT_PIN')     ?: '5561');
define('MH_USUARIO',   getenv('MH_USUARIO')   ?: 'cpj-3-102-807442@prod.comprobanteselectronicos.go.cr');
define('MH_PASSWORD',  getenv('MH_PASSWORD')  ?: 'D&^&cDVw6xzHQHIjP6Vc');
define('WEBHOOK_SEC',  getenv('WEBHOOK_SECRET') ?: 'gaucha2026');
define('PRODUCCION',   true);

$TOKEN_CACHE = ['token' => null, 'expira' => 0];

// ── Router ────────────────────────────────────────────────────────────────────
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($path === '/' || $path === '') {
    echo json_encode([
        'status'     => 'ok',
        'facturador' => 'Gaucha FE v4.4 PHP — CRLibre',
        'produccion' => PRODUCCION,
    ]);
    exit;
}

if ($path === '/tiquete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    check_auth();
    $d     = json_decode(file_get_contents('php://input'), true) ?: [];
    $monto = floatval($d['monto'] ?? 0);
    $fecha = $d['fecha'] ?? date('Y-m-d');
    $ref   = $d['referencia'] ?? 'SIN-REF';
    $medio = $d['medio_pago'] ?? '02';
    if ($monto <= 0) { error('Monto inválido', 400); }
    $res = emitir($monto, $fecha, $ref, $medio);
    echo json_encode($res);
    exit;
}

if ($path === '/webhook' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    check_auth();
    $d      = json_decode(file_get_contents('php://input'), true) ?: [];
    $name   = $d['name']   ?? 'N/A';
    $amount = floatval($d['amount_total'] ?? 0);
    $date   = $d['date_order'] ?? date('Y-m-d');
    $method = strtolower($d['payment_method_name'] ?? '');
    $state  = $d['state'] ?? '';

    $tarjeta_kw = ['tarjeta','card','visa','mastercard','amex','credito','debito'];
    $es_tarjeta = false;
    foreach ($tarjeta_kw as $kw) {
        if (strpos($method, $kw) !== false) { $es_tarjeta = true; break; }
    }

    if (!in_array($state, ['done','invoiced','paid'])) {
        echo json_encode(['status' => 'skipped', 'razon' => "Estado $state"]);
        exit;
    }
    if (!$es_tarjeta) {
        echo json_encode(['status' => 'skipped', 'razon' => 'No es tarjeta']);
        exit;
    }
    if ($amount <= 0) {
        echo json_encode(['status' => 'skipped', 'razon' => 'Monto inválido']);
        exit;
    }

    $res = emitir($amount, $date, $name, '02');
    echo json_encode($res);
    exit;
}

echo json_encode(['error' => 'Not found', 'path' => $path]);

// ── Funciones ─────────────────────────────────────────────────────────────────

function check_auth() {
    $headers = getallheaders();
    $secret  = $headers['X-Webhook-Secret'] ?? $headers['x-webhook-secret'] ?? '';
    if ($secret !== WEBHOOK_SEC) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
}

function error($msg, $code = 500) {
    http_response_code($code);
    echo json_encode(['error' => $msg]);
    exit;
}

function emitir($monto_con_iva, $fecha_str, $referencia, $medio_pago = '02') {
    $precio_sin_iva = round($monto_con_iva / 1.13, 5);

    // Parsear fecha
    $fecha = strtotime(substr($fecha_str, 0, 19)) ?: time();

    // 1. Generar clave y consecutivo
    $datos_clave = generar_clave($fecha);

    // 2. Generar XML
    $xml = generar_xml(
        $datos_clave['clave'],
        $datos_clave['consecutivo'],
        $fecha,
        $precio_sin_iva,
        $monto_con_iva,
        $medio_pago
    );

    // 3. Firmar con CRLibre
    $xml_firmado = firmar_xml($xml);
    if (!$xml_firmado) {
        return ['status' => 'error', 'message' => 'Error al firmar XML'];
    }

    // 4. Token Hacienda
    $token = obtener_token();
    if (!$token) {
        return ['status' => 'error', 'message' => 'Error obteniendo token Hacienda'];
    }

    // 5. Enviar
    $resultado = enviar_comprobante($token, $datos_clave['clave'], date('Y-m-d\TH:i:s-06:00', $fecha), $xml_firmado);

    if ($resultado['ok']) {
        return [
            'status'       => 'ok',
            'clave'        => $datos_clave['clave'],
            'consecutivo'  => $datos_clave['consecutivo'],
            'referencia'   => $referencia,
            'hacienda_status' => $resultado['status'],
        ];
    }
    return [
        'status'  => 'error',
        'message' => $resultado['mensaje'] ?? 'Error Hacienda',
        'clave'   => $datos_clave['clave'],
    ];
}

// Consecutivo persistido en archivo simple
function siguiente_consecutivo() {
    $f = sys_get_temp_dir() . '/gaucha_consec.txt';
    $n = file_exists($f) ? (int)file_get_contents($f) : 1;
    file_put_contents($f, $n + 1);
    return $n;
}

function generar_clave($fecha) {
    $cedula         = '3102807442';
    $codigo_pais    = '506';
    $sucursal       = '001';
    $terminal       = '00001';
    $tipo_doc       = '04';
    $situacion      = '1';
    $codigo_seg     = str_pad(rand(10000000, 99999999), 8, '0', STR_PAD_LEFT);

    $dia = date('d', $fecha);
    $mes = date('m', $fecha);
    $ano = date('y', $fecha);

    $consecutivo_num = siguiente_consecutivo();
    $consecutivo = $sucursal . $terminal . $tipo_doc . str_pad($consecutivo_num, 10, '0', STR_PAD_LEFT);

    // Cedula jurídica 12 dígitos
    $identificacion = str_pad($cedula, 12, '0', STR_PAD_LEFT);

    $clave = $codigo_pais . $dia . $mes . $ano . $identificacion . $consecutivo . $situacion . $codigo_seg;

    if (strlen($clave) !== 50) {
        error("Clave generada inválida: " . strlen($clave) . " dígitos");
    }

    return ['clave' => $clave, 'consecutivo' => $consecutivo];
}

function generar_xml($clave, $consecutivo, $fecha, $precio_sin_iva, $monto_total, $medio_pago) {
    $monto_iva   = round($precio_sin_iva * 0.13, 5);
    $total       = round($precio_sin_iva + $monto_iva, 5);
    $fecha_str   = date('Y-m-d\TH:i:s', $fecha) . '-06:00';

    $fmt = function($n) { return rtrim(rtrim(number_format($n, 5, '.', ''), '0'), '.'); };

    return '<?xml version="1.0" encoding="UTF-8"?>' . "\n" .
'<TiqueteElectronico xmlns="https://cdn.comprobanteselectronicos.go.cr/xml-schemas/v4.4/tiqueteElectronico" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="https://cdn.comprobanteselectronicos.go.cr/xml-schemas/v4.4/tiqueteElectronico https://cdn.comprobanteselectronicos.go.cr/xml-schemas/v4.4/tiqueteElectronico.xsd">
<Clave>' . $clave . '</Clave>
<CodigoActividad>5610000</CodigoActividad>
<NumeroConsecutivo>' . $consecutivo . '</NumeroConsecutivo>
<FechaEmision>' . $fecha_str . '</FechaEmision>
<ProveedorSistemas>GauchaSur-FE-PHP v1.0</ProveedorSistemas>
<Emisor>
<Nombre>Tres Bochas Sociedad De Responsabilidad Limitada</Nombre>
<Identificacion><Tipo>02</Tipo><Numero>3102807442</Numero></Identificacion>
<Ubicacion>
<Provincia>6</Provincia><Canton>01</Canton><Distrito>01</Distrito>
<OtrasSenas>Frente Antigua Discoteca Lora Amarilla, Santa Teresa</OtrasSenas>
</Ubicacion>
<Telefono><CodigoPais>506</CodigoPais><NumTelefono>60000000</NumTelefono></Telefono>
<CorreoElectronico>gauchasantateresa@gmail.com</CorreoElectronico>
</Emisor>
<CondicionVenta>01</CondicionVenta>
<MedioPago>' . $medio_pago . '</MedioPago>
<DetalleServicio>
<LineaDetalle>
<NumeroLinea>1</NumeroLinea>
<CodigoComercial><Tipo>04</Tipo><Codigo>6331000000000</Codigo></CodigoComercial>
<Cantidad>1</Cantidad>
<UnidadMedida>Sp</UnidadMedida>
<Detalle>Servicio de Restaurante</Detalle>
<PrecioUnitario>' . $fmt($precio_sin_iva) . '</PrecioUnitario>
<MontoTotal>' . $fmt($precio_sin_iva) . '</MontoTotal>
<SubTotal>' . $fmt($precio_sin_iva) . '</SubTotal>
<Impuesto>
<Codigo>01</Codigo>
<CodigoTarifaIVA>08</CodigoTarifaIVA>
<Tarifa>13</Tarifa>
<Monto>' . $fmt($monto_iva) . '</Monto>
</Impuesto>
<MontoTotalLinea>' . $fmt($total) . '</MontoTotalLinea>
</LineaDetalle>
</DetalleServicio>
<ResumenFactura>
<CodigoTipoMoneda><CodigoMoneda>CRC</CodigoMoneda><TipoCambio>1</TipoCambio></CodigoTipoMoneda>
<TotalServGravados>' . $fmt($precio_sin_iva) . '</TotalServGravados>
<TotalServExentos>0</TotalServExentos>
<TotalServExonerado>0</TotalServExonerado>
<TotalMercanciasGravadas>0</TotalMercanciasGravadas>
<TotalMercanciasExentas>0</TotalMercanciasExentas>
<TotalMercExonerada>0</TotalMercExonerada>
<TotalGravado>' . $fmt($precio_sin_iva) . '</TotalGravado>
<TotalExento>0</TotalExento>
<TotalExonerado>0</TotalExonerado>
<TotalVenta>' . $fmt($precio_sin_iva) . '</TotalVenta>
<TotalDescuentos>0</TotalDescuentos>
<TotalVentaNeta>' . $fmt($precio_sin_iva) . '</TotalVentaNeta>
<TotalImpuesto>' . $fmt($monto_iva) . '</TotalImpuesto>
<TotalIVADevuelto>0</TotalIVADevuelto>
<TotalOtrosCargos>0</TotalOtrosCargos>
<TotalComprobante>' . $fmt($total) . '</TotalComprobante>
</ResumenFactura>
</TiqueteElectronico>';
}

function firmar_xml($xml) {
    // Escribir XML temporal
    $tmp_in  = tempnam(sys_get_temp_dir(), 'gaucha_in_')  . '.xml';
    $tmp_out = tempnam(sys_get_temp_dir(), 'gaucha_out_') . '.xml';

    file_put_contents($tmp_in, $xml);

    // Llamar al firmador de CRLibre
    $signer_path = __DIR__ . '/cli-signer.php';
    $cert_path   = CERT_PATH;
    $pin         = CERT_PIN;

    $cmd = escapeshellcmd("php $signer_path") .
           ' ' . escapeshellarg($cert_path) .
           ' ' . escapeshellarg($pin) .
           ' ' . escapeshellarg($tmp_in) .
           ' ' . escapeshellarg($tmp_out) .
           ' 2>/dev/null';

    exec($cmd, $output, $ret);

    $xml_firmado = null;
    if ($ret === 0 && file_exists($tmp_out)) {
        $xml_firmado = file_get_contents($tmp_out);
    }

    @unlink($tmp_in);
    @unlink($tmp_out);

    return $xml_firmado;
}

function obtener_token() {
    global $TOKEN_CACHE;

    $ahora = time();
    if ($TOKEN_CACHE['token'] && $ahora < $TOKEN_CACHE['expira'] - 60) {
        return $TOKEN_CACHE['token'];
    }

    $url  = PRODUCCION
        ? 'https://idp.comprobanteselectronicos.go.cr/auth/realms/rut/protocol/openid-connect/token'
        : 'https://idp.comprobanteselectronicos.go.cr/auth/realms/rut-stag/protocol/openid-connect/token';

    $data = http_build_query([
        'client_id'     => PRODUCCION ? 'api-prod' : 'api-stag',
        'client_secret' => '',
        'grant_type'    => 'password',
        'username'      => MH_USUARIO,
        'password'      => MH_PASSWORD,
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $data,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200) return null;

    $j = json_decode($resp, true);
    if (!isset($j['access_token'])) return null;

    $TOKEN_CACHE['token']  = $j['access_token'];
    $TOKEN_CACHE['expira'] = $ahora + ($j['expires_in'] ?? 300);

    return $TOKEN_CACHE['token'];
}

function enviar_comprobante($token, $clave, $fecha_emision, $xml_firmado) {
    $url = PRODUCCION
        ? 'https://api.comprobanteselectronicos.go.cr/recepcion/v1/recepcion/'
        : 'https://api-sandbox.comprobanteselectronicos.go.cr/recepcion/v1/recepcion/';

    $payload = json_encode([
        'clave'  => $clave,
        'fecha'  => $fecha_emision,
        'emisor' => [
            'tipoIdentificacion'   => '02',
            'numeroIdentificacion' => '3102807442',
        ],
        'comprobanteXml' => base64_encode($xml_firmado),
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => 'POST',
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            'Authorization: bearer ' . $token,
            'Content-Type: application/json',
        ],
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'ok'      => $code === 202,
        'status'  => $code,
        'mensaje' => $resp,
    ];
}
