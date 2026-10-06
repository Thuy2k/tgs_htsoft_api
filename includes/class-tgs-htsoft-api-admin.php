<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Trang "Kiểm tra API HTsoft" — Quản trị → Công cụ đối chiếu. Chỉ quản trị cấp cao.
 * Lưu cấu hình + bấm thử từng hàm CHỈ ĐỌC. Không gọi được hàm ghi từ đây.
 */
class TGS_HTsoft_Api_Admin
{
    const VIEW  = 'htsoft-api-test';
    const NONCE = 'tgs_htsoft_api';

    public static function init()
    {
        add_filter('tgs_shop_dashboard_routes', [__CLASS__, 'register_route']);
        add_filter('tgs_shop_workflow_nav', [__CLASS__, 'add_nav_item'], 40, 2);
        add_action('wp_ajax_tgs_htsoft_api_save', [__CLASS__, 'ajax_save']);
        add_action('wp_ajax_tgs_htsoft_api_call', [__CLASS__, 'ajax_call']);
        add_action('wp_ajax_tgs_htsoft_api_preview', [__CLASS__, 'ajax_preview']);
    }

    public static function can_use(): bool
    {
        return is_super_admin() || current_user_can('manage_network_options');
    }

    public static function register_route($routes)
    {
        $routes[self::VIEW] = ['Kiểm tra API HTsoft', TGS_HTSOFT_API_DIR . 'admin/page-htsoft-api.php'];
        return $routes;
    }

    public static function add_nav_item($nav, $current_view = '')
    {
        if (!self::can_use() || !isset($nav['admin']['sections'])) {
            return $nav;
        }
        $item = ['view' => self::VIEW, 'label' => 'Kiểm tra API HTsoft', 'icon' => 'bx bx-plug'];
        foreach ($nav['admin']['sections'] as $i => $section) {
            if ((($section['key'] ?? '') === 'recon-tools') || (($section['heading'] ?? '') === 'Công cụ đối chiếu')) {
                $nav['admin']['sections'][$i]['items'][] = $item;
                return $nav;
            }
        }
        $nav['admin']['sections'][] = ['key' => 'recon-tools', 'heading' => 'Công cụ đối chiếu', 'icon' => 'bx bx-git-compare', 'items' => [$item]];
        return $nav;
    }

    private static function guard(): void
    {
        check_ajax_referer(self::NONCE, 'nonce');
        if (!self::can_use()) {
            wp_send_json_error(['message' => 'Chỉ quản trị cấp cao dùng được trang này.'], 403);
        }
    }

    public static function ajax_save()
    {
        self::guard();
        $in = [];
        foreach (array_keys(TGS_HTsoft_Api_Config::defaults()) as $k) {
            if (isset($_POST[$k])) {
                $in[$k] = wp_unslash($_POST[$k]);
            }
        }
        TGS_HTsoft_Api_Config::save($in);
        TGS_HTsoft_Api_Client::clear_down(); // vừa sửa cấu hình → cho thử lại ngay
        wp_send_json_success(['config' => TGS_HTsoft_Api_Config::for_display(), 'message' => 'Đã lưu cấu hình.']);
    }

    /** Thay các chỗ thế {{...}} trong dữ liệu mẫu bằng giá trị người dùng nhập. */
    private static function fill($v, array $vars)
    {
        if (is_array($v)) {
            foreach ($v as $k => $x) {
                $v[$k] = self::fill($x, $vars);
            }
            return $v;
        }
        if (is_string($v)) {
            return strtr($v, $vars);
        }
        return $v;
    }

    public static function ajax_call()
    {
        self::guard();
        $fn = sanitize_text_field(wp_unslash($_POST['fn'] ?? ''));
        $def = TGS_HTsoft_Api_Catalog::get($fn);
        if (!$def) {
            // Chỉ cho gọi hàm có trong danh sách chỉ-đọc — không gọi được hàm ghi hay hàm lạ từ trang này.
            wp_send_json_error(['message' => 'Hàm này không nằm trong danh sách kiểm tra (chỉ đọc).']);
        }
        $date = sanitize_text_field(wp_unslash($_POST['date'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = current_time('Y-m-d');
        }
        $vars = [
            '{{TODAY_START}}' => TGS_HTsoft_Api_Client::wcf_date($date . ' 00:00:00'),
            '{{TODAY_END}}'   => TGS_HTsoft_Api_Client::wcf_date($date . ' 23:59:59'),
            '{{WAREHOUSE}}'   => sanitize_text_field(wp_unslash($_POST['warehouse'] ?? '')),
            '{{SKU}}'         => sanitize_text_field(wp_unslash($_POST['sku'] ?? '')),
            '{{PHONE}}'       => preg_replace('/\D+/', '', (string) wp_unslash($_POST['phone'] ?? '')),
            '{{INVOICE}}'     => sanitize_text_field(wp_unslash($_POST['invoice'] ?? '')),
            '{{BRANCH_ID}}'   => sanitize_text_field(wp_unslash($_POST['branch_id'] ?? '')),
        ];

        // Cho sửa tay dữ liệu gửi (JSON) khi tài liệu và thực tế lệch nhau.
        $body = $def['body'];
        $custom = trim((string) wp_unslash($_POST['body'] ?? ''));
        if ($custom !== '' && $def['method'] !== 'GET') {
            $decoded = json_decode($custom, true);
            if (!is_array($decoded)) {
                wp_send_json_error(['message' => 'Dữ liệu gửi không phải JSON hợp lệ.']);
            }
            $body = $decoded;
        }
        if (is_array($body)) {
            $body = self::fill($body, $vars);
            // Thiếu tham số bắt buộc thì đừng gọi — tránh hỏi HTsoft một câu chắc chắn lỗi.
            $flat = wp_json_encode($body);
            if (strpos($flat, '"BranchID":""') !== false) {
                wp_send_json_error(['message' => 'Hàm này cần ID chi nhánh (GUID). Gọi "Danh sách kho" trước để lấy storeSrid rồi điền vào ô ID chi nhánh.']);
            }
            foreach (['callerid' => 'số điện thoại', 'invoiceCode' => 'số phiếu', 'SKU' => 'mã hàng', 'WarehouseCode' => 'mã kho'] as $key => $label) {
                if (array_key_exists($key, $body) && $body[$key] === '') {
                    wp_send_json_error(['message' => 'Hàm này cần ' . $label . ' — điền ở ô phía trên rồi bấm lại.']);
                }
            }
        }

        $query = self::fill((array) ($def['query'] ?? []), $vars);
        if (array_key_exists('mobile', $query) && $query['mobile'] === '') {
            wp_send_json_error(['message' => 'Hàm này cần số điện thoại — điền ở ô phía trên rồi bấm lại.']);
        }

        @set_time_limit(0);
        $r = TGS_HTsoft_Api_Client::call($fn, $def['method'] === 'GET' ? null : (array) $body, ['query' => $query]);

        // Tóm tắt hình dạng dữ liệu để đọc nhanh: bao nhiêu dòng, mỗi dòng có trường gì.
        $shape = '';
        $d = $r['data'];
        if (is_array($d)) {
            $list = $d;
            foreach (['detail', 'AllOrderInfoDto', 'LoadProductInfo', 'ProductInfo', 'QuantityInfo', 'data'] as $k) {
                if (isset($d[$k]) && is_array($d[$k])) {
                    $list = $d[$k];
                    $shape = 'trong "' . $k . '": ';
                    break;
                }
            }
            if ($list && array_keys($list) === range(0, count($list) - 1)) {
                $first = is_array($list[0]) ? implode(', ', array_keys($list[0])) : gettype($list[0]);
                $shape .= count($list) . ' dòng; mỗi dòng có: ' . $first;
            } else {
                $shape .= 'một đối tượng có: ' . implode(', ', array_keys($list));
            }
        }

        wp_send_json_success([
            'ok'     => $r['ok'],
            'http'   => $r['http'],
            'ms'     => $r['ms'],
            'bytes'  => (int) ($r['bytes'] ?? 0),
            'error'  => $r['error'],
            'shape'  => $shape,
            'sent'   => $def['method'] === 'GET' ? null : $body,
            'pretty' => $d !== null
                ? mb_substr((string) wp_json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 60000)
                : mb_substr((string) $r['raw'], 0, 4000),
            'log'    => TGS_HTsoft_Api_Client::recent_log(15),
        ]);
    }

    /**
     * XEM TRƯỚC dữ liệu sẽ gửi của MỘT phiếu (không gửi gì, không đổi gì trong sổ).
     * Nhận mã shop (tgs_site_code) + mã phiếu bán / phiếu hoàn.
     */
    public static function ajax_preview()
    {
        self::guard();
        global $wpdb;
        $shop = sanitize_text_field(wp_unslash($_POST['shop'] ?? ''));
        $code = sanitize_text_field(wp_unslash($_POST['code'] ?? ''));
        if ($shop === '' || $code === '') {
            wp_send_json_error(['message' => 'Điền mã shop và mã phiếu.']);
        }
        $blog_id = 0;
        if ($wpdb->get_results("SHOW COLUMNS FROM {$wpdb->blogs} LIKE 'tgs_site_code'")) {
            $blog_id = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT blog_id FROM {$wpdb->blogs} WHERE tgs_site_code = %s AND deleted = 0 LIMIT 1",
                $shop
            ));
        }
        if ($blog_id <= 0 && ctype_digit($shop)) {
            $blog_id = get_blog_details((int) $shop) ? (int) $shop : 0;
        }
        if ($blog_id <= 0) {
            wp_send_json_error(['message' => 'Không tìm thấy shop mang mã "' . $shop . '".']);
        }
        @set_time_limit(0);
        switch_to_blog($blog_id);
        try {
            $r = TGS_HTsoft_Api_Invoice::preview($code);
        } finally {
            restore_current_blog();
        }
        $r['pretty'] = mb_substr((string) wp_json_encode($r['steps'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 120000);
        $r['mapper'] = TGS_HTsoft_Api_Mapper::status();
        unset($r['steps']);
        wp_send_json_success($r);
    }
}
