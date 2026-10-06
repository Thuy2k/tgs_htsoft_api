<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * ═══════════════════════════════════════════════════════════════════════════════════════
 * CHỖ DUY NHẤT CẦN ĐIỀN KHI HTSOFT GỬI TÀI LIỆU API "hoá đơn bán lẻ / phiếu trả"
 * ═══════════════════════════════════════════════════════════════════════════════════════
 *
 * Phần còn lại của hệ thống đã sẵn: tgs_pos gom đủ dữ liệu của một chứng từ thành một mảng
 * chuẩn ("payload" — mô tả bên dưới) rồi đưa cho dịch vụ ghi. File này đổi payload đó thành
 * đúng lời gọi API của HTsoft, và đổi câu trả lời của HTsoft về dạng tgs_pos đang chờ.
 *
 * Mỗi hàm *_request() trả về:
 *     ['fn' => 'TenHamApi', 'body' => [ ...dữ liệu gửi... ]]
 * hoặc NULL nếu CHƯA khai báo — khi đó chứng từ KHÔNG được gửi đi, báo lỗi rõ "chưa khai báo".
 *
 * Mỗi hàm *_result() nhận câu trả lời đã giải JSON ($data) và trả về dạng tgs_pos cần.
 *
 * ── PAYLOAD HOÁ ĐƠN BÁN (createRetail) ────────────────────────────────────────────────────
 *   khach            ['ma' => Mã KH HTsoft ('' = khách lẻ), 'ten', 'di_dong']
 *   branch           ['srid' => ID chi nhánh, 'code_prefix', 'kho_code' => mã kho, 'nvid', ...]
 *   nvid             ID nhân viên bán bên HTsoft
 *   ngay_hd          'Y-m-d H:i:s' giờ VN — ngày bán THẬT
 *   ca_ten           'Ca sáng' | 'Ca chiều'
 *   ldx_code         mã lý do xuất (XBA, XBB…)
 *   kenh_ban         nguồn bán
 *   ghi_chu          tối đa 100 ký tự
 *   invoice_httt_id / invoice_httt_ten   hình thức thanh toán đại diện của hoá đơn
 *   payments         [ ['htttid' => ID hình thức, 'htttten' => tên, 'nhid' => ID ngân hàng (null = tiền mặt),
 *                       'paid_by_cash' => 1|0, 'amount' => số tiền, 'ghi_chu'], … ]  — mỗi phần tử một phiếu thu
 *   allow_no_receipt true = hoá đơn KHÔNG phiếu thu (bán nợ / đổi trả phủ hết)
 *   so_phieu         CHỈ có ở phiếu Z: mã phiếu chính — phiếu Z phải mang mã = so_phieu + 'Z'
 *   lines            [ [
 *                       'mhcode' => mã hàng, 'soluong' (theo ĐVT gốc), 'giaban' (giá gồm thuế, ĐVT gốc),
 *                       'giaban_sale' (giá gồm thuế theo ĐVT bán), 'chietkhau' (tiền chiết khấu của dòng),
 *                       'unit_name' (ĐVT bán), 'qty_rate' (tỉ lệ quy đổi ĐVT bán → gốc),
 *                       'soluong_ex' (SL theo ĐVT bán), 'ghi_chu'
 *                    ], … ]
 *   Ví dụ thật (lấy từ nút "Xem trước dữ liệu sẽ gửi" ở trang Kiểm tra API):
 *     {"mhcode":"100861007","soluong":1,"giaban":450000,"giaban_sale":450000,"chietkhau":20000,
 *      "ghi_chu":"Bán lẻ theo đơn POS","unit_name":"Lon","qty_rate":1,"soluong_ex":1}
 *   Muốn xem payload thật của bất kỳ phiếu nào: dùng nút đó, không cần đoán.
 *
 * ── KẾT QUẢ tgs_pos CHỜ TỪ createRetail ───────────────────────────────────────────────────
 *   ['ok' => true, 'so_phieu' => số hoá đơn HTsoft cấp, 'bhdid' => ID hoá đơn,
 *    'receipts' => [ ['bpttcode' => mã phiếu thu, 'httt_ten', 'so_tien'], … ]]
 *   hoặc ['ok' => false, 'error' => 'lý do']
 *
 * ── PAYLOAD PHIẾU HOÀN (createReturn) ─────────────────────────────────────────────────────
 *   khach, branch, nvid, ngay_hd, ca_ten, ghi_chu, lines — như trên
 *   bhdcode_goc      số hoá đơn bán GỐC bên HTsoft ('' = hoàn tự do)
 *   ldn_code         mã lý do nhập lại (NTH1…)
 *   invoice_httt_id / invoice_httt_ten   'Đối trừ công nợ' (đổi trả) | 'Công nợ' (hoàn thuần)
 *   refund           false = không sinh phiếu chi
 *   → kết quả chờ: ['ok', 'so_phieu' => số phiếu hoàn, 'bhtid', 'bhdid_goc', 'phieu_chi' => [..]]
 *
 * ── ĐỐI TRỪ (linkReturnOffset) ────────────────────────────────────────────────────────────
 *   ['bhdcode' => hoá đơn bán MỚI, 'bhtcode' => phiếu hoàn, 'doitru' => số tiền đối trừ]
 *   → kết quả chờ: ['ok' => true] hoặc ['ok' => false, 'error']
 */
class TGS_HTsoft_Api_Mapper
{
    /** Hoá đơn bán lẻ (và phiếu Z khi $is_z = true). CHƯA KHAI BÁO — chờ tài liệu HTsoft. */
    public static function retail_request(array $payload, bool $is_z): ?array
    {
        return null;
    }

    public static function retail_result($data): array
    {
        return ['ok' => false, 'error' => 'Chưa khai báo cách đọc kết quả hoá đơn bán lẻ từ API.'];
    }

    /** Phiếu hoàn hàng. CHƯA KHAI BÁO — chờ tài liệu HTsoft. */
    public static function return_request(array $payload): ?array
    {
        return null;
    }

    public static function return_result($data): array
    {
        return ['ok' => false, 'error' => 'Chưa khai báo cách đọc kết quả phiếu hoàn từ API.'];
    }

    /** Đối trừ phiếu hoàn ↔ hoá đơn bán mới. CHƯA KHAI BÁO — chờ tài liệu HTsoft. */
    public static function offset_request(array $args): ?array
    {
        return null;
    }

    public static function offset_result($data): array
    {
        return ['ok' => false, 'error' => 'Chưa khai báo cách đọc kết quả đối trừ từ API.'];
    }

    /** Ba loại chứng từ đã khai báo chưa — trang kiểm tra hiện cho người dùng biết. */
    public static function status(): array
    {
        $probe = ['khach' => [], 'branch' => [], 'lines' => []];
        return [
            'retail' => self::retail_request($probe, false) !== null,
            'return' => self::return_request($probe) !== null,
            'offset' => self::offset_request([]) !== null,
        ];
    }
}
