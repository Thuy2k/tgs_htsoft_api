/**
 * Nút "Đẩy lên SO (API)" ở màn Quản lý phiếu xuất bán (VAT) của tgs-bc-tk.
 * Liệt kê phiếu bán chưa lên HTsoft → bỏ tích phiếu không đẩy → xem dữ liệu sẽ gửi → đẩy theo lô
 * qua API AddListOrder (không dùng SQL). Phiếu đã lên SO được đánh dấu; có nhật ký.
 */
(function ($) {
    'use strict';
    var CFG = window.tgsHtsoftApiSo;
    if (!CFG || !$) { return; }

    $(function () {
        var $head = $('.bctk-result__head');
        if (!$head.length || $('#htaSoBtn').length) { return; }

        // Màn phiếu điều chỉnh giảm: đẩy PHIẾU HOÀN (hàng bán trả lại) lên SO; màn phiếu xuất bán: đẩy phiếu bán.
        var RET = CFG.kind === 'return';
        var WHAT = RET ? 'phiếu hoàn (hàng bán trả lại)' : 'phiếu bán';
        var LABEL = RET ? '⬆ Đẩy phiếu hoàn BT lên SO (API)' : '⬆ Đẩy phiếu BT lên SO (API)';
        var $btn = $('<button type="button" id="htaSoBtn" '
            + 'style="margin-left:10px;padding:6px 14px;border:1px solid #7c3aed;border-radius:6px;'
            + 'background:#f5f3ff;color:#5b21b6;font-weight:600;cursor:pointer;">' + LABEL + '</button>');
        $head.append($btn);

        var st = { pass: '', items: [], running: false, stop: false };
        var esc = function (v) { return $('<div>').text(v == null ? '' : String(v)).html(); };
        var money = function (v) { return Number(v || 0).toLocaleString('vi-VN'); };

        function post(action, data) {
            var fd = new FormData();
            fd.append('action', action);
            fd.append('nonce', CFG.nonce);
            fd.append('password', st.pass);
            fd.append('kind', RET ? 'return' : 'sale');
            Object.keys(data || {}).forEach(function (k) { fd.append(k, data[k]); });
            return fetch(CFG.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (res) {
                    return res.text().then(function (text) {
                        try { return JSON.parse(text); }
                        catch (e) { throw new Error('Máy chủ trả lời không đọc được (mã ' + res.status + ').'); }
                    });
                })
                .then(function (out) {
                    if (!out || !out.success) { throw new Error((out && out.data && out.data.message) || 'Không thực hiện được.'); }
                    return out.data;
                });
        }

        function modal() {
            var $ov = $('#htaSoModal');
            if ($ov.length) { return $ov; }
            $ov = $(
                '<div id="htaSoModal" style="position:fixed;inset:0;z-index:100000;display:none;">'
                + '<style>'
                + '#htaSoModal table{width:100%;border-collapse:collapse;font-size:13px;}'
                + '#htaSoModal th{position:sticky;top:0;background:#f8fafc;box-shadow:0 1px 0 #e2e8f0;padding:7px 8px;text-align:left;font-size:12px;color:#475569;z-index:1;}'
                + '#htaSoModal td{padding:6px 8px;border-bottom:1px solid #eef2f7;vertical-align:top;}'
                + '#htaSoModal .st{display:inline-block;padding:0 8px;border-radius:99px;font-size:11.5px;font-weight:700;white-space:nowrap;}'
                + '#htaSoModal .st-wait{background:#f1f5f9;color:#64748b;}#htaSoModal .st-run{background:#dbeafe;color:#1e40af;}'
                + '#htaSoModal .st-ok{background:#dcfce7;color:#15803d;}#htaSoModal .st-fail{background:#fee2e2;color:#b91c1c;}'
                + '#htaSoModal .st-done{background:#ede9fe;color:#5b21b6;}'
                + '#htaSoModal .lnk{border:0;background:none;color:#2563eb;cursor:pointer;padding:0;font-size:12.5px;}'
                + '#htaSoModal pre{background:#0f172a;color:#e2e8f0;padding:10px;border-radius:8px;font-size:12px;max-height:34vh;overflow:auto;white-space:pre-wrap;word-break:break-word;margin:0;}'
                // Ô tích tự vẽ: ô mặc định bị giao diện làm mất dấu tích, không biết đã tích hay chưa.
                + '#htaSoModal input[type=checkbox]{-webkit-appearance:none!important;appearance:none!important;width:18px!important;height:18px!important;min-width:18px!important;margin:0!important;padding:0!important;border:2px solid #94a3b8!important;border-radius:4px!important;background:#fff!important;box-shadow:none!important;cursor:pointer;vertical-align:middle;position:relative;outline:0;}'
                + '#htaSoModal input[type=checkbox]:hover{border-color:#7c3aed!important;}'
                + '#htaSoModal input[type=checkbox]:checked{background:#7c3aed!important;border-color:#7c3aed!important;}'
                + '#htaSoModal input[type=checkbox]::before{content:none!important;}'
                + '#htaSoModal input[type=checkbox]:checked::after{content:""!important;position:absolute;left:4px;top:0;width:5px;height:10px;border:solid #fff;border-width:0 2.5px 2.5px 0;transform:rotate(45deg);}'
                + '#htaSoModal input[type=checkbox]:disabled{opacity:.45;cursor:not-allowed;}'
                + '#htaSoModal tr:has(input.c-on:checked) td{background:#faf5ff;}'
                + '</style>'
                + '<div style="position:absolute;inset:0;background:rgba(15,23,42,.45);"></div>'
                + '<div style="position:relative;margin:3vh auto;width:min(1200px,95vw);max-height:94vh;display:flex;flex-direction:column;background:#fff;border-radius:12px;box-shadow:0 20px 50px rgba(0,0,0,.25);">'
                + '<div style="padding:14px 18px;border-bottom:1px solid #e2e8f0;display:flex;align-items:center;gap:10px;">'
                + '<strong style="font-size:15px;">Đẩy ' + WHAT + ' chưa lên HTsoft lên HTsoft thành đơn đặt hàng (SO) — qua API</strong>'
                + '<span id="htaSoSum" style="color:#64748b;font-size:12.5px;"></span>'
                + '<button type="button" id="htaSoX" style="margin-left:auto;border:0;background:none;font-size:20px;cursor:pointer;">×</button></div>'
                + '<div id="htaSoShops" style="flex-shrink:0;padding:10px 18px;border-bottom:1px solid #e2e8f0;font-size:12.5px;color:#334155;max-height:16vh;overflow:auto;"></div>'
                + '<div style="padding:8px 18px;"><div style="height:8px;background:#e2e8f0;border-radius:99px;overflow:hidden;"><div id="htaSoBar" style="height:100%;width:0;background:#7c3aed;"></div></div></div>'
                + '<div id="htaSoMain" style="flex:1;min-height:0;overflow:auto;padding:0 18px;"><table><thead><tr>'
                + '<th><input type="checkbox" id="htaSoAll" title="Tích / bỏ tích tất cả phiếu chưa lên SO"></th>'
                + '<th>#</th><th>Shop</th><th>Mã bên mình</th><th>Mã SO sẽ tạo</th><th>Phiếu Z</th><th>' + (RET ? 'Ngày hoàn' : 'Ngày bán') + '</th>'
                + '<th style="text-align:right">Tiền</th><th>Trạng thái</th><th>Kết quả</th><th></th></tr></thead><tbody id="htaSoRows"></tbody></table></div>'
                + '<div id="htaSoView" style="display:none;padding:10px 18px;border-top:1px solid #e2e8f0;">'
                + '<div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;"><b id="htaSoViewTitle" style="font-size:13px;"></b>'
                + '<button type="button" class="lnk" id="htaSoViewX" style="margin-left:auto;">Đóng khung này</button></div>'
                + '<div id="htaSoViewWarn" style="color:#92400e;font-size:12.5px;margin-bottom:6px;"></div><pre id="htaSoViewPre"></pre></div>'
                + '<div style="padding:12px 18px;border-top:1px solid #e2e8f0;display:flex;gap:8px;align-items:center;">'
                + '<span id="htaSoNote" style="color:#64748b;font-size:12.5px;flex:1;"></span>'
                + '<button type="button" id="htaSoLog" style="padding:7px 14px;border:1px solid #cbd5e1;border-radius:6px;background:#fff;color:#334155;font-weight:600;cursor:pointer;">Nhật ký</button>'
                + '<button type="button" id="htaSoStop" style="display:none;padding:7px 16px;border:1px solid #b91c1c;border-radius:6px;background:#fff;color:#b91c1c;font-weight:600;cursor:pointer;">Dừng</button>'
                + '<button type="button" id="htaSoGo" style="padding:7px 18px;border:none;border-radius:6px;background:#7c3aed;color:#fff;font-weight:600;cursor:pointer;">Bắt đầu đẩy</button>'
                + '</div></div></div>'
            );
            $('body').append($ov);
            $ov.find('#htaSoX').on('click', function () {
                if (st.running && !window.confirm('Đang đẩy dở. Đóng cửa sổ sẽ DỪNG sau lô đang chạy. Đóng?')) { return; }
                st.stop = true;
                $ov.hide();
            });
            $ov.find('#htaSoStop').on('click', function () { st.stop = true; $(this).prop('disabled', true).text('Đang dừng…'); });
            $ov.find('#htaSoGo').on('click', run);
            $ov.find('#htaSoLog').on('click', showLog);
            $ov.find('#htaSoViewX').on('click', function () { $('#htaSoView').hide(); });
            $ov.on('change', '#htaSoAll', function () {
                var on = this.checked;
                st.items.forEach(function (it) { if (!it.done && it._st !== 'ok') { it._on = on; } });
                $('#htaSoRows .c-on').each(function () {
                    var it = st.items[$(this).closest('tr').data('i')];
                    if (it) { this.checked = !!it._on; }
                });
                count();
            });
            $ov.on('change', '.c-on', function () {
                var it = st.items[$(this).closest('tr').data('i')];
                if (it) { it._on = this.checked; }
                count();
            });
            // Công tắc "đẩy SO" riêng của từng shop — không liên quan công tắc đẩy HTsoft của quầy bán hàng.
            $ov.on('click', '.c-so-toggle', function () {
                if (st.running) { return; }
                var $b = $(this), on = Number($b.data('on')) === 1;
                if (on && !window.confirm('Bật ĐẨY SO cho shop này?\n\nChỉ cho phép đẩy phiếu BT của shop lên SO bằng nút này. Không thay đổi gì ở luồng bán hàng tại quầy.')) { return; }
                $b.prop('disabled', true).text('Đang lưu…');
                post('tgs_htsoft_api_so_toggle', { blog: $b.data('blog'), on: on ? 1 : 0 })
                    .then(load)
                    .catch(function (err) { window.alert(err.message || 'Không lưu được.'); $b.prop('disabled', false); });
            });
            $ov.on('click', '#htaSoOnAll', function () {
                var list = (st.off || []).slice();
                if (st.running || !list.length) { return; }
                if (!window.confirm('Bật ĐẨY SO cho ' + list.length + ' shop: ' + list.map(function (s) { return s.shop; }).join(', ')
                    + '?\n\nChỉ cho phép đẩy phiếu BT của các shop này lên SO bằng nút này. Không thay đổi gì ở luồng bán hàng tại quầy.')) { return; }
                var $b = $(this).prop('disabled', true).text('Đang bật…');
                list.reduce(function (p, sh) {
                    return p.then(function () { return post('tgs_htsoft_api_so_toggle', { blog: sh.blog, on: 1 }); });
                }, Promise.resolve())
                    .catch(function (err) { window.alert(err.message || 'Không bật được.'); })
                    .then(load)
                    .catch(function (err) { window.alert(err.message || 'Không quét lại được.'); $b.prop('disabled', false); });
            });
            $ov.on('click', '.c-view', function () {
                var it = st.items[$(this).closest('tr').data('i')];
                if (it) { view(it); }
            });
            return $ov;
        }

        function count() {
            var n = st.items.filter(function (x) { return x._on; }).length;
            if (!st.running) {
                $('#htaSoGo').prop('disabled', !n).text(n ? 'Bắt đầu đẩy ' + n + ' phiếu đã tích' : 'Chưa tích phiếu nào');
            }
            return n;
        }

        function stHtml(it) {
            var lab = { wait: 'Chưa đẩy', run: 'Đang đẩy…', ok: 'Đã lên SO', fail: 'Lỗi', done: 'Đã lên SO' }[it._st] || it._st;
            return '<span class="st st-' + it._st + '">' + lab + '</span>';
        }

        function setRow(it, s, msg) {
            it._st = s;
            if (msg !== undefined) { it._msg = msg; }
            var $tr = $('#htaSoRows tr[data-i="' + it._i + '"]');
            $tr.find('.c-st').html(stHtml(it));
            $tr.find('.c-msg').text(it._msg || '');
            if (s === 'ok') { it._on = false; it.done = 1; $tr.find('.c-on').prop('checked', false); }
            if (s === 'run' && $tr[0] && $tr[0].scrollIntoView) { $tr[0].scrollIntoView({ block: 'nearest' }); }
        }

        function render(data) {
            var $ov = modal();
            st.items = (data.items || []).map(function (it, i) {
                it._i = i;
                it._st = it.done ? 'done' : 'wait';
                it._on = !it.done;   // phiếu đã lên SO: mặc định KHÔNG tích
                it._msg = it.done ? ('Đã đẩy lúc ' + String(it.done_at || '').slice(0, 16))
                    : (it.last_err ? 'Lần trước lỗi: ' + it.last_err : '');
                return it;
            });
            var nDone = st.items.filter(function (x) { return x.done; }).length;
            $('#htaSoSum').text(st.items.length + ' phiếu chưa lên HTsoft · ' + nDone + ' đã lên SO · quét ' + data.days + ' ngày gần nhất, sau mốc khoá sổ');
            // Shop đang chọn nhưng chưa bật đẩy SO thì KHÔNG được liệt kê phiếu — báo rõ, kèm nút bật một lượt.
            st.off = (data.shops || []).filter(function (sh) { return !sh.so_on; });
            $('#htaSoShops').html(
                '<div style="margin-bottom:4px;color:#64748b;">API: <b>' + esc(data.api || '(chưa cấu hình)') + '</b></div>'
                + (st.off.length
                    ? '<div style="margin:4px 0 6px;padding:7px 10px;border:1px solid #fecaca;border-radius:8px;background:#fef2f2;color:#991b1b;">'
                        + '<b>' + st.off.length + ' shop đang chọn chưa bật đẩy SO nên chưa liệt kê phiếu:</b> '
                        + esc(st.off.map(function (sh) { return sh.shop; }).join(', '))
                        + ' <button type="button" id="htaSoOnAll" style="margin-left:8px;padding:3px 10px;border:1px solid #b91c1c;border-radius:6px;background:#fff;color:#b91c1c;font-weight:700;cursor:pointer;">'
                        + 'Bật đẩy SO cho cả ' + st.off.length + ' shop</button></div>'
                    : '')
                + ((data.shops || []).map(function (sh) {
                    return '<span style="display:inline-block;margin:2px 14px 2px 0;"><b>' + esc(sh.shop) + '</b>: '
                        + (sh.skip ? '<span style="color:#b91c1c">' + esc(sh.skip) + '</span>'
                            : (sh.n + ' phiếu, ' + sh.done + ' đã lên SO' + (sh.lock ? ' · khoá sổ đến ' + esc(sh.lock) : '')))
                        + ' <button type="button" class="lnk c-so-toggle" data-blog="' + sh.blog + '" data-on="' + (sh.so_on ? 0 : 1) + '">'
                        + (sh.so_on ? '[tắt đẩy SO]' : '[BẬT đẩy SO cho shop này]') + '</button>'
                        + '</span>';
                }).join('') || 'Không có shop nào hợp lệ.')
            );
            $('#htaSoRows').html(st.items.map(function (it) {
                return '<tr data-i="' + it._i + '">'
                    + '<td><input type="checkbox" class="c-on"' + (it._on ? ' checked' : '') + '></td>'
                    + '<td>' + (it._i + 1) + '</td><td>' + esc(it.shop) + '</td><td>' + esc(it.code) + '</td>'
                    + '<td>' + esc(it.so) + '</td><td>' + esc(it.z || '') + '</td>'
                    + '<td style="white-space:nowrap">' + esc(String(it.at || '').slice(0, 16)) + '</td>'
                    + '<td style="text-align:right">' + money(it.amt) + '</td>'
                    + '<td class="c-st">' + stHtml(it) + '</td><td class="c-msg">' + esc(it._msg) + '</td>'
                    + '<td><button type="button" class="lnk c-view">Xem</button></td></tr>';
            }).join('') || '<tr><td colspan="11" style="padding:18px;color:#64748b;">Không có ' + WHAT + ' mã BT nào trong các shop đã chọn.</td></tr>');
            $('#htaSoAll').prop('checked', st.items.some(function (x) { return x._on; }));
            $('#htaSoBar').css('width', '0');
            $('#htaSoView').hide();
            $('#htaSoNote').text((RET
                ? 'Chỉ phiếu HOÀN. Lên SO với mã kết thúc bằng R, lý do nhập trả hàng, ghi chú "TRA LAI" — bên HTsoft chuyển SO thành hàng bán trả lại.'
                : 'Chỉ phiếu BÁN (phiếu Z đi theo phiếu chính). Phiếu hoàn đẩy ở màn Quản lý phiếu điều chỉnh giảm.')
                + ' Bỏ tích phiếu không muốn đẩy; bấm "Xem" để coi dữ liệu sẽ gửi.');
            $('#htaSoGo').show();
            $('#htaSoStop').hide().prop('disabled', false).text('Dừng');
            count();
            $ov.show();
        }

        function view(it) {
            $('#htaSoView').show();
            $('#htaSoViewTitle').text('Dữ liệu sẽ gửi — ' + it.shop + ' · ' + it.code);
            $('#htaSoViewWarn').text('');
            $('#htaSoViewPre').text('Đang dựng…');
            post('tgs_htsoft_api_so_preview', { blog: it.blog, id: it.id }).then(function (d) {
                $('#htaSoViewWarn').text((d.warn || []).join(' · '));
                $('#htaSoViewPre').text(d.pretty || '');
            }).catch(function (err) { $('#htaSoViewPre').text(err.message || 'Không dựng được.'); });
        }

        function run() {
            if (st.running) { return; }
            var queue = st.items.filter(function (x) { return x._on; });
            if (!queue.length) { return; }
            var redo = queue.filter(function (x) { return x.done; }).length;
            if (redo && !window.confirm(redo + ' phiếu đang tích ĐÃ lên SO rồi. Đẩy lại sẽ ghi đè dòng hàng của đơn bên HTsoft. Tiếp tục?')) { return; }
            st.running = true; st.stop = false;
            $('#htaSoGo').hide(); $('#htaSoStop').show().prop('disabled', false).text('Dừng');
            $('#htaSoRows .c-on, #htaSoAll').prop('disabled', true);
            var done = 0, okN = 0, failN = 0, total = queue.length;
            var step = function () {
                if (st.stop || !queue.length) { return finish(); }
                var batch = queue.splice(0, CFG.batch || 10);
                batch.forEach(function (it) { setRow(it, 'run', ''); });
                post('tgs_htsoft_api_so_push', {
                    items: JSON.stringify(batch.map(function (it) { return { blog: it.blog, id: it.id }; }))
                }).then(function (data) {
                    (data.results || []).forEach(function (r, k) {
                        var it = batch[k];
                        if (!it) { return; }
                        done++;
                        var msg = r.message + ((r.warn && r.warn.length) ? ' — Lưu ý: ' + r.warn.join(' ') : '');
                        if (Number(r.ok) === 1) { okN++; setRow(it, 'ok', msg); }
                        else { failN++; setRow(it, 'fail', msg); }
                    });
                }).catch(function (err) {
                    batch.forEach(function (it) { done++; failN++; setRow(it, 'fail', err.message || 'Lỗi kết nối.'); });
                }).then(function () {
                    $('#htaSoBar').css('width', Math.round(done * 100 / total) + '%');
                    $('#htaSoNote').text('Đã chạy ' + done + '/' + total + ' · lên SO ' + okN + ' · lỗi ' + failN);
                    window.setTimeout(step, 1500);
                });
            };
            var finish = function () {
                st.running = false;
                $('#htaSoStop').hide();
                $('#htaSoRows .c-on, #htaSoAll').prop('disabled', false);
                $('#htaSoNote').text((st.stop ? 'ĐÃ DỪNG. ' : 'XONG. ') + 'Lên SO ' + okN + ' · lỗi ' + failN
                    + '. Phiếu lỗi vẫn đang tích — sửa xong bấm đẩy lại. Xem lại ở nút "Nhật ký".');
                $('#htaSoGo').show();
                count();
            };
            step();
        }

        function showLog() {
            $('#htaSoView').show();
            $('#htaSoViewTitle').text('Nhật ký đẩy SO (mới nhất trước)');
            $('#htaSoViewWarn').text('');
            $('#htaSoViewPre').text('Đang tải…');
            post('tgs_htsoft_api_so_log', { limit: 300 }).then(function (d) {
                var rows = d.rows || [];
                $('#htaSoViewWarn').text('Giữ ' + d.keep_days + ' ngày. ' + rows.length + ' dòng gần nhất.');
                $('#htaSoViewPre').text(rows.map(function (r) {
                    return r.t + ' | ' + (r.ok ? 'OK ' : 'LỖI') + ' | ' + (r.shop || '') + ' | ' + (r.bt || '') + ' → ' + (r.so || '')
                        + (r.so_z ? ' + ' + r.so_z : '') + ' | ' + money(r.amt) + 'đ | ' + (r.by || '') + ' | ' + (r.msg || '');
                }).join('\n') || 'Chưa có dòng nào.');
            }).catch(function (err) { $('#htaSoViewPre').text(err.message || 'Không tải được nhật ký.'); });
        }

        $btn.on('click', function () {
            if (!CFG.ready) {
                window.alert('Chưa cấu hình địa chỉ API HTsoft.\nVào Quản trị → Công cụ đối chiếu → Kiểm tra API HTsoft để điền.');
                return;
            }
            var blogs = $('.bctk-site:checked').map(function () { return parseInt(this.value, 10); }).get();
            // Khớp đúng bộ lọc đang nhìn: ô MÃ KHO (giá trị "blogId::mã") đang tích kho nào thì chỉ quét shop đó.
            if ($('.bctk-zone').length) {
                var zoneBlogs = {};
                $('.bctk-zone:checked').each(function () { zoneBlogs[parseInt(String(this.value).split('::')[0], 10)] = 1; });
                if (Object.keys(zoneBlogs).length) {
                    var both = blogs.filter(function (b) { return zoneBlogs[b]; });
                    blogs = both.length ? both : Object.keys(zoneBlogs).map(Number);
                }
            }
            var pass = window.prompt(
                'ĐẨY ' + WHAT.toUpperCase() + ' CÒN MÃ BT LÊN HTSOFT THÀNH ĐƠN ĐẶT HÀNG (SO) — QUA API\n\n'
                + 'Quét ' + (blogs.length ? blogs.length + ' chi nhánh đang tích' : 'TẤT CẢ chi nhánh áp dụng thuế')
                + ': ' + WHAT + ' chưa lên HTsoft, tạo sau mốc khoá sổ.\n'
                + 'Bước này mới chỉ LIỆT KÊ để bạn xem và bỏ tích — chưa gửi gì.\n\nNhập mật khẩu:'
            );
            if (pass === null) { return; }
            if (!pass) { window.alert('Chưa nhập mật khẩu.'); return; }
            st.pass = pass;
            st.blogs = blogs;
            $btn.prop('disabled', true).text('Đang quét phiếu BT…');
            load()
                .catch(function (err) { window.alert(err.message || 'Không quét được.'); })
                .then(function () { $btn.prop('disabled', false).text(LABEL); });
        });

        // Quét lại danh sách phiếu của các shop đang chọn (dùng khi mở cửa sổ và sau khi bật / tắt đẩy SO).
        function load() {
            return post('tgs_htsoft_api_so_list', { blogs: JSON.stringify(st.blogs || []) }).then(render);
        }
    });
})(window.jQuery);
