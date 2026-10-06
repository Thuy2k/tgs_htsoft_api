# Đánh giá API HTsoft: dùng được tới đâu cho việc đồng bộ

Lập ngày 05/10/2026, dựa trên hai tài liệu HTsoft gửi (`tgs_htsoft_connector/docs/tailieuapihtsoft1.text` và `tailieuapihtsoft2.text`).

## Kết luận ngắn

- **Chiều đọc (lấy dữ liệu về): trên giấy thì đủ dùng** cho giá, tồn, khách hàng, danh mục và đối chiếu hoá đơn. Chưa xác nhận được bằng lượt gọi thật, vì tài liệu không có địa chỉ chính thức và token.
- **Chiều ghi (đẩy phiếu bán, phiếu trả hôm qua lên HTsoft): tài liệu KHÔNG cho thấy làm được như đang làm qua SQL.** Các hàm ghi chỉ tạo "đơn hàng từ web". Không có hàm nào cho phiếu trả, phiếu thu, gắn hoá đơn VAT hay đối trừ.
- Vì vậy API thay được phần đọc, nhưng **chưa thay được việc đẩy bù phiếu BT** nếu HTsoft không bổ sung hoặc xác nhận thêm. Cần hỏi HTsoft các câu ở mục 5 trước khi viết phần ghi.

## 1. Tình trạng kiểm tra thực tế

| Việc | Kết quả |
|---|---|
| Đọc hết hai tài liệu | Xong: 64 hàm trong tài liệu 2, 50 hàm trong tài liệu 1 |
| Gọi miền thử nghiệm `devapi.htsoft.vn:8899` (3 lượt chỉ đọc) | Không phản hồi (hết giờ chờ kết nối). Miền này trỏ về `118.70.181.86` |
| Địa chỉ chính thức | Tài liệu để trống dòng "Domain Active" |
| Token và cách gửi token | Tài liệu không ghi. Chỉ nhắc "Có ClientTag" ở một số hàm |
| Lớp gọi API của plugin này | Đã thử với máy chủ giả: GET, POST, ngày giờ, sai token, mất kết nối, chặn hàm ghi đều đúng |

Chưa có lượt gọi thật nào thành công tới HTsoft. Mọi nhận định dưới đây là theo tài liệu.

## 2. Chiều đọc: hàm nào thay được việc gì

| Việc đang làm qua SQL | Hàm API | Nhận xét |
|---|---|---|
| Danh sách kho, chi nhánh | `GetAllListStore` | Có mã kho, ID kho, ID chi nhánh |
| Tồn và giá bán theo kho | `GetPriceAndQuantityByInventoryCode` | Theo mã kho + mã hàng; trả tồn, giá bán, giá niêm yết, đơn vị, mã vạch |
| Mặt hàng thay đổi từ một ngày | `SyncProductWebByDate`, `SyncProductWebByDate2` | Ứng viên thay cron đồng bộ giá |
| Khách hàng theo SĐT | `GetCustomerInfoByCallerID` | Có mã khách và ID khách |
| Hình thức thanh toán, ngân hàng, nhóm hàng | `LoadAllPaymentType`, `LoadAllPaymentBank`, `LoadAllProductCatelogy` | Đủ |
| Kiểm một hoá đơn đã lên chưa | `GetInvoicerInfobyInvoiceCode` | Trả tiền, khách, dòng hàng |
| Danh sách hoá đơn, phiếu thu theo ngày và chi nhánh | `GetRevenueInvoiceByDate`, `GetPaymentInvoiceByDate` | Dùng để đối chiếu cả ngày |
| Lịch sử trả hàng | `GetSaleReturnHistoryByCallerID` | Chỉ tra theo SĐT khách |

Điểm chưa rõ ở chiều đọc, phải nhìn dữ liệu thật mới biết:

1. **Đơn vị quy đổi.** Bên mình mỗi mã có nhiều đơn vị (hộp, lốc, thùng) với tỉ lệ và giá riêng, và có "đơn vị bán chính". Tài liệu chỉ thấy một trường đơn vị và một giá cho mỗi mã.
2. **Bảng giá theo chi nhánh và cờ "hàng chính".** Không thấy trong tài liệu.
3. **Thuế suất và cờ không chịu thuế của mặt hàng.** Không thấy trường rõ ràng.
4. **Loại mặt hàng cấm bán** (đang lấy qua SQL). Có `productTypeID`, chưa rõ có khớp không.

## 3. Chiều ghi: tài liệu có gì và thiếu gì

Các hàm ghi liên quan tới bán hàng: `AddOrder`, `AddOrder1`, `AddOrder2`, `ImportOrder`, `UpdateOrderStatus`, `UpdateInvoiceStatus`; và khách hàng: `AddCustomer`, `AddPartner`, `AddPartnerEx`, `UpdatePartner`.

`AddOrder2` là hàm gần nhất với nhu cầu: nhận mã đơn do mình đặt, mã hàng, mã kho, đơn giá, thuế, số lượng, ID hình thức thanh toán, ID ngân hàng, thông tin người mua.

So với những gì đang đẩy qua SQL:

| Đang đẩy qua SQL | Trong API |
|---|---|
| Hoá đơn bán lẻ, trừ tồn kho, HTsoft cấp số phiếu | Chỉ có "thêm đơn đặt hàng từ web". Tài liệu không nói đơn này có thành hoá đơn bán và trừ tồn hay không |
| Phiếu thu theo từng hình thức (đa hình thức thanh toán) | Không có hàm tạo phiếu thu. Đơn hàng chỉ nhận một hình thức và một ngân hàng |
| Phiếu Z (mã phiếu chính + Z) | Không có khái niệm này; có thể gửi thành đơn thứ hai, chưa rõ HTsoft xử lý ra sao |
| Phiếu hoàn hàng | **Không có hàm ghi nào** |
| Đối trừ phiếu hoàn với phiếu bán mới | Không có |
| Gắn số hoá đơn điện tử (VAT) vào phiếu | Không có |
| Bán theo đơn vị quy đổi (lốc, thùng) | Dòng hàng không có trường đơn vị |
| Chiết khấu từng dòng | Dòng hàng không có trường chiết khấu (chỉ có giá niêm yết và đơn giá) |
| Lý do xuất, ca làm việc, nhân viên bán | Không có |
| Khách hàng | Có: `AddCustomer`, `AddPartner` (mã do mình đặt) |
| Duyệt chuyển kho, xuất điều chỉnh, chuyển quỹ | Không có |

## 4. Bước cuối "DATA VAT" có phụ thuộc không

Có. Việc đẩy sang DB VAT hiện đọc phiếu **từ DB vận hành của HTsoft** rồi mới chép sang DB VAT. Đường ghi sang DB VAT vẫn thông, nhưng đường đọc DB vận hành đang bị chặn, và API không trả các mã nội bộ mà bước chép đang dùng. Nên dù phiếu có lên HTsoft bằng API, bước DB VAT vẫn cần hoặc đường SQL mở lại, hoặc viết lại để dựng từ dữ liệu bên mình.

## 5. Câu hỏi cần HTsoft trả lời

1. Địa chỉ API chính thức và token; token gửi bằng header nào (tên header) hay tham số nào.
2. Giới hạn số lượt gọi mỗi phút, mỗi ngày; IP gọi có cần đăng ký không.
3. `AddOrder2` (hoặc `ImportOrder`) tạo ra chứng từ gì: đơn đặt hàng chờ duyệt, hay hoá đơn bán lẻ có trừ tồn? Nếu là đơn đặt hàng thì ai chuyển thành hoá đơn, và mã hoá đơn có theo mã mình gửi không.
4. Phiếu thu: tạo bằng cách nào khi một đơn trả bằng nhiều hình thức (tiền mặt + QR)?
5. Phiếu trả hàng: có hàm nào không? Nếu chưa, HTsoft bổ sung được không.
6. Gắn số hoá đơn điện tử vào hoá đơn bán: có hàm nào không.
7. Bán theo đơn vị quy đổi và chiết khấu từng dòng: gửi thế nào.
8. Chiều đọc: có trả đơn vị quy đổi, giá theo từng đơn vị, bảng giá theo chi nhánh, thuế suất của mặt hàng không.
9. Nếu API không đủ cho phần ghi: HTsoft có thể mở lại đường SQL cho đúng một IP để đẩy bù các phiếu đang chờ không.

## 6. Plugin `tgs_htsoft_api` hiện có gì

- `TGS_HTsoft_Api_Config`: địa chỉ và token lưu ở site option của network, không nằm trong mã nguồn; hỗ trợ ba cách gửi token.
- `TGS_HTsoft_Api_Client::call()`: lớp gọi dùng chung; giãn nhịp giữa các lượt gọi, ngắt nhanh khi không tới được, nhật ký không chứa token hay dữ liệu; đổi ngày giờ kiểu WCF.
- Trang "Kiểm tra API HTsoft" (Quản trị → Công cụ đối chiếu, chỉ quản trị cấp cao): 16 hàm chỉ đọc, bấm là gọi, xem kết quả thật và sửa được dữ liệu gửi.
- Hàm ghi bị chặn ở lớp gọi; chỉ mở sau khi có trả lời cho mục 5.
- **Đường ghi phiếu đã sẵn (bổ sung 05/10):** `tgs_pos` lấy dịch vụ ghi phiếu qua `TGS_POS_HTsoft_Invoice_Push::invoice_service()`; plugin này thay được dịch vụ đó bằng `TGS_HTsoft_Api_Invoice`. Toàn bộ luồng đẩy hiện có (phiếu Z, phiếu thu, đối trừ, nhật ký, nút đẩy toàn bộ phiếu BT) dùng lại nguyên vẹn.
- **Chỗ duy nhất phải điền khi HTsoft gửi tài liệu API hoá đơn:** `includes/class-tgs-htsoft-api-mapper.php` (ba cặp hàm: hoá đơn bán + phiếu Z, phiếu hoàn, đối trừ). Chưa điền thì không gửi gì.
- **Xem trước dữ liệu sẽ gửi:** ở trang kiểm tra, nhập mã shop + mã phiếu → hiện đúng dữ liệu từng bước, không gửi và không đổi sổ. Dùng để đưa HTsoft xem mình cần những trường nào.
- **Còn thiếu cho đường API:** bước cấp Mã KH trước khi đẩy vẫn đi đường SQL; cần chuyển sang `GetCustomerInfoByCallerID` / `AddCustomer` khi có token. Gắn VAT và đẩy DB VAT cũng vẫn là đường SQL.

## 7. Bước tiếp theo

1. Lấy địa chỉ chính thức và token, điền ở trang kiểm tra, gọi `GetAllListStore`.
2. Gọi lần lượt các hàm đọc với một kho, một mã hàng, một SĐT, một số phiếu thật; ghi lại hàm nào trả đủ trường.
3. Gửi HTsoft các câu hỏi mục 5.
4. Theo kết quả: viết phần đọc (giá, tồn, khách) trước; phần ghi chỉ viết khi biết chắc `AddOrder2` tạo ra chứng từ gì.
