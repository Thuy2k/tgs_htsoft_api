<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * ĐỐI SOÁT PHIẾU BÁN BTSOFT ↔ HOÁ ĐƠN BÁN LẺ HTSOFT bằng file Excel xuất từ phần mềm HTsoft.
 * Nút "Đối soát HTsoft (Excel)" ở màn "Quản lý phiếu xuất bán (VAT)" của tgs-bc-tk.
 *
 * Vì sao cần: phiếu BT lên HTsoft thành đơn đặt hàng (SO), rồi người dùng bấm tay chuyển SO thành hoá
 * đơn bán lẻ. Bấm nhầm hai lần thì một phiếu BTsoft ra HAI hoá đơn; chọn nhầm ngày thì hoá đơn lệch
 * ngày bán. BTsoft là gốc nên đối soát lấy BTsoft làm chuẩn.
 *
 * File Excel HTsoft cần các cột: Số phiếu · Tổng nợ · Diễn giải · Ngày nhập. Đọc file ngay trên trình
 * duyệt (SheetJS); máy chủ chỉ trả phiếu bán của các shop đang chọn và lưu kết quả ghép.
 *
 * Ghép: (1) mã BT nằm trong Diễn giải (hoá đơn sinh từ SO mang ghi chú "BTAA… <ngày giờ>");
 *       (2) Số phiếu HTsoft trùng mã phiếu bên mình (phiếu đã lên bằng đường cũ, mã thật).
 *
 * Kết quả ghép lưu ngay trên phiếu: advance_meta.htsoft_ref = {code, n, total, date, at} — hiện ở cột
 * "Số phiếu HTsoft" cuối bảng bc-tk và đi theo khi xuất Excel. KHÔNG sửa ghi chú, KHÔNG đổi mã phiếu.
 */
class TGS_HTsoft_Api_Recon
{
    const NONCE     = 'tgs_htsoft_api_recon';
    const META_KEY  = 'htsoft_ref';
    const MAX_CODES = 5000;

    public static function init()
    {
        add_action('wp_ajax_tgs_htsoft_api_recon_local', [__CLASS__, 'ajax_local']);
        add_action('wp_ajax_tgs_htsoft_api_recon_save', [__CLASS__, 'ajax_save']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue'], 31);
    }

    public static function enqueue()
    {
        if (!wp_script_is('tgs-bctk-vat-report', 'enqueued') || (($_GET['view'] ?? '') !== 'bctk-vat-sales')) {
            return;
        }
        // Cùng bản SheetJS mà màn "Trợ giúp đối soát" của POS đang dùng.
        wp_enqueue_script('sheetjs-style', 'https://cdn.jsdelivr.net/npm/xlsx-js-style@1.2.0/dist/xlsx.bundle.js', [], '1.2.0', true);
        $file = TGS_HTSOFT_API_DIR . 'assets/so-recon.js';
        wp_enqueue_script(
            'tgs-htsoft-api-recon',
            plugins_url('assets/so-recon.js', TGS_HTSOFT_API_FILE),
            ['jquery', 'tgs-bctk-vat-report', 'sheetjs-style'],
            TGS_HTSOFT_API_VERSION . '.' . @filemtime($file),
            true
        );
        wp_localize_script('tgs-htsoft-api-recon', 'tgsHtsoftApiRecon', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce(self::NONCE),
        ]);
    }

    private static function guard(): void
    {
        check_ajax_referer(self::NONCE, 'nonce');
        $cap = defined('TGS_BCTK_CAPABILITY') ? TGS_BCTK_CAPABILITY : 'manage_network_options';
        if (!current_user_can($cap)) {
            wp_send_json_error(['message' => 'Không có quyền đối soát.'], 403);
        }
    }

    /** Shop được xem: trong "Shop áp dụng thuế" — cùng phạm vi với bảng bc-tk. */
    private static function allowed_blogs(): array
    {
        return class_exists('TGS_BCTK_Vat_Shops') ? array_map('intval', (array) TGS_BCTK_Vat_Shops::active_blog_ids()) : [];
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

    private static function day(string $v): string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : '';
    }

    /**
     * Phiếu bán (cả phiếu Z) của các shop đang chọn: tạo trong khoảng ngày, CỘNG các phiếu có mã nằm
     * trong danh sách mã đọc được từ file Excel (hoá đơn HTsoft nhập nhầm ngày vẫn tìm ra phiếu gốc).
     */
    public static function ajax_local()
    {
        self::guard();
        global $wpdb;
        $want  = array_filter(array_map('intval', (array) json_decode((string) wp_unslash($_POST['blogs'] ?? '[]'), true)));
        $blogs = array_values(array_intersect(self::allowed_blogs(), $want));
        if (!$blogs) {
            wp_send_json_error(['message' => 'Chưa chọn shop nào (tích chi nhánh / mã kho ở bộ lọc bên trái).'], 400);
        }
        $from = self::day((string) ($_POST['from'] ?? ''));
        $to   = self::day((string) ($_POST['to'] ?? ''));
        if ($from === '' || $to === '' || $from > $to) {
            wp_send_json_error(['message' => 'Khoảng ngày không hợp lệ.'], 400);
        }
        $codes = [];
        foreach ((array) json_decode((string) wp_unslash($_POST['codes'] ?? '[]'), true) as $c) {
            $c = strtoupper(trim((string) $c));
            if ($c !== '' && preg_match('/^[A-Z0-9._\-]{4,40}$/', $c)) {
                $codes[$c] = 1;
            }
            if (count($codes) >= self::MAX_CODES) {
                break;
            }
        }
        $codes = array_keys($codes);

        $rows = [];
        $shops = [];
        foreach ($blogs as $blog_id) {
            if (!get_blog_details($blog_id)) {
                continue;
            }
            switch_to_blog($blog_id);
            try {
                $L = $wpdb->prefix . 'local_ledger';
                if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $L)) !== $L) {
                    continue;
                }
                $shop = self::shop_code($blog_id) ?: ('#' . $blog_id);
                $where = 's.created_at BETWEEN %s AND %s';
                $args = [$from . ' 00:00:00', $to . ' 23:59:59'];
                if ($codes) {
                    $where = '(' . $where . ' OR s.local_ledger_code IN (' . implode(',', array_fill(0, count($codes), '%s')) . '))';
                    $args = array_merge($args, $codes);
                }
                $found = $wpdb->get_results($wpdb->prepare(
                    "SELECT s.local_ledger_id id, s.local_ledger_code code, s.created_at, s.local_ledger_total_amount amt,
                            (pp.local_ledger_id IS NOT NULL) is_z, pp.local_ledger_code parent_code,
                            JSON_UNQUOTE(JSON_EXTRACT(s.local_ledger_advance_meta, '$.htsoft_so.code'))   so,
                            JSON_UNQUOTE(JSON_EXTRACT(pp.local_ledger_advance_meta, '$.htsoft_so.code_z')) so_z,
                            JSON_UNQUOTE(JSON_EXTRACT(s.local_ledger_advance_meta, '$.htsoft_ref.code'))  ref
                       FROM {$L} s
                       LEFT JOIN {$L} pp ON pp.local_ledger_id = s.local_ledger_parent_id AND pp.local_ledger_type = 10
                      WHERE s.local_ledger_type = 10 AND (s.is_deleted = 0 OR s.is_deleted IS NULL) AND {$where}
                      ORDER BY s.created_at, s.local_ledger_id",
                    ...$args
                ), ARRAY_A);
                foreach ((array) $found as $r) {
                    $at = (string) $r['created_at'];
                    $rows[] = [
                        'blog' => $blog_id, 'shop' => $shop, 'id' => (int) $r['id'],
                        'code' => trim((string) $r['code']), 'at' => $at, 'amt' => (float) $r['amt'],
                        'is_z' => (int) $r['is_z'], 'parent' => (string) ($r['parent_code'] ?? ''),
                        'so'   => (string) ((int) $r['is_z'] ? ($r['so_z'] ?? '') : ($r['so'] ?? '')),
                        'ref'  => (string) ($r['ref'] ?? ''),
                        'in_range' => (substr($at, 0, 10) >= $from && substr($at, 0, 10) <= $to) ? 1 : 0,
                    ];
                }
                $shops[] = ['blog' => $blog_id, 'shop' => $shop, 'n' => count((array) $found)];
            } finally {
                restore_current_blog();
            }
        }
        wp_send_json_success(['rows' => $rows, 'shops' => $shops, 'from' => $from, 'to' => $to]);
    }

    /** Lưu kết quả ghép lên phiếu: items = [{blog, id, code, n, total, date}]. code rỗng = xoá dấu ghép. */
    public static function ajax_save()
    {
        self::guard();
        global $wpdb;
        $items = json_decode((string) wp_unslash($_POST['items'] ?? '[]'), true);
        if (!is_array($items) || !$items) {
            wp_send_json_error(['message' => 'Không có phiếu nào để lưu.'], 400);
        }
        $allowed = self::allowed_blogs();
        $by_blog = [];
        foreach (array_slice($items, 0, 500) as $it) {
            $blog_id = (int) ($it['blog'] ?? 0);
            $id = (int) ($it['id'] ?? 0);
            if ($blog_id > 0 && $id > 0 && in_array($blog_id, $allowed, true)) {
                $by_blog[$blog_id][$id] = $it;
            }
        }
        $saved = 0;
        foreach ($by_blog as $blog_id => $list) {
            if (!get_blog_details($blog_id)) {
                continue;
            }
            switch_to_blog($blog_id);
            try {
                $L = $wpdb->prefix . 'local_ledger';
                foreach ($list as $id => $it) {
                    $adv = $wpdb->get_var($wpdb->prepare(
                        "SELECT local_ledger_advance_meta FROM {$L} WHERE local_ledger_id=%d AND local_ledger_type=10",
                        $id
                    ));
                    if ($adv === null && !$wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$L} WHERE local_ledger_id=%d AND local_ledger_type=10", $id))) {
                        continue;
                    }
                    $m = json_decode((string) $adv, true);
                    $m = is_array($m) ? $m : [];
                    $code = mb_substr(preg_replace('/[^A-Za-z0-9._\-, ]/', '', (string) ($it['code'] ?? '')), 0, 300);
                    if ($code === '') {
                        unset($m[self::META_KEY]);
                    } else {
                        $m[self::META_KEY] = [
                            'code'  => $code,
                            'n'     => max(1, (int) ($it['n'] ?? 1)),
                            'total' => (float) ($it['total'] ?? 0),
                            'date'  => self::day((string) ($it['date'] ?? '')),
                            'at'    => current_time('mysql'),
                        ];
                    }
                    $wpdb->update($L, ['local_ledger_advance_meta' => wp_json_encode($m, JSON_UNESCAPED_UNICODE)], ['local_ledger_id' => $id]);
                    $saved++;
                }
            } finally {
                restore_current_blog();
            }
        }
        wp_send_json_success(['saved' => $saved]);
    }
}
