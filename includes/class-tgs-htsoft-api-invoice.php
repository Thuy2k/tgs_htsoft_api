<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * DỊCH VỤ GHI CHỨNG TỪ SANG HTSOFT QUA API — cùng giao diện với TGS_HTsoft_Invoice (cổng SQL)
 * ở ba hàm tgs_pos dùng khi đẩy phiếu: createRetail, createReturn, linkReturnOffset.
 *
 * tgs_pos lấy dịch vụ ghi qua TGS_POS_HTsoft_Invoice_Push::invoice_service() (filter
 * 'tgs_pos_htsoft_invoice_service'). Plugin này cắm vào filter đó trong hai trường hợp:
 *
 *   1. XEM TRƯỚC (capture): trang kiểm tra chạy đúng luồng đẩy của một phiếu nhưng KHÔNG gửi gì
 *      đi — chỉ ghi lại payload từng bước (hoá đơn chính, phiếu Z, phiếu hoàn, đối trừ) để xem
 *      "nếu đẩy thì sẽ gửi đúng những gì". Mọi thay đổi trong DB của mình được hoàn tác.
 *
 *   2. CHẠY THẬT (live): khi cấu hình "Đường ghi phiếu" = API. Payload đi qua
 *      TGS_HTsoft_Api_Mapper để thành lời gọi API. Mapper chưa khai báo thì KHÔNG gửi, báo lỗi rõ.
 *
 * Hàm nào tgs_pos gọi mà chưa có ở đây → trả ['ok' => false] kèm tên hàm (xem __call), không
 * làm sập trang.
 */
class TGS_HTsoft_Api_Invoice
{
    /** @var bool true = chỉ ghi lại, không gửi */
    private $capture;
    /** @var array mã local để trả giả khi xem trước: ['main' => .., 'z' => .., 'return' => ..] */
    private $fake;
    /** @var array các bước đã ghi lại khi xem trước */
    public $captured = [];

    public function __construct(bool $capture = false, array $fake = [])
    {
        $this->capture = $capture;
        $this->fake = $fake;
    }

    public function createRetail(array $payload, bool $dryRun = false, bool $isSub = false): array
    {
        if ($this->capture) {
            $this->captured[] = ['step' => $isSub ? 'Phiếu Z' : 'Hoá đơn bán', 'call' => 'createRetail', 'payload' => $payload];
            // Trả đúng mã local hiện có → bước "đối chiếu mã" phía sau không đổi gì.
            return ['ok' => true, 'so_phieu' => (string) ($this->fake[$isSub ? 'z' : 'main'] ?? ''), 'bhdid' => '', 'receipts' => []];
        }
        $req = TGS_HTsoft_Api_Mapper::retail_request($payload, $isSub);
        if ($req === null) {
            return ['ok' => false, 'error' => 'API hoá đơn bán lẻ chưa được khai báo (chờ tài liệu HTsoft) — chưa gửi gì.'];
        }
        return $this->send($req, [TGS_HTsoft_Api_Mapper::class, 'retail_result'], $dryRun);
    }

    public function createReturn(array $payload, bool $dryRun = false): array
    {
        if ($this->capture) {
            $this->captured[] = ['step' => 'Phiếu hoàn', 'call' => 'createReturn', 'payload' => $payload];
            return ['ok' => true, 'so_phieu' => (string) ($this->fake['return'] ?? ''), 'bhtid' => '', 'bhdid_goc' => '', 'phieu_chi' => []];
        }
        $req = TGS_HTsoft_Api_Mapper::return_request($payload);
        if ($req === null) {
            return ['ok' => false, 'error' => 'API phiếu hoàn chưa được khai báo (chờ tài liệu HTsoft) — chưa gửi gì.'];
        }
        return $this->send($req, [TGS_HTsoft_Api_Mapper::class, 'return_result'], $dryRun);
    }

    public function linkReturnOffset(array $payload, bool $dryRun = false): array
    {
        if ($this->capture) {
            $this->captured[] = ['step' => 'Đối trừ phiếu hoàn ↔ hoá đơn mới', 'call' => 'linkReturnOffset', 'payload' => $payload];
            return ['ok' => true];
        }
        $req = TGS_HTsoft_Api_Mapper::offset_request($payload);
        if ($req === null) {
            return ['ok' => false, 'error' => 'API đối trừ chưa được khai báo (chờ tài liệu HTsoft) — chưa gửi gì.'];
        }
        return $this->send($req, [TGS_HTsoft_Api_Mapper::class, 'offset_result'], $dryRun);
    }

    /** Gửi một lời gọi ghi đã được mapper dựng. */
    private function send(array $req, callable $parse, bool $dryRun): array
    {
        $fn = (string) ($req['fn'] ?? '');
        $body = is_array($req['body'] ?? null) ? $req['body'] : [];
        if ($fn === '') {
            return ['ok' => false, 'error' => 'Mapper không trả tên hàm API.'];
        }
        if ($dryRun) {
            return ['ok' => true, 'dry' => true, 'fn' => $fn, 'body' => $body];
        }
        $r = TGS_HTsoft_Api_Client::call($fn, $body, ['allow_write' => true]);
        if (empty($r['ok'])) {
            return ['ok' => false, 'error' => (string) $r['error']];
        }
        $out = (array) call_user_func($parse, $r['data']);
        if (!isset($out['ok'])) {
            $out['ok'] = false;
            $out['error'] = 'Không đọc được kết quả từ API.';
        }
        return $out;
    }

    /** Hàm tgs_pos gọi mà đường API chưa có → báo rõ, không làm sập. */
    public function __call($name, $args)
    {
        if ($this->capture) {
            $this->captured[] = ['step' => 'Bước khác', 'call' => (string) $name, 'payload' => $args[0] ?? null];
            return ['ok' => true];
        }
        return ['ok' => false, 'error' => 'Đường API chưa hỗ trợ thao tác "' . $name . '".'];
    }

    /* ═══════════════════════════════ cắm vào tgs_pos ═══════════════════════════════ */

    public static function init(): void
    {
        add_filter('tgs_pos_htsoft_invoice_service', [__CLASS__, 'provide'], 10, 2);
    }

    /** Dịch vụ đang được ép dùng cho lượt xem trước (null = không). */
    private static $forced = null;

    public static function provide($svc, $blog_id = 0)
    {
        if (self::$forced) {
            return self::$forced;
        }
        if (TGS_HTsoft_Api_Config::get()['write_mode'] === 'api') {
            return new self(false);
        }
        return $svc; // null → tgs_pos dùng cổng SQL như cũ
    }

    /**
     * XEM TRƯỚC: chạy đúng luồng đẩy của MỘT phiếu (bán hoặc hoàn) ở blog hiện tại mà không gửi gì.
     * Trả ['ok', 'message', 'kind', 'code', 'steps' => [...payload từng bước...], 'result' => kết quả luồng].
     *
     * An toàn:
     *  - dịch vụ ghi bị thay bằng bản "chỉ ghi lại" trong suốt lượt chạy;
     *  - chạy trong một giao dịch DB rồi ROLLBACK → sổ của mình không đổi; chỉ chạy khi bảng là InnoDB;
     *  - gỡ tạm hook 'tgs_pos_htsoft_return_pushed' (hook này có thể ghi phiếu chi sang HTsoft);
     *  - sau khi chạy, so lại meta của phiếu — nếu lệch thì khôi phục và báo.
     */
    public static function preview(string $code): array
    {
        global $wpdb, $wp_filter;
        if (!class_exists('TGS_POS_HTsoft_Invoice_Push')) {
            return ['ok' => false, 'message' => 'Shop này chưa nạp lớp đẩy HTsoft của POS.'];
        }
        $L = $wpdb->prefix . 'local_ledger';
        $engine = (string) $wpdb->get_var($wpdb->prepare(
            'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
            $L
        ));
        if (strcasecmp($engine, 'InnoDB') !== 0) {
            return ['ok' => false, 'message' => 'Bảng sổ của shop không phải InnoDB — không xem trước an toàn được.'];
        }
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT local_ledger_id, local_ledger_code, local_ledger_type, local_ledger_parent_id
               FROM {$L} WHERE local_ledger_code = %s AND local_ledger_type IN (10, 11)
                AND (is_deleted = 0 OR is_deleted IS NULL) ORDER BY local_ledger_id DESC LIMIT 1",
            $code
        ), ARRAY_A);
        if (!$row) {
            return ['ok' => false, 'message' => 'Không tìm thấy phiếu bán / phiếu hoàn mang mã "' . $code . '" ở shop này.'];
        }
        $id = (int) $row['local_ledger_id'];
        $kind = (int) $row['local_ledger_type'] === 11 ? 'return' : 'sale';
        $fake = ['main' => (string) $row['local_ledger_code'], 'return' => (string) $row['local_ledger_code'], 'z' => ''];

        if ($kind === 'sale') {
            // Phiếu Z → quy về phiếu chính (phiếu Z luôn đi theo phiếu chính).
            $pid = (int) $row['local_ledger_parent_id'];
            if ($pid > 0) {
                $parent = $wpdb->get_row($wpdb->prepare(
                    "SELECT local_ledger_id, local_ledger_code FROM {$L} WHERE local_ledger_id = %d AND local_ledger_type = 10",
                    $pid
                ), ARRAY_A);
                if ($parent) {
                    $id = (int) $parent['local_ledger_id'];
                    $fake['main'] = (string) $parent['local_ledger_code'];
                }
            }
            $fake['z'] = (string) $wpdb->get_var($wpdb->prepare(
                "SELECT local_ledger_code FROM {$L} WHERE local_ledger_parent_id = %d AND local_ledger_type = 10
                    AND (is_deleted = 0 OR is_deleted IS NULL) ORDER BY local_ledger_id LIMIT 1",
                $id
            ));
            // Đơn đổi trả: phiếu hoàn được kéo theo — dùng mã giữ chỗ (mọi thay đổi đều được ROLLBACK).
            $fake['return'] = 'XEMTRUOC-HOAN';
        }

        $meta_sql = $wpdb->prepare("SELECT local_ledger_advance_meta FROM {$L} WHERE local_ledger_id = %d", $id);
        $meta_before = (string) $wpdb->get_var($meta_sql);

        $svc = new self(true, $fake);
        self::$forced = $svc;
        $force_push = static function () { return true; };
        add_filter('tgs_htsoft_push_enabled', $force_push, 9999);
        $saved_hook = $wp_filter['tgs_pos_htsoft_return_pushed'] ?? null;
        unset($wp_filter['tgs_pos_htsoft_return_pushed']);

        $result = null;
        $error = '';
        $wpdb->query('START TRANSACTION');
        try {
            $result = $kind === 'return'
                ? TGS_POS_HTsoft_Invoice_Push::push_return($id, false)
                : TGS_POS_HTsoft_Invoice_Push::push_order($id);
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        } finally {
            $wpdb->query('ROLLBACK');
            self::$forced = null;
            remove_filter('tgs_htsoft_push_enabled', $force_push, 9999);
            if ($saved_hook !== null) {
                $wp_filter['tgs_pos_htsoft_return_pushed'] = $saved_hook;
            }
        }

        // Chốt an toàn: meta của phiếu phải y nguyên.
        $restored = false;
        if ((string) $wpdb->get_var($meta_sql) !== $meta_before) {
            $wpdb->update($L, ['local_ledger_advance_meta' => $meta_before], ['local_ledger_id' => $id]);
            $restored = true;
        }

        $msg = '';
        if ($error !== '') {
            $msg = 'Luồng đẩy báo lỗi khi xem trước: ' . $error;
        } elseif (!$svc->captured) {
            $msg = 'Luồng đẩy dừng trước khi tới bước gửi: ' . (string) ($result['error'] ?? ($result['skipped'] ?? 'không rõ lý do'))
                . (!empty($result['already']) ? ' (phiếu đã có mã HTsoft)' : '');
        }
        if ($restored) {
            $msg .= ' [Đã khôi phục meta của phiếu về như cũ.]';
        }
        return [
            'ok'      => $error === '' && (bool) $svc->captured,
            'message' => $msg,
            'kind'    => $kind,
            'code'    => $fake['main'],
            'steps'   => $svc->captured,
            'result'  => $result,
        ];
    }
}
