/**
 * Nút "Đối soát HTsoft (Excel)" ở màn Quản lý phiếu xuất bán (VAT) của tgs-bc-tk.
 * Đọc file Excel xuất từ phần mềm HTsoft (cột: Số phiếu · Tổng nợ · Diễn giải · Ngày nhập) ngay trên
 * trình duyệt, ghép với phiếu bán BTsoft của các shop đang chọn rồi chỉ ra: một phiếu bị chuyển thành
 * HAI hoá đơn, lệch tiền, lệch ngày nhập, phiếu chưa có bên HTsoft, hoá đơn HTsoft không khớp phiếu nào.
 * Kết quả ghép lưu lên phiếu (cột "Số phiếu HTsoft" cuối bảng). BTsoft là gốc.
 */
(function ($) {
    'use strict';
    var CFG = window.tgsHtsoftApiRecon;
    if (!CFG || !$) { return; }

    $(function () {
        var $head = $('.bctk-result__head');
        if (!$head.length || $('#htaRcBtn').length) { return; }

        var LABEL = '🧾 Đối soát HTsoft (Excel)';
        var $btn = $('<button type="button" id="htaRcBtn" '
            + 'style="margin-left:10px;padding:6px 14px;border:1px solid #0f766e;border-radius:6px;'
            + 'background:#f0fdfa;color:#0f766e;font-weight:600;cursor:pointer;">' + LABEL + '</button>');
        $head.append($btn);

        var st = { ht: [], rows: [], filter: 'problem', q: '', fileName: '' };
        var esc = function (v) { return $('<div>').text(v == null ? '' : String(v)).html(); };
        var money = function (v) { return Math.round(Number(v || 0)).toLocaleString('vi-VN'); };
        var dmy = function (ymd) { return ymd ? ymd.slice(8, 10) + '/' + ymd.slice(5, 7) + '/' + ymd.slice(0, 4) : ''; };

        // Thứ tự = mức cần xem trước.
        var ST = {
            dup:    { lab: 'TRÙNG — 1 phiếu ra nhiều hoá đơn', cls: 'rc-dup' },
            amt:    { lab: 'Lệch tiền', cls: 'rc-amt' },
            date:   { lab: 'Lệch ngày nhập', cls: 'rc-date' },
            miss:   { lab: 'Chưa có bên HTsoft', cls: 'rc-miss' },
            htonly: { lab: 'Chỉ có ở HTsoft', cls: 'rc-ht' },
            ok:     { lab: 'Khớp', cls: 'rc-ok' }
        };
        var ORDER = ['dup', 'amt', 'date', 'miss', 'htonly', 'ok'];

        function post(action, data) {
            var fd = new FormData();
            fd.append('action', action);
            fd.append('nonce', CFG.nonce);
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

        // Shop đang chọn — khớp bộ lọc đang nhìn: ô MÃ KHO (giá trị "blogId::mã") tích kho nào thì chỉ lấy shop đó.
        function selectedBlogs() {
            var blogs = $('.bctk-site:checked').map(function () { return parseInt(this.value, 10); }).get();
            if ($('.bctk-zone').length) {
                var z = {};
                $('.bctk-zone:checked').each(function () { z[parseInt(String(this.value).split('::')[0], 10)] = 1; });
                if (Object.keys(z).length) {
                    var both = blogs.filter(function (b) { return z[b]; });
                    blogs = both.length ? both : Object.keys(z).map(Number);
                }
            }
            return blogs;
        }

        /* ───────────────────────── đọc file Excel HTsoft ───────────────────────── */

        function norm(s) {
            return String(s == null ? '' : s).toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '')
                .replace(/đ/g, 'd').replace(/\s+/g, ' ').trim();
        }

        function toYmd(v) {
            var p = function (n) { return (n < 10 ? '0' : '') + n; };
            if (v == null || v === '') { return ''; }
            if (v instanceof Date && !isNaN(v)) {
                var d = new Date(v.getTime() + 3600000);   // ô chỉ có ngày đôi khi lệch vài giây về hôm trước
                return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate());
            }
            if (typeof v === 'number' && window.XLSX && XLSX.SSF) {
                var o = XLSX.SSF.parse_date_code(v);
                return o ? o.y + '-' + p(o.m) + '-' + p(o.d) : '';
            }
            var s = String(v).trim(), m;
            if ((m = s.match(/^(\d{4})-(\d{1,2})-(\d{1,2})/))) { return m[1] + '-' + p(+m[2]) + '-' + p(+m[3]); }
            if ((m = s.match(/^(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{4})/))) { return m[3] + '-' + p(+m[2]) + '-' + p(+m[1]); }   // ngày/tháng/năm
            return '';
        }

        function toMoney(v) {
            if (typeof v === 'number') { return Math.round(v); }
            var s = String(v == null ? '' : v).replace(/\s/g, '');
            if (s === '') { return 0; }
            var neg = /^-|^\(.*\)$/.test(s);
            s = s.replace(/[.,]\d{1,2}$/, '').replace(/[^\d]/g, '');
            return (neg ? -1 : 1) * (parseInt(s, 10) || 0);
        }

        /** Mã phiếu BTsoft nằm trong diễn giải: BTAA00004166, BTZ00003368Z, BT.A00000002… (lấy mã đầu tiên). */
        function btOf(note) {
            var m = String(note || '').toUpperCase().match(/BT[A-Z.]{0,2}\d{5,}Z?/);
            return m ? m[0] : '';
        }

        function parseSheet(buf) {
            var wb = XLSX.read(buf, { type: 'array', cellDates: true });
            var best = null;
            wb.SheetNames.forEach(function (name) {
                if (best) { return; }
                var aoa = XLSX.utils.sheet_to_json(wb.Sheets[name], { header: 1, raw: true, defval: '' });
                for (var i = 0; i < Math.min(aoa.length, 40); i++) {
                    var h = (aoa[i] || []).map(norm);
                    var find = function (tests) {
                        for (var t = 0; t < tests.length; t++) {
                            for (var c = 0; c < h.length; c++) {
                                if (tests[t](h[c])) { return c; }
                            }
                        }
                        return -1;
                    };
                    var cCode = find([function (x) { return x === 'so phieu'; }, function (x) { return x.indexOf('so phieu') === 0; },
                        function (x) { return x.indexOf('so phieu') !== -1 || x === 'so ct' || x === 'ma phieu' || x === 'so chung tu'; }]);
                    var cNote = find([function (x) { return x.indexOf('dien giai') !== -1; }, function (x) { return x === 'ghi chu'; }]);
                    if (cCode === -1 || cNote === -1) { continue; }
                    best = {
                        sheet: name, aoa: aoa, row: i, cCode: cCode, cNote: cNote,
                        cTotal: find([function (x) { return x.indexOf('tong no') !== -1; }, function (x) { return x.indexOf('tong tien') !== -1; },
                            function (x) { return x.indexOf('thanh tien') !== -1; }]),
                        cDate: find([function (x) { return x.indexOf('ngay nhap') !== -1; }, function (x) { return x.indexOf('ngay lap') !== -1; },
                            function (x) { return x.indexOf('ngay') === 0; }]),
                        heads: aoa[i]
                    };
                    return;
                }
            });
            if (!best) {
                throw new Error('Không tìm thấy dòng tiêu đề có cột "Số phiếu" và "Diễn giải" trong file.');
            }
            // Gộp theo số phiếu: một hoá đơn có thể nằm trên nhiều dòng (chi tiết hàng).
            var map = {}, list = [];
            for (var r = best.row + 1; r < best.aoa.length; r++) {
                var row = best.aoa[r] || [];
                var code = String(row[best.cCode] == null ? '' : row[best.cCode]).trim().toUpperCase();
                if (code === '' || norm(code).indexOf('tong') === 0) { continue; }
                var note = String(row[best.cNote] == null ? '' : row[best.cNote]).trim();
                var total = best.cTotal === -1 ? 0 : toMoney(row[best.cTotal]);
                var date = best.cDate === -1 ? '' : toYmd(row[best.cDate]);
                var h = map[code];
                if (!h) {
                    h = map[code] = { code: code, note: note, total: total, date: date, lines: 0 };
                    list.push(h);
                }
                h.lines++;
                if (!h.note && note) { h.note = note; }
                if (!h.date && date) { h.date = date; }
                if (Math.abs(total) > Math.abs(h.total)) { h.total = total; }
            }
            list.forEach(function (h) { h.bt = btOf(h.note); });
            return { list: list, info: best };
        }

        /* ───────────────────────── ghép ───────────────────────── */

        function match(ht, local) {
            var byCode = {};
            local.forEach(function (L) {
                L.hs = [];
                var k = String(L.code).toUpperCase();
                (byCode[k] = byCode[k] || []).push(L);
            });
            var pick = function (cands, h) {
                if (!cands || !cands.length) { return null; }
                if (cands.length === 1) { return cands[0]; }
                // Mã BT trùng nhau giữa các shop: ưu tiên shop có mã là phần đầu của số phiếu HTsoft, rồi tới khớp tiền.
                var a = cands.filter(function (L) { return L.shop && h.code.indexOf(String(L.shop).toUpperCase()) === 0; });
                if (a.length === 1) { return a[0]; }
                var b = (a.length ? a : cands).filter(function (L) { return Math.abs(L.amt - h.total) < 1; });
                if (b.length >= 1) { return b[0]; }
                h.amb = 1;
                return (a.length ? a : cands)[0];
            };
            var out = [];
            ht.forEach(function (h) {
                h.amb = 0;
                var L = pick(byCode[h.code], h) || (h.bt ? pick(byCode[h.bt], h) : null);
                if (L) { L.hs.push(h); }
                else { out.push({ st: 'htonly', L: null, hs: [h] }); }
            });
            local.forEach(function (L) {
                if (!L.hs.length) {
                    if (L.in_range) { out.push({ st: 'miss', L: L, hs: [] }); }
                    return;
                }
                var sum = L.hs.reduce(function (s, h) { return s + h.total; }, 0);
                var flags = [];
                if (L.hs.length > 1) { flags.push('dup'); }
                if (Math.abs(L.hs[0].total - L.amt) >= 1) { flags.push('amt'); }
                if (L.hs.some(function (h) { return h.date && h.date !== String(L.at).slice(0, 10); })) { flags.push('date'); }
                out.push({ st: flags[0] || 'ok', flags: flags, L: L, hs: L.hs, sum: sum });
            });
            out.sort(function (a, b) {
                var d = ORDER.indexOf(a.st) - ORDER.indexOf(b.st);
                if (d) { return d; }
                var ka = a.L ? a.L.shop + a.L.at : 'zz' + a.hs[0].code, kb = b.L ? b.L.shop + b.L.at : 'zz' + b.hs[0].code;
                return ka < kb ? -1 : (ka > kb ? 1 : 0);
            });
            return out;
        }

        /* ───────────────────────── cửa sổ ───────────────────────── */

        function modal() {
            var $ov = $('#htaRcModal');
            if ($ov.length) { return $ov; }
            $ov = $(
                '<div id="htaRcModal" style="position:fixed;inset:0;z-index:100000;display:none;">'
                + '<style>'
                + '#htaRcModal table{width:100%;border-collapse:collapse;font-size:13px;}'
                + '#htaRcModal th{position:sticky;top:0;background:#f1f5f9;box-shadow:0 1px 0 #cbd5e1;padding:9px 10px;text-align:left;font-size:12px;color:#334155;z-index:1;white-space:nowrap;}'
                + '#htaRcModal td{padding:8px 10px;border-bottom:1px solid #e2e8f0;vertical-align:top;}'
                + '#htaRcModal tbody tr:hover td{background:#f8fafc;}'
                + '#htaRcModal .num{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap;}'
                + '#htaRcModal .mono{font-family:ui-monospace,Consolas,monospace;font-size:12.5px;white-space:nowrap;}'
                + '#htaRcModal .grp-bt{border-left:3px solid #2563eb;} #htaRcModal .grp-ht{border-left:3px solid #0f766e;}'
                + '#htaRcModal .tag{display:inline-block;padding:1px 9px;border-radius:99px;font-size:11.5px;font-weight:700;white-space:nowrap;}'
                + '#htaRcModal .rc-dup{background:#fee2e2;color:#991b1b;} #htaRcModal .rc-amt{background:#ffedd5;color:#9a3412;}'
                + '#htaRcModal .rc-date{background:#fef9c3;color:#854d0e;} #htaRcModal .rc-miss{background:#e0e7ff;color:#3730a3;}'
                + '#htaRcModal .rc-ht{background:#f1f5f9;color:#475569;} #htaRcModal .rc-ok{background:#dcfce7;color:#166534;}'
                + '#htaRcModal tr.r-dup td{background:#fff5f5;}'
                + '#htaRcModal .bad{color:#b91c1c;font-weight:700;}'
                + '#htaRcModal .sub{color:#64748b;font-size:11.5px;}'
                + '#htaRcModal .chip{display:inline-flex;align-items:center;gap:6px;margin:0 8px 6px 0;padding:6px 12px;border:1px solid #cbd5e1;border-radius:8px;background:#fff;cursor:pointer;font-size:12.5px;color:#334155;}'
                + '#htaRcModal .chip b{font-size:15px;} #htaRcModal .chip.on{border-color:#0f766e;background:#f0fdfa;box-shadow:inset 0 0 0 1px #0f766e;}'
                + '#htaRcModal input[type=date],#htaRcModal input[type=text]{padding:6px 8px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px;}'
                + '#htaRcModal .btn{padding:7px 16px;border-radius:6px;font-weight:600;cursor:pointer;border:1px solid #cbd5e1;background:#fff;color:#334155;}'
                + '#htaRcModal .btn-main{border-color:#0f766e;background:#0f766e;color:#fff;} #htaRcModal .btn:disabled{opacity:.5;cursor:not-allowed;}'
                + '</style>'
                + '<div style="position:absolute;inset:0;background:rgba(15,23,42,.45);"></div>'
                + '<div style="position:relative;margin:2vh auto;width:min(1680px,97vw);height:96vh;display:flex;flex-direction:column;background:#fff;border-radius:12px;box-shadow:0 20px 50px rgba(0,0,0,.25);">'
                + '<div style="padding:14px 20px;border-bottom:1px solid #e2e8f0;display:flex;align-items:center;gap:10px;">'
                + '<strong style="font-size:16px;">Đối soát phiếu bán BTsoft ↔ hoá đơn bán lẻ HTsoft</strong>'
                + '<span style="color:#64748b;font-size:12.5px;">BTsoft là gốc · file Excel xuất từ HTsoft</span>'
                + '<button type="button" id="htaRcX" style="margin-left:auto;border:0;background:none;font-size:22px;cursor:pointer;">×</button></div>'
                + '<div style="flex-shrink:0;padding:12px 20px;border-bottom:1px solid #e2e8f0;display:flex;flex-wrap:wrap;gap:12px;align-items:center;font-size:13px;color:#334155;">'
                + '<label>File Excel HTsoft <input type="file" id="htaRcFile" accept=".xlsx,.xls,.csv" style="margin-left:6px;"></label>'
                + '<label>Phiếu BTsoft từ <input type="date" id="htaRcFrom"></label><label>đến <input type="date" id="htaRcTo"></label>'
                + '<button type="button" class="btn btn-main" id="htaRcRun" disabled>Đối soát</button>'
                + '<span id="htaRcInfo" class="sub" style="flex:1;min-width:260px;">File cần các cột: <b>Số phiếu</b> · <b>Tổng nợ</b> · <b>Diễn giải</b> · <b>Ngày nhập</b>.</span>'
                + '</div>'
                + '<div id="htaRcChips" style="flex-shrink:0;padding:12px 20px 4px;display:none;"></div>'
                + '<div style="flex-shrink:0;padding:0 20px 8px;display:none;" id="htaRcSearchWrap"><input type="text" id="htaRcQ" placeholder="Tìm mã phiếu BTsoft / số phiếu HTsoft / diễn giải…" style="width:420px;max-width:100%;"></div>'
                + '<div id="htaRcMain" style="flex:1;min-height:0;overflow:auto;padding:0 20px;"><table><thead><tr>'
                + '<th>Kết quả</th>'
                + '<th class="grp-bt">Shop</th><th>Mã phiếu BTsoft</th><th>Ngày bán</th><th class="num">Thành tiền BTsoft</th><th>Mã SO</th>'
                + '<th class="grp-ht">Số phiếu HTsoft</th><th>Ngày nhập HTsoft</th><th class="num">Tổng nợ HTsoft</th><th class="num">Lệch tiền</th><th>Diễn giải HTsoft</th>'
                + '</tr></thead><tbody id="htaRcRows"><tr><td colspan="11" style="padding:26px;color:#64748b;">Chọn file Excel xuất từ HTsoft rồi bấm <b>Đối soát</b>.</td></tr></tbody></table></div>'
                + '<div style="flex-shrink:0;padding:12px 20px;border-top:1px solid #e2e8f0;display:flex;gap:8px;align-items:center;">'
                + '<span id="htaRcNote" class="sub" style="flex:1;">Cột xanh dương = BTsoft, cột xanh lục = HTsoft. Lưu để cột "Số phiếu HTsoft" hiện ở cuối bảng và đi theo khi xuất Excel.</span>'
                + '<button type="button" class="btn" id="htaRcXls" disabled>Xuất Excel kết quả</button>'
                + '<button type="button" class="btn btn-main" id="htaRcSave" disabled>Lưu số phiếu HTsoft vào phiếu</button>'
                + '</div></div></div>'
            );
            $('body').append($ov);
            $ov.find('#htaRcX').on('click', function () { $ov.hide(); });
            $ov.find('#htaRcFile').on('change', onFile);
            $ov.find('#htaRcRun').on('click', run);
            $ov.find('#htaRcSave').on('click', save);
            $ov.find('#htaRcXls').on('click', exportXls);
            $ov.on('click', '.chip', function () { st.filter = $(this).data('f'); draw(); });
            $ov.find('#htaRcQ').on('input', function () { st.q = norm(this.value); draw(); });
            return $ov;
        }

        function onFile() {
            var f = this.files && this.files[0];
            st.ht = [];
            $('#htaRcRun').prop('disabled', true);
            if (!f) { return; }
            if (!window.XLSX) { $('#htaRcInfo').html('<span class="bad">Chưa nạp được thư viện đọc Excel — tải lại trang rồi thử lại.</span>'); return; }
            var rd = new FileReader();
            rd.onload = function (e) {
                try {
                    var p = parseSheet(new Uint8Array(e.target.result));
                    st.ht = p.list;
                    st.fileName = f.name;
                    var i = p.info, col = function (c) { return c === -1 ? '<span class="bad">không thấy</span>' : '"' + esc(i.heads[c]) + '"'; };
                    var noBt = p.list.filter(function (h) { return !h.bt; }).length;
                    $('#htaRcInfo').html('Đọc được <b>' + p.list.length + '</b> hoá đơn HTsoft (sheet "' + esc(i.sheet) + '"). Cột: số phiếu ' + col(i.cCode)
                        + ' · tổng nợ ' + col(i.cTotal) + ' · diễn giải ' + col(i.cNote) + ' · ngày nhập ' + col(i.cDate)
                        + '. ' + noBt + ' hoá đơn không có mã BT trong diễn giải (ghép theo số phiếu).');
                    $('#htaRcRun').prop('disabled', !p.list.length);
                } catch (err) {
                    $('#htaRcInfo').html('<span class="bad">' + esc(err.message || 'Không đọc được file.') + '</span>');
                }
            };
            rd.readAsArrayBuffer(f);
        }

        function run() {
            var blogs = selectedBlogs();
            if (!blogs.length) { window.alert('Chưa chọn shop nào. Tích chi nhánh / mã kho ở bộ lọc bên trái rồi mở lại.'); return; }
            var from = $('#htaRcFrom').val(), to = $('#htaRcTo').val();
            if (!from || !to) { window.alert('Chọn khoảng ngày của phiếu BTsoft.'); return; }
            var codes = {};
            st.ht.forEach(function (h) { codes[h.code] = 1; if (h.bt) { codes[h.bt] = 1; } });
            var $b = $('#htaRcRun').prop('disabled', true).text('Đang đối soát…');
            post('tgs_htsoft_api_recon_local', { blogs: JSON.stringify(blogs), from: from, to: to, codes: JSON.stringify(Object.keys(codes)) })
                .then(function (d) {
                    st.rows = match(st.ht, d.rows || []);
                    st.shops = d.shops || [];
                    st.filter = 'problem';
                    $('#htaRcChips, #htaRcSearchWrap').show();
                    draw();
                    $('#htaRcXls').prop('disabled', !st.rows.length);
                })
                .catch(function (err) { window.alert(err.message || 'Không đối soát được.'); })
                .then(function () { $b.prop('disabled', false).text('Đối soát'); });
        }

        function visible() {
            return st.rows.filter(function (r) {
                if (st.filter === 'problem' ? r.st === 'ok' : (st.filter !== 'all' && r.st !== st.filter)) { return false; }
                if (!st.q) { return true; }
                var hay = norm((r.L ? r.L.code + ' ' + r.L.shop + ' ' + r.L.so : '') + ' ' + r.hs.map(function (h) { return h.code + ' ' + h.note; }).join(' '));
                return hay.indexOf(st.q) !== -1;
            });
        }

        function draw() {
            var cnt = {};
            st.rows.forEach(function (r) { cnt[r.st] = (cnt[r.st] || 0) + 1; });
            var prob = st.rows.length - (cnt.ok || 0);
            var chip = function (f, lab, n, cls) {
                return '<span class="chip' + (st.filter === f ? ' on' : '') + '" data-f="' + f + '">'
                    + (cls ? '<span class="tag ' + cls + '">' + esc(lab) + '</span>' : esc(lab)) + ' <b>' + n + '</b></span>';
            };
            $('#htaRcChips').html(
                chip('problem', 'Cần xem', prob) + ORDER.map(function (k) { return chip(k, ST[k].lab, cnt[k] || 0, ST[k].cls); }).join('')
                + chip('all', 'Tất cả', st.rows.length)
                + '<div class="sub" style="margin:2px 0 6px;">Shop: ' + esc((st.shops || []).map(function (s) { return s.shop + ' (' + s.n + ' phiếu)'; }).join(', '))
                + ' · file: ' + esc(st.fileName) + ' (' + st.ht.length + ' hoá đơn)</div>'
            );
            var rows = visible();
            $('#htaRcRows').html(rows.map(function (r) {
                var L = r.L, hs = r.hs, flags = r.flags || [];
                var tags = (r.st === 'ok' || !flags.length ? [r.st] : flags).map(function (f) { return '<span class="tag ' + ST[f].cls + '">' + esc(ST[f].lab) + '</span>'; }).join('<br>');
                var sub = '';
                if (r.st === 'miss') {
                    sub = /^BT/i.test(L.code)
                        ? (L.so ? 'Đã lên SO, chưa thấy hoá đơn' : 'Chưa đẩy lên SO')
                        : 'Mã thật nhưng không có trong file';
                }
                if (hs.some(function (h) { return h.amb; })) { sub = 'Mã BT trùng ở nhiều shop — tự chọn shop gần đúng nhất'; }
                var d0 = L ? String(L.at).slice(0, 10) : '';
                var diff = L && hs.length ? hs[0].total - L.amt : null;
                return '<tr class="' + (r.st === 'dup' ? 'r-dup' : '') + '">'
                    + '<td>' + tags + (sub ? '<div class="sub">' + esc(sub) + '</div>' : '') + '</td>'
                    + '<td class="grp-bt mono">' + esc(L ? L.shop : '') + '</td>'
                    + '<td class="mono">' + esc(L ? L.code : '') + (L && L.is_z ? '<div class="sub">phiếu Z của ' + esc(L.parent) + '</div>' : '') + '</td>'
                    + '<td class="mono">' + esc(L ? dmy(d0) + ' ' + String(L.at).slice(11, 16) : '') + '</td>'
                    + '<td class="num">' + (L ? money(L.amt) : '') + '</td>'
                    + '<td class="mono">' + esc(L ? L.so : '') + '</td>'
                    + '<td class="grp-ht mono">' + hs.map(function (h) { return '<div' + (hs.length > 1 ? ' class="bad"' : '') + '>' + esc(h.code) + '</div>'; }).join('') + '</td>'
                    + '<td class="mono">' + hs.map(function (h) { return '<div' + (L && h.date && h.date !== d0 ? ' class="bad"' : '') + '>' + esc(dmy(h.date)) + '</div>'; }).join('') + '</td>'
                    + '<td class="num">' + hs.map(function (h) { return '<div>' + money(h.total) + '</div>'; }).join('') + '</td>'
                    + '<td class="num">' + (diff === null || Math.abs(diff) < 1 ? '' : '<span class="bad">' + (diff > 0 ? '+' : '') + money(diff) + '</span>') + '</td>'
                    + '<td style="min-width:260px;">' + hs.map(function (h) { return '<div>' + esc(h.note) + '</div>'; }).join('') + '</td>'
                    + '</tr>';
            }).join('') || '<tr><td colspan="11" style="padding:26px;color:#64748b;">Không có dòng nào ở mục này.</td></tr>');
            var nSave = st.rows.filter(function (r) { return r.L && r.hs.length; }).length;
            $('#htaRcSave').prop('disabled', !nSave).text(nSave ? 'Lưu số phiếu HTsoft vào ' + nSave + ' phiếu' : 'Lưu số phiếu HTsoft vào phiếu');
            $('#htaRcNote').text('Đang hiện ' + rows.length + '/' + st.rows.length + ' dòng. Cột xanh dương = BTsoft, cột xanh lục = HTsoft. Lệch tiền = Tổng nợ HTsoft − Thành tiền BTsoft.');
        }

        function save() {
            var items = st.rows.filter(function (r) { return r.L && r.hs.length; }).map(function (r) {
                return {
                    blog: r.L.blog, id: r.L.id, code: r.hs.map(function (h) { return h.code; }).join(', '),
                    n: r.hs.length, total: r.sum, date: r.hs[0].date || ''
                };
            });
            if (!items.length) { return; }
            if (!window.confirm('Lưu số phiếu HTsoft vào ' + items.length + ' phiếu BTsoft?\n\nChỉ ghi thông tin đối chiếu (cột "Số phiếu HTsoft"). Không đổi mã phiếu, không sửa ghi chú, không gửi gì sang HTsoft.')) { return; }
            var $b = $('#htaRcSave').prop('disabled', true), total = items.length, done = 0;
            var step = function () {
                if (!items.length) {
                    window.alert('Đã lưu ' + done + '/' + total + ' phiếu.');
                    draw();
                    if ($('.bctk-site:checked').length) { $(document).trigger('bctk:search'); }
                    return;
                }
                var batch = items.splice(0, 200);
                $b.text('Đang lưu ' + done + '/' + total + '…');
                post('tgs_htsoft_api_recon_save', { items: JSON.stringify(batch) })
                    .then(function (d) { done += Number(d.saved || 0); step(); })
                    .catch(function (err) { window.alert((err.message || 'Không lưu được.') + ' Đã lưu ' + done + '/' + total + '.'); draw(); });
            };
            step();
        }

        function exportXls() {
            var aoa = [['Kết quả', 'Shop', 'Mã phiếu BTsoft', 'Ngày bán', 'Thành tiền BTsoft', 'Mã SO', 'Số phiếu HTsoft', 'Ngày nhập HTsoft', 'Tổng nợ HTsoft', 'Lệch tiền', 'Diễn giải HTsoft']];
            visible().forEach(function (r) {
                var L = r.L, hs = r.hs.length ? r.hs : [null];
                hs.forEach(function (h) {
                    aoa.push([
                        ((r.flags && r.flags.length) ? r.flags : [r.st]).map(function (f) { return ST[f].lab; }).join('; '),
                        L ? L.shop : '', L ? L.code : '', L ? dmy(String(L.at).slice(0, 10)) + ' ' + String(L.at).slice(11, 16) : '', L ? Math.round(L.amt) : '',
                        L ? L.so : '', h ? h.code : '', h ? dmy(h.date) : '', h ? h.total : '', (L && h) ? Math.round(h.total - L.amt) : '', h ? h.note : ''
                    ]);
                });
            });
            var ws = XLSX.utils.aoa_to_sheet(aoa);
            ws['!cols'] = [28, 9, 17, 17, 16, 15, 19, 14, 16, 12, 60].map(function (w) { return { wch: w }; });
            var wb = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(wb, ws, 'Doi soat');
            XLSX.writeFile(wb, 'doi-soat-htsoft-' + ($('#htaRcFrom').val() || '') + '_' + ($('#htaRcTo').val() || '') + '.xlsx');
        }

        if (CFG.test) { CFG.test({ parseSheet: parseSheet, match: match, btOf: btOf, toYmd: toYmd, toMoney: toMoney }); }   // chỉ dùng khi chạy thử ngoài trình duyệt

        $btn.on('click', function () {
            var $ov = modal();
            if (!$('#htaRcFrom').val()) { $('#htaRcFrom').val($('#bctkDateFrom').val() || ''); }
            if (!$('#htaRcTo').val()) { $('#htaRcTo').val($('#bctkDateTo').val() || ''); }
            $ov.show();
        });
    });
})(window.jQuery);
