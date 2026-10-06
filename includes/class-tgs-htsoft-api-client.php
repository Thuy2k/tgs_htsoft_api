<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Lớp gọi API HTsoft (ActionService.svc) — MỌI lượt gọi trong hệ thống phải đi qua đây.
 *
 *   $r = TGS_HTsoft_Api_Client::call('GetAllListStore');                       // GET
 *   $r = TGS_HTsoft_Api_Client::call('GetCustomerInfoByCallerID', ['callerid' => '09...']);  // POST JSON
 *   if ($r['ok']) { $data = $r['data']; } else { $loi = $r['error']; }
 *
 * Trả về mảng: ok, http (mã HTTP), ms, data (đã giải JSON), raw (chuỗi gốc, cắt ngắn), error.
 *
 * Ba lớp bảo vệ, rút từ sự cố 04/10/2026 (HTsoft chặn IP vì kết nối dồn dập):
 *   1. GIÃN NHỊP: hai lượt gọi liên tiếp cách nhau tối thiểu min_gap_ms (cấu hình), tính chung
 *      cho cả hệ thống — không phải riêng từng request.
 *   2. NGẮT NHANH: một lượt không tới được máy chủ (hết giờ chờ / từ chối) → nghỉ DOWN_SECONDS;
 *      trong lúc nghỉ mọi lượt gọi báo lỗi ngay, không gõ cửa HTsoft thêm.
 *   3. NHẬT KÝ: mỗi lượt gọi một dòng JSONL (hàm, mã HTTP, thời gian, lỗi) — KHÔNG ghi token,
 *      KHÔNG ghi dữ liệu gửi/nhận. Giữ KEEP_DAYS ngày.
 */
class TGS_HTsoft_Api_Client
{
    const DOWN_KEY     = 'tgs_htsoft_api_down';
    const DOWN_SECONDS = 180;
    const GAP_KEY      = 'tgs_htsoft_api_last_call';
    const KEEP_DAYS    = 10;
    const RAW_MAX      = 200000; // byte chuỗi gốc tối đa trả về cho trang kiểm tra

    /** Hàm ghi dữ liệu sang HTsoft — bản 0.1 CHƯA cho gọi (xem docs/DANH_GIA_API.md). */
    public static function is_write(string $fn): bool
    {
        return (bool) preg_match('/^(Add|Import|Insert|Update)/i', $fn);
    }

    /**
     * @param string     $fn      Tên hàm, vd 'GetAllListStore'
     * @param array|null $body    null = GET; mảng = POST JSON
     * @param array      $opts    allow_write (bool), method ('GET'|'POST'), query (array)
     */
    public static function call(string $fn, ?array $body = null, array $opts = []): array
    {
        $out = ['ok' => false, 'fn' => $fn, 'http' => 0, 'ms' => 0, 'data' => null, 'raw' => '', 'error' => ''];
        $fn = trim($fn, " /\t\n\r");
        if (!preg_match('/^[A-Za-z0-9_]+$/', $fn)) {
            $out['error'] = 'Tên hàm không hợp lệ.';
            return $out;
        }
        if (self::is_write($fn) && empty($opts['allow_write'])) {
            $out['error'] = 'Hàm ghi dữ liệu chưa được mở ở bản này.';
            return $out;
        }
        $cfg = TGS_HTsoft_Api_Config::get();
        if ($cfg['base_url'] === '') {
            $out['error'] = 'Chưa điền địa chỉ API HTsoft.';
            return $out;
        }
        if (get_site_transient(self::DOWN_KEY)) {
            $out['error'] = 'API HTsoft vừa không kết nối được — đang tạm nghỉ, thử lại sau ít phút.';
            return $out;
        }

        self::wait_gap((int) $cfg['min_gap_ms']);

        $query = isset($opts['query']) && is_array($opts['query']) ? $opts['query'] : [];
        if ($cfg['query_name'] !== '' && $cfg['query_value'] !== '') {
            $query[$cfg['query_name']] = $cfg['query_value'];
        }
        $url = $cfg['base_url'] . $cfg['service_path'] . '/' . $fn;
        if ($query) {
            $url = add_query_arg(array_map('rawurlencode', $query), $url);
        }

        $headers = ['Accept' => 'application/json'];
        if ($cfg['header1_name'] !== '' && $cfg['header1_value'] !== '') {
            $headers[$cfg['header1_name']] = $cfg['header1_value'];
        }
        if ($cfg['header2_name'] !== '' && $cfg['header2_value'] !== '') {
            $headers[$cfg['header2_name']] = $cfg['header2_value'];
        }

        $method = strtoupper((string) ($opts['method'] ?? ($body === null ? 'GET' : 'POST')));
        $args = [
            'method'      => $method,
            'timeout'     => (int) $cfg['timeout'],
            'redirection' => 0,
            'headers'     => $headers,
            'user-agent'  => 'TGS-HTsoft-API/' . TGS_HTSOFT_API_VERSION,
        ];
        if ($method !== 'GET') {
            $args['headers']['Content-Type'] = 'application/json; charset=utf-8';
            $args['body'] = wp_json_encode($body === null ? new stdClass() : $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $t0 = microtime(true);
        $res = wp_remote_request($url, $args);
        $out['ms'] = (int) round((microtime(true) - $t0) * 1000);
        update_site_option(self::GAP_KEY, microtime(true));

        if (is_wp_error($res)) {
            $out['error'] = 'Không gọi được API: ' . $res->get_error_message();
            // Không tới được máy chủ → nghỉ, đừng thử dồn.
            set_site_transient(self::DOWN_KEY, 1, self::DOWN_SECONDS);
            self::log($out);
            return $out;
        }

        $out['http'] = (int) wp_remote_retrieve_response_code($res);
        $raw = (string) wp_remote_retrieve_body($res);
        $out['raw'] = strlen($raw) > self::RAW_MAX ? substr($raw, 0, self::RAW_MAX) : $raw;
        $out['bytes'] = strlen($raw);

        // WCF trả JSON; đôi khi bọc thêm một lớp chuỗi ("{...}") — giải hai lần nếu cần.
        $data = json_decode($raw, true);
        if (is_string($data)) {
            $inner = json_decode($data, true);
            if ($inner !== null) {
                $data = $inner;
            }
        }
        $out['data'] = $data;

        if ($out['http'] < 200 || $out['http'] >= 300) {
            $out['error'] = 'API trả mã HTTP ' . $out['http'] . '.';
        } elseif ($data === null && trim($raw) !== '' && strtolower(trim($raw)) !== 'null') {
            $out['error'] = 'API trả về nội dung không phải JSON.';
        } else {
            $out['ok'] = true;
            // Nhiều hàm trả kèm responseCode: 00 = thành công, 01 = lỗi, 02 = trùng.
            if (is_array($data) && isset($data['responseCode']) && (string) $data['responseCode'] !== '00'
                && (string) $data['responseCode'] !== '') {
                $out['ok'] = false;
                $out['error'] = 'HTsoft báo mã ' . $data['responseCode'] . ': ' . (string) ($data['message'] ?? '');
            }
        }
        self::log($out);
        return $out;
    }

    /** Bỏ cờ "đang nghỉ" để thử lại ngay (sau khi sửa cấu hình). */
    public static function clear_down(): void
    {
        delete_site_transient(self::DOWN_KEY);
    }

    /** Chờ cho đủ khoảng nghỉ tối thiểu kể từ lượt gọi trước (tính chung cả hệ thống). */
    private static function wait_gap(int $gap_ms): void
    {
        if ($gap_ms <= 0) {
            return;
        }
        $last = (float) get_site_option(self::GAP_KEY, 0);
        $wait = ($last + $gap_ms / 1000) - microtime(true);
        if ($wait > 0) {
            usleep((int) round(min($wait, 10) * 1000000));
        }
    }

    /* ── Ngày giờ kiểu WCF: "/Date(1727715600000+0700)/" ─────────────────────────── */

    /** Đổi 'Y-m-d' hoặc 'Y-m-d H:i:s' (giờ VN) sang chuỗi ngày WCF. */
    public static function wcf_date(string $local): string
    {
        $tz = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('Asia/Ho_Chi_Minh');
        try {
            $dt = new DateTime($local, $tz);
        } catch (\Throwable $e) {
            $dt = new DateTime('now', $tz);
        }
        return '/Date(' . ($dt->getTimestamp() * 1000) . $dt->format('O') . ')/';
    }

    /** Đổi chuỗi ngày WCF về 'Y-m-d H:i:s' giờ VN ('' nếu không đúng dạng). */
    public static function from_wcf_date($v): string
    {
        if (!is_string($v) || !preg_match('#/Date\((-?\d+)([+-]\d{4})?\)/#', $v, $m)) {
            return '';
        }
        $tz = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('Asia/Ho_Chi_Minh');
        $dt = (new DateTime('@' . (int) floor(((float) $m[1]) / 1000)))->setTimezone($tz);
        return $dt->format('Y-m-d H:i:s');
    }

    /* ── Nhật ký gọi API ─────────────────────────────────────────────────────────── */

    private static function log_dir(): string
    {
        return trailingslashit(WP_CONTENT_DIR) . 'uploads/tgs-htsoft-logs/';
    }

    private static function log(array $r): void
    {
        try {
            $d = self::log_dir();
            if (!is_dir($d) && !wp_mkdir_p($d)) {
                return;
            }
            if (!file_exists($d . '.htaccess')) {
                @file_put_contents($d . '.htaccess', "Require all denied\nDeny from all\n");
            }
            $file = $d . 'api-call-' . current_time('Y-m-d') . '.jsonl';
            $is_new = !file_exists($file);
            $line = wp_json_encode([
                't'    => current_time('Y-m-d H:i:s'),
                'fn'   => $r['fn'],
                'http' => $r['http'],
                'ms'   => $r['ms'],
                'ok'   => $r['ok'] ? 1 : 0,
                'b'    => (int) ($r['bytes'] ?? 0),
                'err'  => $r['ok'] ? '' : mb_substr((string) $r['error'], 0, 200),
            ], JSON_UNESCAPED_UNICODE);
            @file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);
            if ($is_new) {
                $min = wp_date('Y-m-d', current_time('timestamp', true) - (self::KEEP_DAYS - 1) * DAY_IN_SECONDS);
                foreach ((array) glob($d . 'api-call-*.jsonl') as $f) {
                    if (preg_match('/api-call-(\d{4}-\d{2}-\d{2})\.jsonl$/', (string) $f, $m) && $m[1] < $min) {
                        @unlink($f);
                    }
                }
            }
        } catch (\Throwable $e) {
            // nhật ký hỏng không được làm hỏng lượt gọi
        }
    }

    /** Vài chục dòng nhật ký gần nhất của hôm nay (cho trang kiểm tra). */
    public static function recent_log(int $n = 30): array
    {
        $file = self::log_dir() . 'api-call-' . current_time('Y-m-d') . '.jsonl';
        if (!is_file($file)) {
            return [];
        }
        $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return [];
        }
        $out = [];
        foreach (array_reverse(array_slice($lines, -$n)) as $l) {
            $r = json_decode($l, true);
            if (is_array($r)) {
                $out[] = $r;
            }
        }
        return $out;
    }
}
