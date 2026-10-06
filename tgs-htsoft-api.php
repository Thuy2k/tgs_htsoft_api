<?php
/**
 * Plugin Name: TGS HTSoft API
 * Description: Kết nối HTsoft qua API dịch vụ (ActionService.svc) thay cho kết nối SQL trực tiếp. Bước 1: cấu hình + kiểm tra các hàm chỉ đọc.
 * Version: 0.1.0
 * Author: BIZGPT_AI
 * Network: true
 */

if (!defined('ABSPATH')) {
    exit;
}

/*
 * VÌ SAO CÓ PLUGIN NÀY (05/10/2026)
 *
 * Trước giờ mọi thứ nói chuyện với HTsoft qua SQL Server cổng 2016 (plugin tgs_htsoft_connector).
 * Từ 04/10/2026 HTsoft chặn đường đó từ máy chủ web. HTsoft cho biết bộ API dịch vụ của họ
 * (tài liệu: tgs_htsoft_connector/docs/tailieuapihtsoft1.text và tailieuapihtsoft2.text) vẫn dùng
 * được với một token tĩnh. Plugin này là đường đi qua API đó.
 *
 * PHẠM VI BẢN 0.1 — CHỈ ĐỌC:
 *  - Lưu cấu hình: địa chỉ API + token (site option của network, không nằm trong mã nguồn).
 *  - Lớp gọi API dùng chung (TGS_HTsoft_Api_Client): giãn nhịp giữa các lượt gọi, ngắt nhanh khi
 *    không tới được, ghi nhật ký gọn.
 *  - Trang "Kiểm tra API HTsoft" (Quản trị → Công cụ đối chiếu, chỉ quản trị cấp cao): bấm thử
 *    từng hàm CHỈ ĐỌC và xem kết quả thật, để đánh giá API có đủ dữ liệu cho mình không.
 *
 * CHƯA LÀM (cố ý): mọi hàm GHI (AddOrder*, ImportOrder, AddCustomer, AddPartner, Update*). Trang
 * kiểm tra KHÔNG cho gọi các hàm này. Chỉ mở sau khi HTsoft xác nhận rõ mỗi hàm ghi tạo ra chứng
 * từ gì bên họ (đơn đặt hàng hay hoá đơn bán lẻ có trừ tồn + phiếu thu) — xem docs/DANH_GIA_API.md.
 */

define('TGS_HTSOFT_API_FILE', __FILE__);
define('TGS_HTSOFT_API_DIR', plugin_dir_path(__FILE__));
define('TGS_HTSOFT_API_VERSION', '0.1.0');

require_once TGS_HTSOFT_API_DIR . 'includes/class-tgs-htsoft-api-config.php';
require_once TGS_HTSOFT_API_DIR . 'includes/class-tgs-htsoft-api-client.php';
require_once TGS_HTSOFT_API_DIR . 'includes/class-tgs-htsoft-api-catalog.php';
require_once TGS_HTSOFT_API_DIR . 'includes/class-tgs-htsoft-api-mapper.php';   // CHỖ ĐIỀN khi HTsoft gửi tài liệu API hoá đơn
require_once TGS_HTSOFT_API_DIR . 'includes/class-tgs-htsoft-api-invoice.php';  // dịch vụ ghi phiếu qua API + xem trước
require_once TGS_HTSOFT_API_DIR . 'includes/class-tgs-htsoft-api-admin.php';

add_action('plugins_loaded', ['TGS_HTsoft_Api_Admin', 'init']);
add_action('plugins_loaded', ['TGS_HTsoft_Api_Invoice', 'init']);
