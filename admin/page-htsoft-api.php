<?php
/**
 * KIỂM TRA API HTSOFT — Quản trị → Công cụ đối chiếu. Chỉ quản trị cấp cao.
 * Lưu địa chỉ + token, rồi bấm thử từng hàm CHỈ ĐỌC để xem dữ liệu thật.
 *
 * @package tgs_htsoft_api
 */
if (!defined('ABSPATH')) {
    exit;
}
if (!TGS_HTsoft_Api_Admin::can_use()) {
    echo '<div style="padding:24px">Chỉ quản trị cấp cao dùng được trang này.</div>';
    return;
}
$hta_cfg = TGS_HTsoft_Api_Config::for_display();
$hta_fns = TGS_HTsoft_Api_Catalog::all();
$hta_today = current_time('Y-m-d');
?>
<style>
    .hta { padding: 16px; font-size: 13px; color: #0f172a; max-width: 1280px; }
    .hta h2 { font-size: 16px; margin: 0 0 4px; }
    .hta h3 { font-size: 14px; margin: 18px 0 8px; }
    .hta .muted { color: #64748b; font-size: 12px; }
    .hta-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 14px; margin-top: 10px; }
    .hta-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)); gap: 10px; }
    .hta label { display: block; font-size: 12px; color: #475569; margin-bottom: 3px; }
    .hta input, .hta textarea { width: 100%; box-sizing: border-box; padding: 6px 8px; border: 1px solid #cbd5e1; border-radius: 7px; font-size: 13px; background: #fff; }
    .hta textarea { font-family: Consolas, monospace; font-size: 12px; min-height: 90px; }
    .hta-btn { padding: 6px 14px; border-radius: 7px; border: 1px solid #2563eb; background: #2563eb; color: #fff; font-weight: 600; cursor: pointer; }
    .hta-btn.ghost { background: #fff; color: #2563eb; }
    .hta-fn { display: flex; gap: 10px; align-items: flex-start; padding: 8px 0; border-bottom: 1px solid #f1f5f9; }
    .hta-fn:last-child { border-bottom: 0; }
    .hta-fn b { display: block; }
    .hta-fn code { font-size: 11.5px; color: #475569; }
    .hta-tag { display: inline-block; padding: 0 8px; border-radius: 99px; font-size: 11px; font-weight: 700; }
    .hta-ok { background: #dcfce7; color: #166534; } .hta-fail { background: #fee2e2; color: #991b1b; }
    .hta pre { background: #0f172a; color: #e2e8f0; padding: 12px; border-radius: 8px; overflow: auto; max-height: 55vh; font-size: 12px; white-space: pre-wrap; word-break: break-word; }
    .hta table { width: 100%; border-collapse: collapse; font-size: 12px; }
    .hta td, .hta th { padding: 4px 8px; border-bottom: 1px solid #f1f5f9; text-align: left; }
</style>

<div class="hta">
    <h2>Kiểm tra API HTsoft</h2>
    <div class="muted">Đường kết nối HTsoft qua API dịch vụ (ActionService.svc) thay cho SQL trực tiếp. Trang này chỉ gọi các hàm <b>chỉ đọc</b> —
        không tạo, không sửa gì bên HTsoft. Mỗi lượt gọi cách nhau tối thiểu theo ô "Nghỉ giữa hai lượt gọi".</div>

    <div class="hta-card">
        <h3 style="margin-top:0">1. Cấu hình kết nối</h3>
        <div class="hta-grid">
            <div><label>Địa chỉ API (không kèm /ActionService.svc)</label><input id="htaBase" value="<?php echo esc_attr($hta_cfg['base_url']); ?>" placeholder="http://ten-mien:8899"></div>
            <div><label>Đường dẫn dịch vụ</label><input id="htaPath" value="<?php echo esc_attr($hta_cfg['service_path']); ?>"></div>
            <div><label>Chờ tối đa mỗi lượt (giây)</label><input id="htaTimeout" type="number" value="<?php echo (int) $hta_cfg['timeout']; ?>"></div>
            <div><label>Nghỉ giữa hai lượt gọi (mili giây)</label><input id="htaGap" type="number" value="<?php echo (int) $hta_cfg['min_gap_ms']; ?>"></div>
        </div>
        <div class="muted" style="margin:10px 0 6px">Token: điền theo đúng cách HTsoft hướng dẫn (một trong ba kiểu), các ô khác để trống. Ô giá trị để trống = giữ giá trị đang lưu; gõ một dấu <b>-</b> = xoá.</div>
        <div class="hta-grid">
            <div><label>Header 1 — tên</label><input id="htaH1n" value="<?php echo esc_attr($hta_cfg['header1_name']); ?>"></div>
            <div><label>Header 1 — giá trị <?php echo $hta_cfg['header1_value_set'] ? '(đã lưu)' : '(chưa có)'; ?></label><input id="htaH1v" type="password" autocomplete="new-password"></div>
            <div><label>Header 2 — tên</label><input id="htaH2n" value="<?php echo esc_attr($hta_cfg['header2_name']); ?>" placeholder="vd Authorization"></div>
            <div><label>Header 2 — giá trị <?php echo $hta_cfg['header2_value_set'] ? '(đã lưu)' : '(chưa có)'; ?></label><input id="htaH2v" type="password" autocomplete="new-password"></div>
            <div><label>Tham số địa chỉ — tên</label><input id="htaQn" value="<?php echo esc_attr($hta_cfg['query_name']); ?>" placeholder="vd token"></div>
            <div><label>Tham số địa chỉ — giá trị <?php echo $hta_cfg['query_value_set'] ? '(đã lưu)' : '(chưa có)'; ?></label><input id="htaQv" type="password" autocomplete="new-password"></div>
        </div>
        <div style="margin-top:10px"><button type="button" class="hta-btn" id="htaSave">Lưu cấu hình</button> <span class="muted" id="htaSaveMsg"></span></div>
    </div>

    <div class="hta-card">
        <h3 style="margin-top:0">2. Tham số dùng cho các hàm bên dưới</h3>
        <div class="hta-grid">
            <div><label>Ngày</label><input id="htaDate" type="date" value="<?php echo esc_attr($hta_today); ?>"></div>
            <div><label>Mã kho</label><input id="htaWh" placeholder="vd 18002"></div>
            <div><label>ID chi nhánh (GUID — lấy từ "Danh sách kho": storeSrid)</label><input id="htaBr"></div>
            <div><label>Mã hàng</label><input id="htaSku"></div>
            <div><label>Số điện thoại khách</label><input id="htaPhone"></div>
            <div><label>Số phiếu / mã hoá đơn</label><input id="htaInv"></div>
        </div>
    </div>

    <div class="hta-card">
        <h3 style="margin-top:0">3. Bấm thử từng hàm (chỉ đọc)</h3>
        <?php $hta_group = '';
        foreach ($hta_fns as $hta_fn => $hta_def) :
            if ($hta_def['group'] !== $hta_group) {
                $hta_group = $hta_def['group'];
                echo '<div class="muted" style="margin:10px 0 2px;font-weight:700;text-transform:uppercase">' . esc_html($hta_group) . '</div>';
            } ?>
            <div class="hta-fn">
                <button type="button" class="hta-btn ghost" data-fn="<?php echo esc_attr($hta_fn); ?>" style="flex:none;min-width:70px">Gọi</button>
                <div>
                    <b><?php echo esc_html($hta_def['label']); ?></b>
                    <code><?php echo esc_html($hta_def['method'] . ' ' . $hta_fn); ?></code>
                    <?php if ($hta_def['note'] !== '') : ?><div class="muted"><?php echo esc_html($hta_def['note']); ?></div><?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="hta-card">
        <h3 style="margin-top:0">5. Đẩy phiếu qua API (chuẩn bị sẵn)</h3>
        <?php $hta_map = TGS_HTsoft_Api_Mapper::status(); $hta_wm = TGS_HTsoft_Api_Config::get()['write_mode']; ?>
        <div class="muted">Toàn bộ luồng đẩy phiếu hiện có (khách hàng, phiếu Z, phiếu thu, đối trừ, nhật ký, nút đẩy toàn bộ phiếu BT) dùng lại nguyên vẹn —
            chỉ đổi "đường đi" từ SQL sang API. Phần còn thiếu duy nhất là khai báo lời gọi API ở file
            <code>includes/class-tgs-htsoft-api-mapper.php</code> khi HTsoft gửi tài liệu.</div>
        <table style="margin:8px 0;max-width:560px">
            <tr><th>Chứng từ</th><th>Đã khai báo lời gọi API</th></tr>
            <tr><td>Hoá đơn bán lẻ + phiếu Z</td><td><span class="hta-tag <?php echo $hta_map['retail'] ? 'hta-ok">rồi' : 'hta-fail">chưa'; ?></span></td></tr>
            <tr><td>Phiếu hoàn</td><td><span class="hta-tag <?php echo $hta_map['return'] ? 'hta-ok">rồi' : 'hta-fail">chưa'; ?></span></td></tr>
            <tr><td>Đối trừ phiếu hoàn ↔ hoá đơn mới</td><td><span class="hta-tag <?php echo $hta_map['offset'] ? 'hta-ok">rồi' : 'hta-fail">chưa'; ?></span></td></tr>
        </table>
        <div class="hta-grid" style="align-items:end">
            <div><label>Đường ghi phiếu của toàn hệ thống</label>
                <select id="htaWm" style="width:100%;padding:6px 8px;border:1px solid #cbd5e1;border-radius:7px">
                    <option value="sql" <?php selected($hta_wm, 'sql'); ?>>SQL (như cũ)</option>
                    <option value="api" <?php selected($hta_wm, 'api'); ?>>API (plugin này)</option>
                </select></div>
            <div><button type="button" class="hta-btn ghost" id="htaWmSave">Lưu đường ghi phiếu</button> <span class="muted" id="htaWmMsg"></span></div>
        </div>
        <div class="muted" style="margin-top:4px">Chọn API khi chưa khai báo thì mọi lượt đẩy phiếu sẽ dừng với thông báo "chưa khai báo", không gửi gì và phiếu vẫn giữ mã BT.</div>

        <h3>Xem trước dữ liệu sẽ gửi của một phiếu</h3>
        <div class="muted">Chạy đúng luồng đẩy của phiếu đó nhưng <b>không gửi gì</b> và không đổi gì trong sổ. Dùng để đưa cho HTsoft xem mình cần gửi những trường nào.</div>
        <div class="hta-grid" style="margin-top:6px;align-items:end">
            <div><label>Mã shop (vd 18002)</label><input id="htaPvShop"></div>
            <div><label>Mã phiếu bán hoặc phiếu hoàn (vd BTAA00003354)</label><input id="htaPvCode"></div>
            <div><button type="button" class="hta-btn" id="htaPvGo">Xem trước</button> <span class="muted" id="htaPvMsg"></span></div>
        </div>
        <pre id="htaPvOut" style="margin-top:8px">Chưa xem phiếu nào.</pre>
    </div>

    <div class="hta-card">
        <h3 style="margin-top:0">4. Kết quả <span id="htaResHead" class="muted"></span></h3>
        <div id="htaShape" class="muted" style="margin-bottom:6px"></div>
        <label>Dữ liệu đã gửi (sửa được rồi bấm "Gọi lại với dữ liệu này" nếu tên trường thực tế khác tài liệu)</label>
        <textarea id="htaBody" placeholder="(hàm GET không gửi dữ liệu)"></textarea>
        <div style="margin:6px 0 10px"><button type="button" class="hta-btn ghost" id="htaAgain" disabled>Gọi lại với dữ liệu này</button></div>
        <pre id="htaOut">Chưa gọi hàm nào.</pre>
        <h3>Các lượt gọi gần nhất hôm nay</h3>
        <table><thead><tr><th>Lúc</th><th>Hàm</th><th>HTTP</th><th>Thời gian</th><th>Dung lượng</th><th>Lỗi</th></tr></thead><tbody id="htaLog"><tr><td colspan="6" class="muted">—</td></tr></tbody></table>
    </div>
</div>

<script>
(function () {
    var AJAX = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
    var NONCE = <?php echo wp_json_encode(wp_create_nonce(TGS_HTsoft_Api_Admin::NONCE)); ?>;
    var $ = function (id) { return document.getElementById(id); };
    var lastFn = '';
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
    function post(action, data) {
        var fd = new FormData();
        fd.append('action', action); fd.append('nonce', NONCE);
        Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
        return fetch(AJAX, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); });
    }

    $('htaSave').addEventListener('click', function () {
        $('htaSaveMsg').textContent = 'Đang lưu…';
        post('tgs_htsoft_api_save', {
            base_url: $('htaBase').value, service_path: $('htaPath').value,
            timeout: $('htaTimeout').value, min_gap_ms: $('htaGap').value,
            header1_name: $('htaH1n').value, header1_value: $('htaH1v').value,
            header2_name: $('htaH2n').value, header2_value: $('htaH2v').value,
            query_name: $('htaQn').value, query_value: $('htaQv').value
        }).then(function (out) {
            $('htaSaveMsg').textContent = (out && out.success) ? 'Đã lưu. Bấm "Gọi" ở hàm Danh sách kho để thử.' : ((out && out.data && out.data.message) || 'Không lưu được.');
            ['htaH1v', 'htaH2v', 'htaQv'].forEach(function (id) { $(id).value = ''; });
        }).catch(function () { $('htaSaveMsg').textContent = 'Không lưu được.'; });
    });

    function call(fn, body) {
        lastFn = fn;
        $('htaResHead').textContent = '— đang gọi ' + fn + '…';
        $('htaOut').textContent = 'Đang gọi…';
        post('tgs_htsoft_api_call', {
            fn: fn, body: body || '',
            date: $('htaDate').value, warehouse: $('htaWh').value, branch_id: $('htaBr').value,
            sku: $('htaSku').value, phone: $('htaPhone').value, invoice: $('htaInv').value
        }).then(function (out) {
            if (!out || !out.success) {
                $('htaResHead').innerHTML = '— ' + esc(fn) + ' <span class="hta-tag hta-fail">chưa gọi</span>';
                $('htaOut').textContent = (out && out.data && out.data.message) || 'Không gọi được.';
                return;
            }
            var d = out.data;
            $('htaResHead').innerHTML = '— ' + esc(fn) + ' <span class="hta-tag ' + (d.ok ? 'hta-ok">được' : 'hta-fail">lỗi') + '</span> · HTTP ' + d.http + ' · ' + d.ms + ' ms · ' + d.bytes + ' byte';
            $('htaShape').textContent = d.error ? d.error : d.shape;
            $('htaBody').value = d.sent ? JSON.stringify(d.sent, null, 2) : '';
            $('htaAgain').disabled = !d.sent;
            $('htaOut').textContent = d.pretty || '(rỗng)';
            $('htaLog').innerHTML = (d.log || []).map(function (r) {
                return '<tr><td>' + esc(String(r.t).slice(11)) + '</td><td>' + esc(r.fn) + '</td><td>' + esc(r.http) + '</td><td>' + esc(r.ms) + ' ms</td><td>' + esc(r.b) + '</td><td>' + esc(r.err || '') + '</td></tr>';
            }).join('') || '<tr><td colspan="6" class="muted">—</td></tr>';
        }).catch(function () { $('htaOut').textContent = 'Không gọi được (lỗi mạng tới máy chủ của mình).'; });
    }

    document.querySelectorAll('.hta-fn [data-fn]').forEach(function (b) {
        b.addEventListener('click', function () { call(b.getAttribute('data-fn'), ''); });
    });
    $('htaAgain').addEventListener('click', function () { if (lastFn) { call(lastFn, $('htaBody').value); } });

    $('htaWmSave').addEventListener('click', function () {
        var v = $('htaWm').value;
        if (v === 'api' && !window.confirm('Chuyển đường ghi phiếu sang API cho TOÀN HỆ THỐNG?
Nếu lời gọi API chưa khai báo, mọi lượt đẩy phiếu sẽ dừng (phiếu giữ mã BT).')) { return; }
        post('tgs_htsoft_api_save', { write_mode: v }).then(function (out) {
            $('htaWmMsg').textContent = (out && out.success) ? 'Đã lưu: ' + (v === 'api' ? 'API' : 'SQL') + '.' : 'Không lưu được.';
        });
    });

    $('htaPvGo').addEventListener('click', function () {
        $('htaPvMsg').textContent = 'Đang chạy…';
        post('tgs_htsoft_api_preview', { shop: $('htaPvShop').value, code: $('htaPvCode').value }).then(function (out) {
            if (!out || !out.success) {
                $('htaPvMsg').textContent = '';
                $('htaPvOut').textContent = (out && out.data && out.data.message) || 'Không xem trước được.';
                return;
            }
            var d = out.data;
            $('htaPvMsg').textContent = d.ok ? ((d.kind === 'return' ? 'Phiếu hoàn ' : 'Phiếu bán ') + d.code + ' — không gửi gì, sổ không đổi.') : '';
            $('htaPvOut').textContent = d.ok ? d.pretty : (d.message || 'Không có bước gửi nào.');
        }).catch(function () { $('htaPvOut').textContent = 'Không xem trước được.'; });
    });
})();
</script>
