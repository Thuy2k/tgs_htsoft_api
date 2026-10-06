<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Danh sách các hàm CHỈ ĐỌC của API HTsoft dùng cho trang kiểm tra — kèm dữ liệu gửi mẫu (đúng
 * tên trường trong tailieuapihtsoft2.text) và ghi chú hàm đó giúp gì cho mình.
 *
 * Trong dữ liệu mẫu có thể dùng các chỗ thế, trang kiểm tra tự điền trước khi gọi:
 *   {{TODAY_START}} / {{TODAY_END}}   đầu / cuối ngày đang chọn, dạng ngày WCF
 *   {{WAREHOUSE}}  mã kho      {{SKU}}  mã hàng      {{PHONE}}  SĐT      {{INVOICE}}  số phiếu
 *   {{BRANCH_ID}}  ID chi nhánh (GUID)
 *
 * Hàm GHI (Add*, Import*, Insert*, Update*) cố ý KHÔNG có ở đây.
 */
class TGS_HTsoft_Api_Catalog
{
    public static function all(): array
    {
        return [
            'GetAllListStore' => [
                'group' => 'Danh mục', 'method' => 'GET', 'body' => null,
                'label' => 'Danh sách kho',
                'note'  => 'Thử đầu tiên: không cần tham số, biết ngay địa chỉ + token có đúng không. Cho mã kho và ID chi nhánh để dùng ở các hàm khác.',
            ],
            'GetCountProductActive' => [
                'group' => 'Danh mục', 'method' => 'GET', 'body' => null,
                'label' => 'Đếm số mặt hàng đang dùng',
                'note'  => 'Nhẹ, dùng để kiểm kết nối.',
            ],
            'LoadAllPaymentType' => [
                'group' => 'Danh mục', 'method' => 'GET', 'body' => null,
                'label' => 'Hình thức thanh toán',
                'note'  => 'Cần ID hình thức thanh toán nếu sau này gửi đơn qua API.',
            ],
            'LoadAllPaymentBank' => [
                'group' => 'Danh mục', 'method' => 'GET', 'body' => null,
                'label' => 'Ngân hàng',
                'note'  => 'Cần ID ngân hàng cho đơn chuyển khoản / QR.',
            ],
            'LoadAllProductCatelogy' => [
                'group' => 'Danh mục', 'method' => 'GET', 'body' => null,
                'label' => 'Nhóm hàng',
                'note'  => '',
            ],
            'GetPriceAndQuantityByInventoryCode' => [
                'group' => 'Giá và tồn', 'method' => 'POST',
                'body'  => ['MerchantSKU' => '{{SKU}}', 'WarehouseCode' => '{{WAREHOUSE}}'],
                'label' => 'Tồn + giá bán theo mã kho',
                'note'  => 'Quan trọng nhất cho chiều ĐỌC: tồn, giá bán, giá niêm yết, đơn vị, mã vạch theo từng kho. Để trống mã hàng = cả kho (có thể rất nặng — thử với một mã trước).',
            ],
            'GetProductInfoBySku' => [
                'group' => 'Giá và tồn', 'method' => 'POST',
                'body'  => ['SKU' => '{{SKU}}'],
                'label' => 'Thông tin một mã hàng (giá, khuyến mãi, tồn theo kho)',
                'note'  => '',
            ],
            'GetProductQuantityBySku' => [
                'group' => 'Giá và tồn', 'method' => 'POST',
                'body'  => ['SKU' => '{{SKU}}'],
                'label' => 'Tồn + giá lẻ/buôn của một mã theo từng chi nhánh',
                'note'  => '',
            ],
            'SyncProductWebByDate' => [
                'group' => 'Giá và tồn', 'method' => 'POST',
                'body'  => ['Date' => '{{TODAY_START}}'],
                'label' => 'Mặt hàng thêm / sửa từ ngày chọn',
                'note'  => 'Ứng viên thay cho cron đồng bộ giá: chỉ kéo phần thay đổi. Cần xem có trả ĐƠN VỊ QUY ĐỔI và giá theo từng đơn vị không.',
            ],
            'GetCustomerInfoByCallerID' => [
                'group' => 'Khách hàng', 'method' => 'POST',
                'body'  => ['callerid' => '{{PHONE}}'],
                'label' => 'Khách hàng theo số điện thoại',
                'note'  => 'Cho Mã KH + ID khách. Thay được bước "tra khách theo SĐT" đang làm qua SQL.',
            ],
            'GetCountAllCustomer' => [
                'group' => 'Khách hàng', 'method' => 'GET', 'body' => null,
                'label' => 'Đếm số khách hàng',
                'note'  => '',
            ],
            'GetInvoicerInfobyInvoiceCode' => [
                'group' => 'Hoá đơn', 'method' => 'POST',
                'body'  => ['invoiceCode' => '{{INVOICE}}'],
                'label' => 'Một hoá đơn bán theo số phiếu',
                'note'  => 'Dùng để ĐỐI CHIẾU: phiếu mình nghĩ đã lên HTsoft có thật sự ở đó, đúng tiền, đúng dòng hàng không.',
            ],
            'GetRevenueInvoiceByDate' => [
                'group' => 'Hoá đơn', 'method' => 'POST',
                'body'  => [
                    'branchIDList' => [['BranchID' => '{{BRANCH_ID}}']],
                    'startDate' => '{{TODAY_START}}', 'endDate' => '{{TODAY_END}}',
                    'page' => 1, 'perPage' => 50,
                ],
                'label' => 'Danh sách hoá đơn bán theo ngày + chi nhánh',
                'note'  => 'Dùng để đối chiếu cả ngày: phiếu nào có bên HTsoft, tổng tiền bao nhiêu.',
            ],
            'GetPaymentInvoiceByDate' => [
                'group' => 'Hoá đơn', 'method' => 'POST',
                'body'  => [
                    'branchIDList' => [['BranchID' => '{{BRANCH_ID}}']],
                    'startDate' => '{{TODAY_START}}', 'endDate' => '{{TODAY_END}}',
                    'page' => 1, 'perPage' => 50,
                ],
                'label' => 'Danh sách phiếu thu theo ngày + chi nhánh',
                'note'  => 'Đối chiếu phiếu thu: mã phiếu thu, hoá đơn, hình thức, số tiền.',
            ],
            'GetAllOrderbyDate' => [
                'group' => 'Hoá đơn', 'method' => 'POST',
                'body'  => ['fromdate' => '{{TODAY_START}}', 'todate' => '{{TODAY_END}}'],
                'label' => 'Đơn hàng theo khoảng ngày (kèm dòng hàng)',
                'note'  => 'Không lọc theo chi nhánh — có thể trả rất nhiều. Thử với khoảng ngày hẹp.',
            ],
            'GetSaleReturnHistoryByCallerID' => [
                'group' => 'Hoá đơn', 'method' => 'POST',
                'body'  => ['callerid' => '{{PHONE}}'],
                'label' => 'Lịch sử trả hàng của một khách',
                'note'  => 'Đường ĐỌC duy nhất về phiếu trả trong tài liệu. Không có hàm nào để GHI phiếu trả.',
            ],
        ];
    }

    public static function get(string $fn): ?array
    {
        $all = self::all();
        return $all[$fn] ?? null;
    }
}
