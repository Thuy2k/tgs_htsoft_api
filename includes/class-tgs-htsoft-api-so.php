<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * ĐẨY PHIẾU BÁN CÒN MÃ BT LÊN HTSOFT THÀNH ĐƠN ĐẶT HÀNG (SO) QUA API — hàm AddListOrder.
 * KHÔNG dùng kết nối SQL. Nút nằm ở màn "Quản lý phiếu xuất bán (VAT)" của tgs-bc-tk.
 *
 * Luồng: bấm nút → nhập mật khẩu → LIỆT KÊ phiếu (chưa gửi gì) → bỏ tích phiếu không muốn đẩy,
 * bấm "Xem" để coi đúng dữ liệu sẽ gửi → "Bắt đầu đẩy" (theo lô nhỏ). Phiếu đã lên SO được đánh
 * dấu ngay trên phiếu (advance_meta.htsoft_so) và ghi nhật ký JSONL.
 *
 * Những điều đã kiểm bằng lượt gọi thật (06/10/2026, xem tailieuhtsoft/HIEU-API-MOI-SO.md):
 *  - orderCode tối đa 12 ký tự và là KHOÁ của đơn bên HTsoft. Mã BT bên mình trùng nhau giữa các
 *    shop (shop nào cũng BTAA0000xxxx) nên KHÔNG gửi thẳng mã BT: mã SO = 5 ký tự đầu mã shop
 *    + 6 số cuối mã BT (+ "Z" cho phiếu Z). Mã BT gốc nằm trong onlineOrderId và ghi chú.
 *  - CusCode bắt buộc; khách lẻ = "KL". Tên / số điện thoại gửi kèm bị HTsoft bỏ qua.
 *  - HTsoft không tự tính tiền: phải gửi Amount từng dòng.
 *  - Mã chung của cả lượt gọi luôn rỗng; thành bại đọc ở responseDetail từng đơn.
 *  - Giá gửi là GIÁ BÁN CUỐI (đã gồm thuế), không gửi thuế — đúng như luồng đẩy hoá đơn qua SQL.
 *
 * Dữ liệu phiếu lấy bằng chính các hàm gom dữ liệu của TGS_POS_HTsoft_Invoice_Push (dòng hàng,
 * phiếu thu, hình thức thanh toán, khách) để SO giống hệt hoá đơn mà đường SQL vẫn đẩy.
 */
class TGS_HTsoft_Api_SO
{
    const NONCE      = 'tgs_htsoft_api_so';
    const META_KEY   = 'htsoft_so';
    const KEEP_DAYS  = 30;
    const BATCH      = 10;  // số phiếu mỗi lượt gọi từ trình duyệt (mỗi phiếu tối đa 2 đơn → ≤ 20 đơn / lượt, HTsoft cho 50)
    const CODE_MAX   = 12;

    public static function init()
    {
        add_action('wp_ajax_tgs_htsoft_api_so_list', [__CLASS__, 'ajax_list']);
        add_action('wp_ajax_tgs_htsoft_api_so_preview', [__CLASS__, 'ajax_preview']);
        add_action('wp_ajax_tgs_htsoft_api_so_push', [__CLASS__, 'ajax_push']);
        add_action('wp_ajax_tgs_htsoft_api_so_log', [__CLASS__, 'ajax_log']);
        add_action('wp_ajax_tgs_htsoft_api_so_toggle', [__CLASS__, 'ajax_toggle']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue'], 30);
    }

    /** Nạp nút vào màn "Quản lý phiếu xuất bán (VAT)" của tgs-bc-tk. */
    public static function enqueue()
    {
        if (!wp_script_is('tgs-bctk-vat-report', 'enqueued') || (($_GET['view'] ?? '') !== 'bctk-vat-sales')) {
            return;
        }
        $file = TGS_HTSOFT_API_DIR . 'assets/so-push.js';
        wp_enqueue_script(
            'tgs-htsoft-api-so',
            plugins_url('assets/so-push.js', TGS_HTSOFT_API_FILE),
            ['jquery', 'tgs-bctk-vat-report'],
            TGS_HTSOFT_API_VERSION . '.' . @filemtime($file),
            true
        );
        wp_localize_script('tgs-htsoft-api-so', 'tgsHtsoftApiSo', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce(self::NONCE),
            'batch'   => self::BATCH,
            'ready'   => TGS_HTsoft_Api_Config::ready() ? 1 : 0,
        ]);
    }

    /* ───────────────────────────── quyền ───────────────────────────── */

    private static function guard(bool $need_pass = true): void
    {
        check_ajax_referer(self::NONCE, 'nonce');
        $cap = defined('TGS_BCTK_CAPABILITY') ? TGS_BCTK_CAPABILITY : 'manage_network_options';
        if (!current_user_can($cap)) {
            wp_send_json_error(['message' => 'Không có quyền đẩy đơn.'], 403);
        }
        if ($need_pass) {
            $pass     = (string) wp_unslash($_POST['password'] ?? '');
            $expected = class_exists('TGS_Book_Close') ? TGS_Book_Close::PASSWORD : 'Thuy!@#';
            if (!hash_equals($expected, $pass)) {
                wp_send_json_error(['message' => 'Sai mật khẩu.'], 403);
            }
        }
    }

    /** Gọi một hàm gom dữ liệu (private) của lớp đẩy POS — để SO dùng đúng dữ liệu như đường SQL. */
    private static function pos(string $method, ...$args)
    {
        $m = new ReflectionMethod('TGS_POS_HTsoft_Invoice_Push', $method);
        $m->setAccessible(true);
        return $m->invoke(null, ...$args);
    }

    private static function shop_code(int $blog_id): string
    {
        global $wpdb;
        $t = $wpdb->base_prefix . 'blogs';
        if (!$wpdb->get_var("SHOW COLUMNS FROM {$t} LIKE 'tgs_site_code'")) {
            return '';
        }
        return trim((string) $wpdb->get_var($wpdb->prepare("SELECT tgs_site_code FROM {$t} WHERE blog_id=%d", $blog_id)));
    }

    /** Shop được phép đẩy: nằm trong "Shop áp dụng thuế" (đang áp dụng) — giống nút đẩy phiếu BT. */
    private static function allowed_blogs(): array
    {
        return class_exists('TGS_BCTK_Vat_Shops') ? array_map('intval', (array) TGS_BCTK_Vat_Shops::active_blog_ids()) : [];
    }

    /*
     * CÔNG TẮC "CHO ĐẨY SO" THEO TỪNG SHOP — tách hẳn khỏi công tắc "đẩy HTsoft" của luồng bán hàng
     * (TGS_POS_HTsoft_Invoice_Push::push_enabled). Bật / tắt ở đây KHÔNG đổi gì ở quầy: shop vẫn bán
     * như đang bán. Lưu ở site option của network: danh sách blog_id được đẩy SO. Mặc định TẮT.
     */
    const OPT_SO_BLOGS = 'tgs_htsoft_api_so_blogs';

    public static function so_blogs(): array
    {
        return array_values(array_unique(array_filter(array_map('intval', (array) get_site_option(self::OPT_SO_BLOGS, [])))));
    }

    public static function so_enabled(int $blog_id): bool
    {
        return in_array($blog_id, self::so_blogs(), true);
    }

    /** Bật / tắt đẩy SO cho một shop (cần mật khẩu như các thao tác đẩy). */
    public static function ajax_toggle()
    {
        self::guard();
        $blog_id = (int) ($_POST['blog'] ?? 0);
        if ($blog_id <= 0 || !in_array($blog_id, self::allowed_blogs(), true) || !get_blog_details($blog_id)) {
            wp_send_json_error(['message' => 'Shop không nằm trong danh sách áp dụng thuế.'], 400);
        }
        $on = !empty($_POST['on']);
        $list = array_diff(self::so_blogs(), [$blog_id]);
        if ($on) {
            $list[] = $blog_id;
        }
        update_site_option(self::OPT_SO_BLOGS, array_values($list));
        self::log(['blog' => $blog_id, 'shop' => self::shop_code($blog_id), 'ok' => 1,
            'msg' => $on ? 'BẬT đẩy SO cho shop' : 'TẮT đẩy SO cho shop']);
        wp_send_json_success(['on' => $on ? 1 : 0]);
    }

    /** Lý do shop (blog hiện tại) không đẩy được; '' = được. */
    private static function shop_block(): string
    {
        global $wpdb;
        if (!class_exists('TGS_POS_HTsoft_Invoice_Push')) {
            return 'Shop chưa nạp lớp đẩy HTsoft của POS';
        }
        if (!self::so_enabled(get_current_blog_id())) {
            return 'Shop chưa bật đẩy SO';
        }
        $L = $wpdb->prefix . 'local_ledger';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $L)) !== $L) {
            return 'Shop chưa có sổ bán hàng';
        }
        if (self::shop_code(get_current_blog_id()) === '') {
            return 'Shop chưa gán mã kho (tgs_site_code)';
        }
        return '';
    }

    /* ───────────────────────────── mã SO ───────────────────────────── */

    /**
     * Mã SO của một phiếu BT: 5 ký tự đầu mã shop + 6 số cuối mã BT (+ 'Z'). Tối đa 12 ký tự.
     * Vd shop 18005, phiếu BTAA00000410 → 18005000410; phiếu Z → 18005000410Z.
     */
    public static function so_code(string $shop_code, string $bt_code, bool $is_z = false): string
    {
        $prefix = substr(preg_replace('/[^A-Za-z0-9]/', '', $shop_code), 0, 5);
        $digits = preg_replace('/\D/', '', $bt_code);
        $num = str_pad(substr($digits, -6), 6, '0', STR_PAD_LEFT);
        return strtoupper($prefix) . $num . ($is_z ? 'Z' : '');
    }

    private static function guid($v): string
    {
        $v = strtolower(trim((string) $v));
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $v) ? $v : '';
    }

    /* ───────────────────────────── dấu "đã lên SO" trên phiếu ───────────────────────────── */

    /**
     * Dấu của phiếu. Dấu "đã lên SO" chỉ tính khi được ghi với ĐÚNG địa chỉ API đang cấu hình: phiếu
     * đẩy thử lên bản tập huấn không được coi là đã đẩy khi chuyển sang bản chính (dấu cũ không ghi
     * địa chỉ cũng coi là chưa đẩy).
     */
    private static function read_mark(string $advance_meta): array
    {
        $m = json_decode($advance_meta, true);
        $mark = (is_array($m) && isset($m[self::META_KEY]) && is_array($m[self::META_KEY])) ? $m[self::META_KEY] : [];
        if (!empty($mark['ok']) && (string) ($mark['api'] ?? '') !== TGS_HTsoft_Api_Config::get()['base_url']) {
            $mark['ok'] = 0;
            $mark['msg'] = 'Đã đẩy lúc ' . substr((string) ($mark['at'] ?? ''), 0, 16) . ' lên một địa chỉ API khác'
                . (($mark['api'] ?? '') !== '' ? ' (' . $mark['api'] . ')' : '') . ' — chưa có ở địa chỉ hiện tại.';
        }
        return $mark;
    }

    private static function save_mark(int $sale_id, array $mark): void
    {
        global $wpdb;
        $L = $wpdb->prefix . 'local_ledger';
        $m = json_decode((string) $wpdb->get_var($wpdb->prepare(
            "SELECT local_ledger_advance_meta FROM {$L} WHERE local_ledger_id=%d",
            $sale_id
        )), true);
        $m = is_array($m) ? $m : [];
        $m[self::META_KEY] = $mark;
        $wpdb->update($L, ['local_ledger_advance_meta' => wp_json_encode($m, JSON_UNESCAPED_UNICODE)], ['local_ledger_id' => $sale_id]);
    }

    /* ───────────────────────────── dựng đơn từ phiếu ───────────────────────────── */

    /** Mã nhân viên HTsoft cho đơn: người lập phiếu (nếu thuộc chi nhánh) → không thì một nhân viên đang theo dõi của chi nhánh. */
    private static function sale_code(int $user_id, string $srid): string
    {
        global $wpdb;
        static $cache = [];
        $key = $user_id . '|' . $srid;
        if (isset($cache[$key])) {
            return $cache[$key];
        }
        $t = $wpdb->base_prefix . 'htsoft_nhanvien';
        $code = '';
        $nvid = ($user_id > 0 && method_exists('TGS_Staff_Data', 'get_htsoft_nvid'))
            ? trim((string) TGS_Staff_Data::get_htsoft_nvid($user_id)) : '';
        if ($nvid !== '') {
            // Chỉ nhận nhân viên THUỘC chi nhánh này: nhân viên chi nhánh khác có thể kéo đơn sang chi nhánh đó.
            $nv = $wpdb->get_row($wpdb->prepare("SELECT NVCODE, SRID FROM {$t} WHERE NVID=%s LIMIT 1", $nvid), ARRAY_A);
            if ($nv && ($srid === '' || strcasecmp(trim((string) $nv['SRID']), $srid) === 0)) {
                $code = trim((string) $nv['NVCODE']);
            }
        }
        if ($code === '' && $srid !== '') {
            $code = trim((string) $wpdb->get_var($wpdb->prepare(
                "SELECT NVCODE FROM {$t} WHERE SRID=%s AND NVCODE<>'' ORDER BY (THEODOI=1) DESC, NVCODE LIMIT 1",
                $srid
            )));
        }
        return $cache[$key] = $code;
    }

    /** Mã khách gửi HTsoft: mã HTsoft của khách nếu có; khách lẻ / khách chỉ có mã nội bộ (CUS-…) → KL. */
    private static function cus_code(array $cust): string
    {
        $code = trim((string) ($cust['code'] ?? ''));
        return ($code === '' || stripos($code, 'CUS-') === 0) ? 'KL' : $code;
    }

    /*
     * MÃ ĐƠN VỊ TÍNH. Màn SO của HTsoft hiện đơn vị theo MÃ (UnitID = UNIT.ID), gửi tên (UnitName)
     * thì cột Đơn vị hiện 0. Phiếu bên mình chỉ có TÊN đơn vị; hàm API GetListUnit đang hỏng phía
     * HTsoft và đường SQL không đăng nhập được, nên bảng tên → mã để SẴN ở đây (chép từ bảng UNIT của
     * HTsoft ngày 06/10/2026, 74 đơn vị). HTsoft thêm đơn vị mới thì bổ sung vào hằng UNITS, hoặc vào
     * site option tgs_htsoft_api_unit_extra dạng ['Tên đơn vị' => mã] (đè lên bảng sẵn).
     */
    const OPT_UNITS = 'tgs_htsoft_api_unit_extra';
    const UNITS = [
        'Gói' => 1, 'Lọ' => 2, 'Cây' => 3, 'Can' => 4, 'Vỉ' => 5, 'Túi' => 6, 'Bịch' => 7, 'Lốc' => 8,
        'Bộ' => 9, 'Thanh' => 10, 'Cái' => 11, 'Thùng' => 12, 'Tuýp' => 13, 'Tuyp' => 14, 'Chai' => 15,
        'Hộp' => 16, 'Cuộn' => 17, 'Lon' => 18, 'Kg' => 19, 'Con' => 20, 'VND' => 21, 'Quyển' => 22,
        'Chiếc' => 23, 'Đôi' => 24, 'Điểm' => 25, 'Cặp' => 26, 'Lốc_8' => 27, 'Vỉ_6' => 28, 'Quả' => 29,
        'Combo' => 30, 'Lốc_6' => 31, 'Dây' => 32, 'Lốc_4' => 33, 'Vỉ_4' => 34, 'Lốc_5' => 35, 'Ổ' => 36,
        'Set' => 37, 'Bình' => 38, 'Cuốn' => 39, 'Ly' => 40, 'Ca' => 41, 'Ống' => 42, 'Lốc_3' => 43,
        'Giỏ' => 44, 'Kg_140' => 45, 'Kg_100' => 46, 'Kg_32' => 47, 'Kg_85' => 48, 'Kg_90' => 49,
        'Que' => 50, 'Kg_170' => 51, 'Kg_142' => 52, 'Kg_104' => 53, 'Kg_38' => 54, 'Gam' => 55,
        'Tập' => 56, 'Thỏi' => 57, 'Vỉ_2' => 58, 'Tờ' => 59, 'Viên' => 60, 'Hũ' => 61, 'Hộp_6c' => 62,
        'Túi_5c' => 63, 'Set_5' => 64, 'Set_3c' => 65, 'Set_3' => 66, 'Hộp_8T' => 67, 'Hộp_7T' => 68,
        'Bánh' => 69, 'Cốc' => 70, 'm2' => 71, 'Lốc_9' => 72, 'Miếng' => 73, 'Túi_10c' => 74,
    ];

    private static function unit_key(string $name): string
    {
        return mb_strtolower(trim($name), 'UTF-8');
    }

    /** ['tên đơn vị (chữ thường)' => UnitID]. */
    public static function unit_ids(): array
    {
        static $map = null;
        if ($map === null) {
            $map = [];
            foreach ([self::UNITS, (array) get_site_option(self::OPT_UNITS, [])] as $src) {
                foreach ($src as $name => $id) {
                    if (is_scalar($id) && (int) $id > 0) {
                        $map[self::unit_key((string) $name)] = (int) $id;
                    }
                }
            }
        }
        return $map;
    }

    private static function lines_to_details(array $lines, string $kho): array
    {
        $units = self::unit_ids();
        $out = [];
        foreach ($lines as $l) {
            $rate = (float) ($l['qty_rate'] ?? 1);
            if ($rate <= 0) {
                $rate = 1.0;
            }
            $qty      = (float) $l['soluong'];                       // theo đơn vị gốc
            $qty_sale = (float) ($l['soluong_ex'] ?? 0);
            if ($qty_sale <= 0) {
                $qty_sale = $qty / $rate;
            }
            $price_sale = (float) $l['giaban_sale'];                 // giá bán cuối / đơn vị bán
            $discount   = (float) $l['chietkhau'];
            $d = [
                'ItemCode'       => (string) $l['mhcode'],
                'StoreCode'      => $kho,
                'Quantity'       => $qty,
                'UnitPrice'      => (float) $l['giaban'],            // giá bán cuối / đơn vị gốc
                'QtyConvertRate' => $rate,
                'QuantityEx'     => $qty_sale,
                'UnitPriceEx'    => $price_sale,
                'Discount'       => $discount,
                'Amount'         => round($price_sale * $qty_sale) - $discount,
            ];
            if (trim((string) ($l['unit_name'] ?? '')) !== '') {
                $d['UnitName'] = trim((string) $l['unit_name']);
                $uid = (int) ($units[self::unit_key($d['UnitName'])] ?? 0);
                if ($uid > 0) {
                    $d['UnitID'] = $uid;
                }
            }
            if (trim((string) ($l['ghi_chu'] ?? '')) !== '') {
                $d['Note'] = (string) $l['ghi_chu'];
            }
            $out[] = $d;
        }
        return $out;
    }

    /**
     * Dựng các đơn SO của MỘT phiếu bán chính (kèm phiếu Z nếu có). Gọi khi đã ở đúng blog của shop.
     * Trả ['error' => '', 'bt', 'orders' => [đơn chính, đơn Z?], 'codes' => ['main', 'z'], 'amt', 'warn' => []].
     */
    public static function build(int $sale_id): array
    {
        global $wpdb;
        $L = $wpdb->prefix . 'local_ledger';
        $order = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$L} WHERE local_ledger_id=%d AND local_ledger_type=10 AND (is_deleted=0 OR is_deleted IS NULL)",
            $sale_id
        ));
        if (!$order) {
            return ['error' => 'Không tìm thấy phiếu bán.'];
        }
        $bt = trim((string) $order->local_ledger_code);
        if (stripos($bt, 'BT') !== 0) {
            return ['error' => 'Phiếu đã có mã HTsoft ' . $bt . ' — không đẩy SO.'];
        }
        $blog_id = get_current_blog_id();
        if (class_exists('TGS_Book_Close') && TGS_Book_Close::is_locked((string) $order->created_at, $blog_id)) {
            return ['error' => 'Phiếu đã khoá sổ — không đẩy.'];
        }
        try {
            $branch   = (array) self::pos('branch_context');
            $kho      = trim((string) ($branch['kho_code'] ?? ''));
            $cust     = (array) self::pos('customer_of', $order->local_ledger_person_id);
            $payments = (array) self::pos('payments_of', $order);
            $httt     = (array) self::pos('representative_httt', $payments);
            if (self::pos('has_offset_netting', $order)) {
                $h = (array) self::pos('httt_by_name', (string) self::pos('exchange_httt_name', $payments));
                if (!empty($h['id'])) {
                    $httt = $h;
                }
            }
            if (self::pos('is_credit_sale', $order)) {
                $h = (array) self::pos('httt_by_name', (string) apply_filters('tgs_pos_htsoft_credit_httt_name', 'Công nợ'));
                if (!empty($h['id'])) {
                    $httt = $h;
                }
            }
            $main_export = (int) self::pos('export_ledger_of', $sale_id);
            $main_lines  = $main_export > 0 ? (array) self::pos('build_lines', $main_export) : [];
        } catch (\Throwable $e) {
            return ['error' => 'Không gom được dữ liệu phiếu: ' . $e->getMessage()];
        }
        if ($kho === '') {
            return ['error' => 'Shop chưa gán mã kho.'];
        }
        if (!$main_lines) {
            return ['error' => 'Phiếu không có dòng hàng hợp lệ.'];
        }
        $sale_code = self::sale_code((int) $order->user_id, (string) ($branch['srid'] ?? ''));
        if ($sale_code === '') {
            return ['error' => 'Không tìm được mã nhân viên HTsoft cho chi nhánh này.'];
        }

        $adv = (string) ($order->local_ledger_advance_meta ?? '');
        $reason = class_exists('TGS_POS_Export_Reason') ? trim((string) TGS_POS_Export_Reason::read_from_meta($adv)) : '';
        $created = (string) ($order->created_at ?: current_time('mysql'));
        try {
            $ts = (new DateTime($created, wp_timezone()))->getTimestamp();
        } catch (\Throwable $e) {
            $ts = time();
        }
        $note = trim(preg_replace('/\[Chưa đẩy HTsoft\]/u', '', (string) $order->local_ledger_note));
        $shop = self::shop_code($blog_id);

        $head = [
            'CusCode'       => self::cus_code($cust),
            'saleCode'      => $sale_code,
            'createdOn'     => $ts,
            'onlineOrderId' => $bt,
        ];
        if ($reason !== '') {
            $head['ReasonCode'] = $reason;
        }
        if (trim((string) ($cust['name'] ?? '')) !== '') {
            $head['orderOwnerName'] = (string) $cust['name'];
        }
        if (trim((string) ($cust['phone'] ?? '')) !== '') {
            $head['orderOwnerMobile'] = (string) $cust['phone'];
            $head['orderOwnerPhone']  = (string) $cust['phone'];
        }
        if ($g = self::guid($httt['id'] ?? '')) {
            $head['paymentTypeId'] = $g;
        }
        foreach ($payments as $p) {
            if ($g = self::guid($p['nhid'] ?? '')) {
                $head['paymentBankId'] = $g;
                break;
            }
        }

        $warn = [];
        $mk = static function (string $code, string $bt_code, array $details) use ($head, $note, $created) {
            $o = ['orderCode' => $code] + $head;
            $o['onlineOrderId'] = $bt_code;
            $o['orderReceiveNote'] = mb_substr(trim($bt_code . ' ' . substr($created, 0, 16) . ' ' . $note), 0, 100);
            $o['orderValue'] = array_sum(array_column($details, 'Amount'));
            $o['orderDetails'] = $details;
            return $o;
        };

        $codes = ['main' => self::so_code($shop, $bt), 'z' => ''];
        $orders = [$mk($codes['main'], $bt, self::lines_to_details($main_lines, $kho))];
        $all_lines = $main_lines;

        // Phiếu Z (phiếu bán con của phiếu bán): đi theo phiếu chính, thành đơn thứ hai mã + 'Z'.
        $z = $wpdb->get_row($wpdb->prepare(
            "SELECT local_ledger_id, local_ledger_code FROM {$L}
              WHERE local_ledger_parent_id=%d AND local_ledger_type=10 AND (is_deleted=0 OR is_deleted IS NULL)
              ORDER BY local_ledger_id LIMIT 1",
            $sale_id
        ));
        if ($z) {
            $z_export = (int) self::pos('export_ledger_of', (int) $z->local_ledger_id);
            $z_lines  = $z_export > 0 ? (array) self::pos('build_lines', $z_export) : [];
            if ($z_lines) {
                $codes['z'] = self::so_code($shop, $bt, true);
                $orders[] = $mk($codes['z'], trim((string) $z->local_ledger_code), self::lines_to_details($z_lines, $kho));
                $all_lines = array_merge($all_lines, $z_lines);
            }
        }

        foreach ($orders as $o) {
            if (strlen($o['orderCode']) > self::CODE_MAX) {
                return ['error' => 'Mã SO ' . $o['orderCode'] . ' dài quá ' . self::CODE_MAX . ' ký tự.'];
            }
        }
        foreach ($all_lines as $l) {
            if ((float) ($l['qty_rate'] ?? 1) != 1.0) {
                $warn[] = 'Có dòng bán theo đơn vị quy đổi — kiểm số lượng / đơn giá trên HTsoft.';
                break;
            }
        }
        $no_unit = [];
        foreach ($orders as $o) {
            foreach ($o['orderDetails'] as $d) {
                if (empty($d['UnitID'])) {
                    $no_unit[$d['UnitName'] ?? '(trống)'] = 1;
                }
            }
        }
        if ($no_unit) {
            $warn[] = 'Chưa có mã đơn vị HTsoft cho: ' . implode(', ', array_keys($no_unit)) . ' — SO sẽ trống cột Đơn vị ở các dòng này.';
        }
        if (count($payments) > 1) {
            $warn[] = 'Đơn trả bằng nhiều hình thức — SO chỉ mang một hình thức đại diện.';
        }
        if ($head['CusCode'] === 'KL' && trim((string) ($cust['code'] ?? '')) !== '') {
            $warn[] = 'Khách chưa có mã HTsoft — gửi là khách lẻ (KL).';
        }
        return [
            'error'  => '',
            'bt'     => $bt,
            'orders' => $orders,
            'codes'  => $codes,
            'amt'    => array_sum(array_column($orders, 'orderValue')),
            'warn'   => $warn,
        ];
    }

    /* ───────────────────────────── AJAX: liệt kê ───────────────────────────── */

    public static function ajax_list()
    {
        self::guard();
        global $wpdb;
        $allowed = self::allowed_blogs();
        $want = array_filter(array_map('intval', (array) json_decode((string) wp_unslash($_POST['blogs'] ?? '[]'), true)));
        $blogs = $want ? array_values(array_intersect($allowed, $want)) : $allowed;
        $days = max(1, min(120, (int) ($_POST['days'] ?? 45)));
        $since = wp_date('Y-m-d 00:00:00', current_time('timestamp', true) - $days * DAY_IN_SECONDS);

        $items = [];
        $shops = [];
        foreach ($blogs as $blog_id) {
            if (!get_blog_details($blog_id)) {
                continue;
            }
            switch_to_blog($blog_id);
            try {
                $shop = self::shop_code($blog_id);
                $info = ['blog' => $blog_id, 'shop' => $shop ?: ('#' . $blog_id), 'n' => 0, 'done' => 0, 'skip' => self::shop_block(),
                    'so_on' => self::so_enabled($blog_id) ? 1 : 0];
                if ($info['skip'] !== '') {
                    $shops[] = $info;
                    continue;
                }
                $L = $wpdb->prefix . 'local_ledger';
                $lock = class_exists('TGS_Book_Close') ? (string) TGS_Book_Close::at($blog_id) : '';
                $from = ($lock !== '' && $lock > $since) ? $lock : $since;
                $info['lock'] = $lock;
                // Phiếu bán CHÍNH còn mã BT (phiếu Z đi theo phiếu chính).
                $rows = $wpdb->get_results($wpdb->prepare(
                    "SELECT s.local_ledger_id id, s.local_ledger_code code, s.created_at, s.local_ledger_total_amount amt,
                            s.local_ledger_advance_meta adv,
                            (SELECT z.local_ledger_code FROM {$L} z
                              WHERE z.local_ledger_parent_id = s.local_ledger_id AND z.local_ledger_type = 10
                                AND (z.is_deleted = 0 OR z.is_deleted IS NULL) LIMIT 1) z_code
                       FROM {$L} s
                       LEFT JOIN {$L} pp ON pp.local_ledger_id = s.local_ledger_parent_id
                      WHERE s.local_ledger_type = 10 AND s.local_ledger_code LIKE %s
                        AND (s.is_deleted = 0 OR s.is_deleted IS NULL)
                        AND (pp.local_ledger_id IS NULL OR pp.local_ledger_type <> 10)
                        AND s.created_at > %s
                      ORDER BY s.created_at, s.local_ledger_id",
                    'BT%',
                    $from
                ), ARRAY_A);
                foreach ((array) $rows as $r) {
                    $mark = self::read_mark((string) $r['adv']);
                    $done = !empty($mark['ok']);
                    $items[] = [
                        'blog' => $blog_id, 'shop' => $info['shop'], 'id' => (int) $r['id'],
                        'code' => (string) $r['code'], 'z' => (string) ($r['z_code'] ?? ''),
                        'so'   => self::so_code($shop, (string) $r['code']),
                        'at'   => (string) $r['created_at'], 'amt' => (float) $r['amt'],
                        'done' => $done ? 1 : 0,
                        'done_at' => (string) ($mark['at'] ?? ''),
                        'last_err' => $done ? '' : (string) ($mark['msg'] ?? ''),
                    ];
                    $info['n']++;
                    $info['done'] += $done ? 1 : 0;
                }
                $shops[] = $info;
            } finally {
                restore_current_blog();
            }
        }
        usort($items, static function ($a, $b) {
            return strcmp($a['shop'], $b['shop']) ?: (strcmp($a['at'], $b['at']) ?: ($a['id'] <=> $b['id']));
        });
        $cfg = TGS_HTsoft_Api_Config::get();
        wp_send_json_success(['items' => $items, 'shops' => $shops, 'days' => $days, 'api' => $cfg['base_url']]);
    }

    /* ───────────────────────────── AJAX: xem dữ liệu sẽ gửi của một phiếu ───────────────────────────── */

    public static function ajax_preview()
    {
        self::guard();
        $blog_id = (int) ($_POST['blog'] ?? 0);
        $id = (int) ($_POST['id'] ?? 0);
        if ($blog_id <= 0 || $id <= 0 || !in_array($blog_id, self::allowed_blogs(), true) || !get_blog_details($blog_id)) {
            wp_send_json_error(['message' => 'Shop không hợp lệ.'], 400);
        }
        switch_to_blog($blog_id);
        try {
            $blk = self::shop_block();
            $b = $blk !== '' ? ['error' => $blk] : self::build($id);
        } finally {
            restore_current_blog();
        }
        if (!empty($b['error'])) {
            wp_send_json_error(['message' => $b['error']]);
        }
        wp_send_json_success([
            'warn'   => $b['warn'],
            'pretty' => wp_json_encode(['data' => $b['orders']], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    /* ───────────────────────────── AJAX: đẩy một lô ───────────────────────────── */

    public static function ajax_push()
    {
        self::guard();
        @set_time_limit(0);
        $items = json_decode((string) wp_unslash($_POST['items'] ?? '[]'), true);
        if (!is_array($items) || !$items) {
            wp_send_json_error(['message' => 'Không có phiếu nào để đẩy.'], 400);
        }
        if (!TGS_HTsoft_Api_Config::ready()) {
            wp_send_json_error(['message' => 'Chưa cấu hình địa chỉ API HTsoft (Quản trị → Công cụ đối chiếu → Kiểm tra API HTsoft).'], 400);
        }
        $items = array_slice($items, 0, self::BATCH);
        $allowed = self::allowed_blogs();

        // 1) Dựng đơn cho từng phiếu.
        $results = [];
        $orders = [];
        foreach ($items as $k => $it) {
            $blog_id = (int) ($it['blog'] ?? 0);
            $id = (int) ($it['id'] ?? 0);
            $res = ['blog' => $blog_id, 'id' => $id, 'ok' => 0, 'message' => '', 'so' => '', 'so_z' => ''];
            if ($blog_id <= 0 || $id <= 0 || !in_array($blog_id, $allowed, true) || !get_blog_details($blog_id)) {
                $res['message'] = 'Shop không nằm trong danh sách áp dụng thuế.';
                $results[$k] = $res;
                continue;
            }
            switch_to_blog($blog_id);
            try {
                $blk = self::shop_block();
                $b = $blk !== '' ? ['error' => $blk] : self::build($id);
            } catch (\Throwable $e) {
                $b = ['error' => 'Lỗi khi dựng đơn: ' . $e->getMessage()];
            } finally {
                restore_current_blog();
            }
            if (!empty($b['error'])) {
                $res['message'] = $b['error'];
                $results[$k] = $res;
                continue;
            }
            $res['so'] = $b['codes']['main'];
            $res['so_z'] = $b['codes']['z'];
            $res['_b'] = $b;
            $res['_at'] = [];
            foreach ($b['orders'] as $o) {
                $res['_at'][] = count($orders);
                $orders[] = $o;
            }
            $results[$k] = $res;
        }

        // 2) Một lượt gọi AddListOrder cho cả lô.
        $detail = [];
        $call_err = '';
        if ($orders) {
            $r = TGS_HTsoft_Api_Client::call('AddListOrder', ['data' => $orders], ['allow_write' => true]);
            $detail = is_array($r['data']['responseDetail'] ?? null) ? array_values($r['data']['responseDetail']) : [];
            if (empty($r['ok']) || count($detail) !== count($orders)) {
                $call_err = $r['error'] !== '' ? (string) $r['error']
                    : 'HTsoft trả ' . count($detail) . ' kết quả cho ' . count($orders) . ' đơn — không khớp, coi như chưa rõ.';
            }
        }

        // 3) Đọc kết quả từng đơn, đánh dấu phiếu, ghi nhật ký.
        foreach ($results as $k => $res) {
            if (!isset($res['_b'])) {
                continue;
            }
            $b = $res['_b'];
            $at = $res['_at'];
            unset($res['_b'], $res['_at']);
            $ok = $call_err === '';
            $msgs = [];
            $order_ids = [];
            if ($call_err !== '') {
                $msgs[] = $call_err;
            } else {
                foreach ($at as $j => $pos) {
                    $d = (array) $detail[$pos];
                    $one_ok = ((string) ($d['responseCode'] ?? '')) === '00';
                    $ok = $ok && $one_ok;
                    $order_ids[] = (string) ($d['OrderID'] ?? '');
                    if (!$one_ok) {
                        $msgs[] = ($j === 0 ? 'Đơn chính: ' : 'Đơn Z: ') . trim((string) ($d['message'] ?? 'lỗi không rõ'));
                    }
                }
            }
            $res['ok'] = $ok ? 1 : 0;
            $res['message'] = $ok
                ? 'Đã lên SO ' . $res['so'] . ($res['so_z'] !== '' ? ' + ' . $res['so_z'] : '')
                : implode(' | ', $msgs);
            $res['warn'] = $b['warn'];

            $mark = [
                'ok' => $ok ? 1 : 0, 'api' => TGS_HTsoft_Api_Config::get()['base_url'],
                'code' => $res['so'], 'code_z' => $res['so_z'],
                'order_id' => $order_ids[0] ?? '', 'at' => current_time('mysql'),
                'msg' => $ok ? '' : mb_substr($res['message'], 0, 300),
            ];
            switch_to_blog($res['blog']);
            try {
                // Lần đẩy lỗi không được xoá dấu "đã lên SO" của một lần đẩy thành công trước đó.
                $old = self::read_mark((string) $GLOBALS['wpdb']->get_var($GLOBALS['wpdb']->prepare(
                    "SELECT local_ledger_advance_meta FROM {$GLOBALS['wpdb']->prefix}local_ledger WHERE local_ledger_id=%d",
                    $res['id']
                )));
                if ($ok || empty($old['ok'])) {
                    self::save_mark($res['id'], $mark);
                }
                $shop = self::shop_code($res['blog']);
            } finally {
                restore_current_blog();
            }
            self::log([
                'blog' => $res['blog'], 'shop' => $shop, 'id' => $res['id'], 'bt' => $b['bt'],
                'so' => $res['so'], 'so_z' => $res['so_z'], 'order_id' => $order_ids[0] ?? '',
                'ok' => $ok ? 1 : 0, 'msg' => $res['message'], 'amt' => $b['amt'],
                'lines' => array_sum(array_map(static function ($o) { return count($o['orderDetails']); }, $b['orders'])),
                'warn' => $b['warn'],
            ]);
            $results[$k] = $res;
        }
        wp_send_json_success(['results' => array_values($results)]);
    }

    /* ───────────────────────────── nhật ký ───────────────────────────── */

    private static function log_dir(): string
    {
        return trailingslashit(WP_CONTENT_DIR) . 'uploads/tgs-htsoft-logs/';
    }

    private static function log(array $row): void
    {
        try {
            $d = self::log_dir();
            if (!is_dir($d) && !wp_mkdir_p($d)) {
                return;
            }
            if (!file_exists($d . '.htaccess')) {
                @file_put_contents($d . '.htaccess', "Require all denied\nDeny from all\n");
            }
            $user = wp_get_current_user();
            $row = array_merge([
                't'  => current_time('Y-m-d H:i:s'),
                'by' => ($user && $user->ID) ? (string) ($user->display_name ?: $user->user_login) : '',
            ], $row);
            $file = $d . 'so-push-' . current_time('Y-m-d') . '.jsonl';
            $is_new = !file_exists($file);
            @file_put_contents($file, wp_json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
            if ($is_new) {
                $min = wp_date('Y-m-d', current_time('timestamp', true) - (self::KEEP_DAYS - 1) * DAY_IN_SECONDS);
                foreach ((array) glob($d . 'so-push-*.jsonl') as $f) {
                    if (preg_match('/so-push-(\d{4}-\d{2}-\d{2})\.jsonl$/', (string) $f, $m) && $m[1] < $min) {
                        @unlink($f);
                    }
                }
            }
        } catch (\Throwable $e) {
            error_log('[TGS HTsoft API] Ghi nhat ky day SO loi: ' . $e->getMessage());
        }
    }

    /** Các dòng nhật ký mới nhất (mới trước), tối đa $limit. */
    public static function ajax_log()
    {
        self::guard(false);
        $limit = max(1, min(1000, (int) ($_POST['limit'] ?? 300)));
        $files = (array) glob(self::log_dir() . 'so-push-*.jsonl');
        rsort($files);
        $rows = [];
        foreach ($files as $file) {
            $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (!is_array($lines)) {
                continue;
            }
            for ($i = count($lines) - 1; $i >= 0 && count($rows) < $limit; $i--) {
                $r = json_decode($lines[$i], true);
                if (is_array($r)) {
                    $rows[] = $r;
                }
            }
            if (count($rows) >= $limit) {
                break;
            }
        }
        wp_send_json_success(['rows' => $rows, 'keep_days' => self::KEEP_DAYS]);
    }
}
