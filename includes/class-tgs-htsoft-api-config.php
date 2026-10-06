<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cấu hình kết nối API HTsoft — lưu ở site option của network (một bộ cho cả hệ thống).
 * Token KHÔNG nằm trong mã nguồn và không bao giờ được in lại ra trang (chỉ hiện đã có / chưa có).
 *
 * Tài liệu HTsoft không ghi token gửi theo cách nào, nên để cấu hình được cả ba kiểu thường gặp:
 *   - một header tuỳ tên (mặc định tên "ClientTag" — tài liệu nhắc "Có ClientTag" ở nhiều hàm);
 *   - thêm một header thứ hai nếu họ cần (vd Authorization);
 *   - hoặc một tham số trên địa chỉ (?token=...).
 * Điền đúng kiểu HTsoft hướng dẫn, các ô còn lại để trống.
 */
class TGS_HTsoft_Api_Config
{
    const OPT = 'tgs_htsoft_api_config';

    public static function defaults(): array
    {
        return [
            'base_url'      => '',          // vd http://api.xxx.vn:8899  (không kèm /ActionService.svc)
            'service_path'  => '/ActionService.svc',
            'header1_name'  => 'ClientTag',
            'header1_value' => '',
            'header2_name'  => '',
            'header2_value' => '',
            'query_name'    => '',
            'query_value'   => '',
            'timeout'       => 20,          // giây chờ mỗi lượt gọi
            'min_gap_ms'    => 1000,        // nghỉ tối thiểu giữa hai lượt gọi (tránh gọi dồn dập)
            // ĐƯỜNG GHI PHIẾU: 'sql' = như cũ (cổng SQL) · 'api' = tgs_pos đẩy phiếu qua API này.
            // Chỉ đổi sang 'api' khi TGS_HTsoft_Api_Mapper đã khai báo và đã thử một phiếu.
            'write_mode'    => 'sql',
        ];
    }

    public static function get(): array
    {
        $saved = get_site_option(self::OPT, []);
        $cfg = array_merge(self::defaults(), is_array($saved) ? $saved : []);
        $cfg['base_url']     = rtrim(trim((string) $cfg['base_url']), '/');
        $cfg['service_path'] = '/' . trim((string) $cfg['service_path'], '/');
        $cfg['timeout']      = max(5, min(120, (int) $cfg['timeout']));
        $cfg['min_gap_ms']   = max(0, min(10000, (int) $cfg['min_gap_ms']));
        $cfg['write_mode']   = $cfg['write_mode'] === 'api' ? 'api' : 'sql';
        return $cfg;
    }

    /** Đã điền địa chỉ API chưa. */
    public static function ready(): bool
    {
        return self::get()['base_url'] !== '';
    }

    /**
     * Lưu cấu hình từ form. Ô giá trị bí mật để TRỐNG = giữ giá trị cũ (form không in lại token);
     * gõ đúng một dấu "-" = xoá.
     */
    public static function save(array $in): array
    {
        $cur = self::get();
        $new = $cur;
        foreach (['base_url', 'service_path', 'header1_name', 'header2_name', 'query_name'] as $k) {
            if (array_key_exists($k, $in)) {
                $new[$k] = trim(sanitize_text_field((string) $in[$k]));
            }
        }
        foreach (['header1_value', 'header2_value', 'query_value'] as $k) {
            if (!array_key_exists($k, $in)) {
                continue;
            }
            $v = trim((string) $in[$k]);
            if ($v === '') {
                continue;           // giữ nguyên
            }
            $new[$k] = $v === '-' ? '' : $v;
        }
        foreach (['timeout', 'min_gap_ms'] as $k) {
            if (array_key_exists($k, $in)) {
                $new[$k] = (int) $in[$k];
            }
        }
        if (array_key_exists('write_mode', $in)) {
            $new['write_mode'] = $in['write_mode'] === 'api' ? 'api' : 'sql';
        }
        if ($new['base_url'] !== '' && !preg_match('#^https?://#i', $new['base_url'])) {
            $new['base_url'] = 'http://' . $new['base_url'];
        }
        update_site_option(self::OPT, $new);
        return self::get();
    }

    /** Bản cấu hình an toàn để đưa ra giao diện: giá trị bí mật chỉ còn "đã có / chưa có". */
    public static function for_display(): array
    {
        $c = self::get();
        foreach (['header1_value', 'header2_value', 'query_value'] as $k) {
            $c[$k . '_set'] = $c[$k] !== '' ? 1 : 0;
            unset($c[$k]);
        }
        return $c;
    }
}
