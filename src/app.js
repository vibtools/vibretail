(() => {
    'use strict';

    const config = window.POS_CONFIG || {};
    const state = {
        bootstrap: null,
        settings: { currency: config.currency || 'BDT', business_name: config.businessName || 'VibRetail' },
        transactionItems: [],
        quotationItems: [],
        editProduct: null,
        currentPage: 'dashboard',
        pendingWrites: new Map()
    };

    const $ = (selector, root = document) => root.querySelector(selector);
    const $$ = (selector, root = document) => [...root.querySelectorAll(selector)];
    const esc = (value) => String(value ?? '').replace(/[&<>"]/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[char]);
    const num = (value) => Number.parseFloat(value || 0) || 0;
    const today = () => new Date().toISOString().slice(0, 10);
    const formatDate = (value) => value ? new Intl.DateTimeFormat('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }).format(new Date(`${value}T00:00:00`)) : '-';
    const formatMoney = (value) => `${state.settings.currency || 'BDT'} ${num(value).toLocaleString('en-BD', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
    const content = () => $('#content');
    const can = (permission) => { const permissions = state.bootstrap?.permissions || []; return permissions.includes('all') || permissions.includes(permission); };

    async function api(action, options = {}) {
        const params = new URLSearchParams(options.params || {});
        params.set('action', action);
        const request = { method: options.method || (options.body ? 'POST' : 'GET'), headers: { Accept: 'application/json' } };
        let pendingKey = '';
        if (options.body) {
            request.headers['Content-Type'] = 'application/json';
            request.headers['X-CSRF-Token'] = config.csrf;
            request.body = JSON.stringify(options.body);
            // Suppress concurrent duplicate submissions (double-click / repeated event firing)
            // without changing legitimate sequential business operations.
            pendingKey = `${action}:${request.body}`;
            if (state.pendingWrites.has(pendingKey)) return state.pendingWrites.get(pendingKey);
        }
        const execute = async () => {
            const response = await fetch(`${config.api}?${params}`, request);
            let result;
            try {
                result = await response.json();
            } catch {
                throw new Error('The server returned an invalid response.');
            }
            if (response.status === 401) {
                location.reload();
                throw new Error(result.message || 'Session expired.');
            }
            if (response.status === 428) {
                location.href = 'profile.php?password=required';
                throw new Error(result.message || 'Password change required.');
            }
            if (response.status === 503 && result?.error?.code === 'UPGRADE_REQUIRED') {
                location.href = result.upgrade_url || 'install';
                throw new Error(result.message || 'Database upgrade required.');
            }
            if (!response.ok || result.ok === false) {
                const error = new Error(result.message || 'The request could not be completed.');
                error.code = result?.error?.code || 'REQUEST_FAILED';
                error.requestId = result?.request_id || response.headers.get('X-Request-ID') || '';
                throw error;
            }
            return result;
        };
        const promise = execute();
        if (!pendingKey) return promise;
        state.pendingWrites.set(pendingKey, promise);
        try { return await promise; } finally { state.pendingWrites.delete(pendingKey); }
    }

    function apiErrorMessage(error) {
        const message = error?.message || 'The request could not be completed.';
        const code = error?.code && error.code !== 'REQUEST_FAILED' ? ` [${error.code}]` : '';
        const request = error?.requestId ? ` Request: ${error.requestId}` : '';
        return `${message}${code}${request}`;
    }

    function toast(message, type = 'success') {
        const node = document.createElement('div');
        node.className = `toast ${type === 'error' ? 'error' : ''}`;
        node.textContent = message;
        $('#toast-root').append(node);
        setTimeout(() => node.remove(), 3600);
    }

    function loading() {
        content().innerHTML = '<div class="loading-state"><span class="loader"></span><p>Loading data...</p></div>';
    }

    function showError(error) {
        const detail = apiErrorMessage(error);
        content().innerHTML = `<div class="panel empty-state page-enter"><span>!</span><h3>Could not load this page</h3><p>${esc(detail)}</p><button class="button button-secondary" id="retry-page">Try again</button></div>`;
        $('#retry-page')?.addEventListener('click', () => navigate(state.currentPage, false));
        toast(detail, 'error');
    }

    function pageHeader(title, subtitle, section = 'ERP', actions = '') {
        return `<header class="page-header"><div><span class="breadcrumb">${esc(section)}</span><h1>${esc(title)}</h1></div><div class="header-actions">${actions}</div></header>`;
    }

    function badge(status) {
        const value = String(status || 'active');
        let color = 'gray';
        if (/complete|delivered|paid|accepted|present|active/i.test(value)) color = '';
        if (/working|sent|draft|late|received/i.test(value)) color = 'blue';
        if (/due|overdue|cancel|reject|absent|return/i.test(value)) color = 'red';
        if (/ready|leave/i.test(value)) color = 'gold';
        return `<span class="badge ${color}">${esc(value)}</span>`;
    }

    function emptyRows(columns, text = 'No records found') {
        return `<tr class="empty-row"><td colspan="${columns}">${esc(text)}</td></tr>`;
    }

    function optionRows(items, label = 'name', selected = '', placeholder = 'Select') {
        return `<option value="">${esc(placeholder)}</option>${(items || []).map((item) => `<option value="${esc(item.id)}" ${String(item.id) === String(selected) ? 'selected' : ''}>${esc(item[label])}</option>`).join('')}`;
    }

    function serializeForm(form) {
        const result = Object.fromEntries(new FormData(form).entries());
        $$('input[type="checkbox"]', form).forEach((input) => { result[input.name] = input.checked ? 1 : 0; });
        return result;
    }

    function fileToDataUrl(input) {
        const file = input?.files?.[0];
        if (!file) return Promise.resolve('');
        if (!/^image\/(png|jpeg|webp|bmp)$/.test(file.type) || file.size > 3 * 1024 * 1024) {
            return Promise.reject(new Error('Choose a PNG, JPG, WEBP or BMP image up to 3 MB.'));
        }
        return new Promise((resolve, reject) => { const reader = new FileReader(); reader.onload = () => resolve(String(reader.result)); reader.onerror = () => reject(new Error('Could not read the selected image.')); reader.readAsDataURL(file); });
    }

    const paginationRegistry = new WeakMap();
    const PAGINATION_DEFAULT = 20;

    function tableRows(table) {
        return $$('tbody tr', table).filter((row) => !row.classList.contains('empty-row'));
    }

    function refreshTablePagination(table, resetPage = false) {
        const pager = paginationRegistry.get(table);
        if (!pager) return;
        const rows = tableRows(table);
        const matched = rows.filter((row) => row.dataset.searchMatch !== '0');
        if (resetPage) pager.page = 1;
        const pageSize = pager.pageSize;
        const pages = Math.max(1, Math.ceil(matched.length / pageSize));
        pager.page = Math.min(pager.page, pages);
        const start = (pager.page - 1) * pageSize;
        const end = Math.min(matched.length, start + pageSize);
        rows.forEach((row) => { row.hidden = true; });
        matched.slice(start, end).forEach((row) => { row.hidden = false; });
        pager.node.hidden = matched.length <= pageSize;
        pager.range.textContent = matched.length ? `${start + 1}–${end} of ${matched.length}` : '0 records';
        pager.prev.disabled = pager.page <= 1;
        pager.next.disabled = pager.page >= pages;
        pager.pageLabel.textContent = `${pager.page} / ${pages}`;
    }

    function enhanceTablePagination(table) {
        if (!table || table.dataset.pagination === 'off' || paginationRegistry.has(table)) return;
        const rows = tableRows(table);
        rows.forEach((row) => { if (!row.dataset.searchMatch) row.dataset.searchMatch = '1'; });
        const node = document.createElement('div');
        node.className = 'data-pager';
        node.innerHTML = `<span class="pager-range"></span><div class="pager-controls"><label>Rows <select class="pager-size"><option>10</option><option selected>20</option><option>50</option></select></label><button class="pager-button pager-prev" type="button" aria-label="Previous page">‹</button><span class="pager-page"></span><button class="pager-button pager-next" type="button" aria-label="Next page">›</button></div>`;
        const panel = table.closest('.table-panel');
        const wrap = table.closest('.table-wrap');
        (panel || wrap?.parentElement || table.parentElement).append(node);
        const pager = { node, page: 1, pageSize: PAGINATION_DEFAULT, range: $('.pager-range', node), pageLabel: $('.pager-page', node), prev: $('.pager-prev', node), next: $('.pager-next', node), size: $('.pager-size', node) };
        paginationRegistry.set(table, pager);
        pager.prev.addEventListener('click', () => { pager.page--; refreshTablePagination(table); });
        pager.next.addEventListener('click', () => { pager.page++; refreshTablePagination(table); });
        pager.size.addEventListener('change', () => { pager.pageSize = Number(pager.size.value) || PAGINATION_DEFAULT; refreshTablePagination(table, true); });
        refreshTablePagination(table);
    }

    function enhanceActivityPagination(list) {
        if (!list || list.dataset.paginationReady) return;
        const items = $$('.activity-item', list);
        list.dataset.paginationReady = '1';
        if (items.length <= 10) return;
        let page = 1; const pageSize = 10; const pages = Math.ceil(items.length / pageSize);
        const pager = document.createElement('div'); pager.className = 'data-pager activity-pager';
        pager.innerHTML = `<span class="pager-range"></span><div class="pager-controls"><button class="pager-button pager-prev" type="button">‹</button><span class="pager-page"></span><button class="pager-button pager-next" type="button">›</button></div>`;
        list.after(pager);
        const render = () => { const a=(page-1)*pageSize,b=Math.min(items.length,a+pageSize); items.forEach((item,i)=>{item.hidden=i<a||i>=b;}); $('.pager-range',pager).textContent=`${a+1}–${b} of ${items.length}`; $('.pager-page',pager).textContent=`${page} / ${pages}`; $('.pager-prev',pager).disabled=page===1; $('.pager-next',pager).disabled=page===pages; };
        $('.pager-prev',pager).addEventListener('click',()=>{page=Math.max(1,page-1);render();}); $('.pager-next',pager).addEventListener('click',()=>{page=Math.min(pages,page+1);render();}); render();
    }

    function finalizePageUi(root = document) {
        $$('.panel-title p, .table-toolbar > div > p, .page-header p', root).forEach((node) => node.remove());
        $$('.data-table', root).forEach(enhanceTablePagination);
        $$('.activity-list', root).forEach(enhanceActivityPagination);
    }

    let uiFinalizeQueued = false;
    function scheduleFinalizePageUi() {
        if (uiFinalizeQueued) return;
        uiFinalizeQueued = true;
        requestAnimationFrame(() => {
            uiFinalizeQueued = false;
            finalizePageUi(content());
            finalizePageUi($('#modal-root'));
        });
    }

    function bindSearch(inputSelector, tableSelector) {
        const input = $(inputSelector);
        const table = $(tableSelector);
        if (!input || !table) return;
        enhanceTablePagination(table);
        input.addEventListener('input', () => {
            const query = input.value.trim().toLowerCase();
            tableRows(table).forEach((row) => { row.dataset.searchMatch = (!query || row.textContent.toLowerCase().includes(query)) ? '1' : '0'; });
            refreshTablePagination(table, true);
        });
    }

    function modal(title, body, actions = '', large = false) {
        $('#modal-root').innerHTML = `<div class="modal-backdrop"><section class="modal ${large ? 'large' : ''}" role="dialog" aria-modal="true"><header class="modal-head"><h2>${esc(title)}</h2><button class="modal-close" aria-label="Close">&times;</button></header><div class="modal-body">${body}</div>${actions ? `<footer class="modal-actions">${actions}</footer>` : ''}</section></div>`;
        const close = () => { $('#modal-root').innerHTML = ''; };
        $('.modal-close')?.addEventListener('click', close);
        $('.modal-backdrop')?.addEventListener('click', (event) => { if (event.target.classList.contains('modal-backdrop')) close(); });
        finalizePageUi($('#modal-root'));
        return close;
    }

    async function refreshBootstrap() {
        const data = await api('bootstrap');
        state.bootstrap = data;
        state.settings = data.settings || state.settings;
        if (state.settings.business_name === 'Cloud Core POS') state.settings.business_name = 'VibRetail';
        const business = $('#sidebar-business');
        if (business) business.textContent = state.settings.business_name;
        return data;
    }

    function quickActions() {
        const actions = [
            ['NS', 'New Sale', 'sale-new'], ['NP', 'New Purchase', 'purchase-new'], ['PL', 'Product List', 'product-list'],
            ['CU', 'Customer', 'customer'], ['SU', 'Supplier', 'supplier'], ['SL', 'Sales List', 'sale-list'], ['PU', 'Purchase List', 'purchase-list'],
            ['PY', 'Payment', 'payment-center'], ['SM', 'Buy SMS', 'buy-sms']
        ];
        return `<div class="quick-actions">${actions.map(([icon, label, page]) => `<button class="quick-action" data-page="${page}"><span>${icon}</span>${label}</button>`).join('')}</div>`;
    }

    function trendSvg(points) {
        if (!points.length) return '';
        const width = 720, height = 180, pad = 20;
        const max = Math.max(1, ...points.flatMap((p) => [num(p.sales), num(p.purchases), num(p.expenses)]));
        const line = (key) => points.map((p, index) => {
            const x = pad + (index * (width - pad * 2) / Math.max(1, points.length - 1));
            const y = height - pad - (num(p[key]) / max * (height - pad * 2));
            return `${index ? 'L' : 'M'}${x.toFixed(1)},${y.toFixed(1)}`;
        }).join(' ');
        return `<svg viewBox="0 0 ${width} ${height}" preserveAspectRatio="none" aria-label="Last 30 days trend"><defs><linearGradient id="area" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#10B981" stop-opacity=".16"/><stop offset="1" stop-color="#10B981" stop-opacity="0"/></linearGradient></defs><path d="${line('sales')} L${width - pad},${height - pad} L${pad},${height - pad} Z" fill="url(#area)"/><path d="${line('sales')}" fill="none" stroke="#10B981" stroke-width="2"/><path d="${line('purchases')}" fill="none" stroke="#3B82F6" stroke-width="2"/><path d="${line('expenses')}" fill="none" stroke="#EF4444" stroke-width="2"/></svg>`;
    }

    async function renderDashboard(period = 'today') {
        loading();
        const data = await api('dashboard', { params: { period } });
        const sales = num(data.sales.total), purchases = num(data.purchases.total), expenses = num(data.expenses);
        const cashNet = num(data.cash_in) - num(data.cash_out);
        const trendSales = data.trend.reduce((sum, item) => sum + num(item.sales), 0);
        const trendPurchases = data.trend.reduce((sum, item) => sum + num(item.purchases), 0);
        const trendExpenses = data.trend.reduce((sum, item) => sum + num(item.expenses), 0);
        const maxFinancial = Math.max(1, sales, purchases, expenses);
        content().innerHTML = `<div class="page-enter dashboard-advanced">
            <div class="dashboard-commandbar">${quickActions()}<div class="dashboard-period"><select id="dashboard-period"><option value="today">Today</option><option value="last_30">Last 30 Days</option><option value="week">This Week</option><option value="last_week">Last Week</option><option value="month">This Month</option><option value="last_month">Last Month</option><option value="year">This Year</option></select><span>${formatDate(data.period.from)} – ${formatDate(data.period.to)}</span></div></div>
            <section class="dashboard-kpi-grid">
                <article class="kpi-card sales"><span>Sales</span><strong>${formatMoney(sales)}</strong><small>${esc(data.sales.count)} invoices · Due ${formatMoney(data.sales.due)}</small></article>
                <article class="kpi-card purchase"><span>Purchases</span><strong>${formatMoney(purchases)}</strong><small>${esc(data.purchases.count)} invoices · Due ${formatMoney(data.purchases.due)}</small></article>
                <article class="kpi-card cashflow"><span>Cash Net</span><strong class="${cashNet < 0 ? 'negative' : 'positive'}">${formatMoney(cashNet)}</strong><small>In ${formatMoney(data.cash_in)} · Out ${formatMoney(data.cash_out)}</small></article>
                <article class="kpi-card expense"><span>Expenses</span><strong>${formatMoney(expenses)}</strong><small>Selected period</small></article>
                <article class="kpi-card account"><span>Account Balance</span><strong>${formatMoney(data.account_balance)}</strong><small>Service paid ${formatMoney(data.service_paid)}</small></article>
                <article class="kpi-card low-stock"><span>Low Stock</span><strong class="${data.low_stock ? 'negative' : ''}">${esc(data.low_stock)}</strong><small>Products at alert level</small></article>
            </section>
            <section class="dashboard-primary-grid">
                <article class="panel dashboard-trend-panel"><div class="compact-panel-head"><h2>30-Day Business Trend</h2><div class="trend-legend"><span>Sales ${formatMoney(trendSales)}</span><span>Purchase ${formatMoney(trendPurchases)}</span><span>Expense ${formatMoney(trendExpenses)}</span></div></div><div class="trend-chart">${trendSvg(data.trend)}</div></article>
                <article class="panel dashboard-cash-panel"><div class="compact-panel-head"><h2>Cash Position</h2></div><dl class="dashboard-ledger"><div><dt>Cash received</dt><dd>${formatMoney(data.cash_in)}</dd></div><div><dt>Cash paid</dt><dd>${formatMoney(data.cash_out)}</dd></div><div><dt>Net cash</dt><dd>${formatMoney(cashNet)}</dd></div><div><dt>Account balance</dt><dd>${formatMoney(data.account_balance)}</dd></div></dl></article>
            </section>
            <section class="dashboard-secondary-grid">
                <article class="panel dashboard-distribution"><div class="compact-panel-head"><h2>Financial Distribution</h2></div><div class="financial-bars"><div class="financial-bar"><label><span>Sales</span><strong>${formatMoney(sales)}</strong></label><div class="bar-track"><i style="width:${sales/maxFinancial*100}%"></i></div></div><div class="financial-bar purchase"><label><span>Purchases</span><strong>${formatMoney(purchases)}</strong></label><div class="bar-track"><i style="width:${purchases/maxFinancial*100}%"></i></div></div><div class="financial-bar expense"><label><span>Expenses</span><strong>${formatMoney(expenses)}</strong></label><div class="bar-track"><i style="width:${expenses/maxFinancial*100}%"></i></div></div></div></article>
                <article class="panel table-panel dashboard-latest"><div class="table-toolbar"><div><h2>Recent Sales</h2></div><button class="button button-secondary" data-page="sale-list">View All</button></div><div class="table-wrap"><table class="data-table" data-pagination="off"><thead><tr><th>Customer</th><th>Invoice</th><th>Date</th><th>Total</th><th>Due</th><th>Status</th></tr></thead><tbody>${data.latest.length ? data.latest.map((row)=>`<tr><td><strong>${esc(row.customer)}</strong></td><td>${esc(row.invoice_no)}</td><td>${formatDate(row.sale_date)}</td><td>${formatMoney(row.total)}</td><td class="${num(row.due)?'negative':''}">${formatMoney(row.due)}</td><td>${badge(num(row.due)?'Due':'Paid')}</td></tr>`).join('') : emptyRows(6,'No sales yet.')}</tbody></table></div></article>
            </section>
        </div>`;
        $('#dashboard-period').value = period;
        $('#dashboard-period').addEventListener('change', (event) => renderDashboard(event.target.value).catch(showError));
    }

    async function renderContacts(type) {
        loading();
        const result = await api('contacts', { params: { type } });
        const title = type === 'supplier' ? 'Supplier' : 'Customer';
        content().innerHTML = `<div class="page-enter">${pageHeader(`${title} Management`, `Add and manage every ${title.toLowerCase()} account.`, 'Customer & Supplier')}
            <div class="two-column"><section class="panel form-panel"><h2 class="section-title">Add ${title}</h2><form id="contact-form"><input type="hidden" name="id"><input type="hidden" name="type" value="${type}"><div class="form-grid">
                <div class="form-field"><label>${title} Name <span class="required">*</span></label><input name="name" required placeholder="${title} name"></div><div class="form-field"><label>Mobile <span class="required">*</span></label><input name="mobile" required placeholder="01XXXXXXXXX"></div>
                <div class="form-field"><label>Email</label><input name="email" type="email" placeholder="Email address"></div><div class="form-field"><label>Contact Person</label><input name="contact_person" placeholder="Contact person"></div>
                <div class="form-field full"><label>Address</label><input name="address" placeholder="Address"></div><div class="form-field"><label>Contact Type</label><select name="type"><option value="customer" ${type === 'customer' ? 'selected' : ''}>Customer</option><option value="supplier" ${type === 'supplier' ? 'selected' : ''}>Supplier</option><option value="both">Both</option></select></div><div class="form-field"><label>Previous Balance</label><input name="opening_balance" type="number" min="0" step="0.01" value="0"></div>
                </div><div class="form-actions"><button class="button button-secondary" type="reset">Clear</button><button class="button button-primary" type="submit">Save ${title}</button></div></form></section>
                <aside class="panel panel-pad"><div class="panel-title"><span>${type === 'supplier' ? 'SU' : 'CU'}</span><div><h3>${title} Summary</h3><p>Live database totals</p></div></div><div class="record-summary"><div class="summary-item"><span>Total ${title}s</span><strong>${result.data.length}</strong></div><div class="summary-item"><span>Total Balance</span><strong>${formatMoney(result.data.reduce((sum, item) => sum + num(item.balance), 0))}</strong></div><div class="summary-item"><span>Added This Month</span><strong>${result.data.filter((item) => String(item.created_at).startsWith(today().slice(0, 7))).length}</strong></div></div></aside></div>
            <section class="panel table-panel" style="margin-top:22px"><div class="table-toolbar"><div><h2>${title} List</h2><p>Showing ${result.data.length} records</p></div><div class="toolbar-tools"><input id="contact-search" class="table-search" placeholder="Search ${title.toLowerCase()}..."></div></div><div class="table-wrap"><table id="contact-table" class="data-table"><thead><tr><th>#</th><th>Name</th><th>Address</th><th>Mobile</th><th>Balance</th><th>Date</th><th>Edit</th><th>Payment</th><th>Ledger</th></tr></thead><tbody>${result.data.length ? result.data.map((row, index) => `<tr><td>${index + 1}</td><td><strong>${esc(row.name)}</strong><br><small>${esc(row.email || '')}</small></td><td>${esc(row.address || '-')}</td><td>${esc(row.mobile)}</td><td class="${num(row.balance) ? 'negative' : ''}">${formatMoney(row.balance)}</td><td>${formatDate(String(row.created_at).slice(0, 10))}</td><td><button class="row-button edit-contact" data-id="${row.id}">Edit</button></td><td><button class="row-button contact-payment" data-id="${row.id}" data-type="${type}">${type==='supplier'?'Pay':'Receive'}</button></td><td><button class="row-button contact-ledger" data-id="${row.id}">Ledger</button></td></tr>`).join('') : emptyRows(9, `No ${title.toLowerCase()}s found.`)}</tbody></table></div></section></div>`;
        bindSearch('#contact-search', '#contact-table');
        $('#contact-form').addEventListener('submit', async (event) => {
            event.preventDefault();
            try { const response = await api('contact_save', { body: serializeForm(event.currentTarget) }); toast(response.message); await refreshBootstrap(); await renderContacts(type); } catch (error) { toast(apiErrorMessage(error), 'error'); }
        });
        $$('.edit-contact').forEach((button) => button.addEventListener('click', () => {
            const record = result.data.find((item) => String(item.id) === button.dataset.id);
            const form = $('#contact-form');
            ['id', 'type', 'name', 'mobile', 'email', 'address', 'contact_person', 'opening_balance'].forEach((key) => { if (form.elements[key]) form.elements[key].value = record[key] ?? ''; });
            form.scrollIntoView({ behavior: 'smooth' });
        }));
        $$('.contact-payment').forEach((button)=>button.addEventListener('click',()=>showContactPayment(button.dataset.id,button.dataset.type).catch((error)=>toast(error.message,'error'))));
        $$('.contact-ledger').forEach((button)=>button.addEventListener('click',()=>showContactLedger(button.dataset.id).catch((error)=>toast(error.message,'error'))));
    }

    async function showContactPayment(contactId,type){
        if(!state.bootstrap)await refreshBootstrap();const isSupplier=type==='supplier';
        const close=modal(isSupplier?'Supplier Payment':'Customer Collection',`<form id="contact-payment-form"><input type="hidden" name="contact_id" value="${contactId}"><input type="hidden" name="type" value="${isSupplier?'payment':'receive'}"><div class="form-grid"><div class="form-field"><label>Date</label><input name="date" type="date" value="${today()}"></div><div class="form-field"><label>Account</label><select name="account_id">${optionRows(state.bootstrap.accounts,'name',state.bootstrap.accounts[0]?.id,'Select Account')}</select></div><div class="form-field"><label>Amount</label><input name="amount" required type="number" min=".01" step=".01"></div><div class="form-field"><label>Discount</label><input name="discount" type="number" min="0" step=".01" value="0"></div><div class="form-field full"><label>Note</label><textarea name="note"></textarea></div></div></form>`, '<button class="button button-secondary cancel-payment">Cancel</button><button class="button button-primary" id="save-contact-payment">Save Payment</button>');
        $('.cancel-payment').addEventListener('click',close);$('#save-contact-payment').addEventListener('click',async()=>{try{const response=await api('contact_payment_save',{body:serializeForm($('#contact-payment-form'))});toast(response.message);close();await refreshBootstrap();await renderContacts(type);}catch(error){toast(error.message,'error');}});
    }

    async function showContactLedger(contactId){
        const result=await api('contact_ledger',{params:{contact_id:contactId}});let running=num(result.contact.opening_balance);
        const table=result.entries.map((row,index)=>{running+=num(row.debit)-num(row.credit);return `<tr><td>${index+1}</td><td>${formatDate(row.entry_date)}</td><td>${esc(row.reference)}</td><td>${esc(row.entry_type)}</td><td>${formatMoney(row.debit)}</td><td>${formatMoney(row.credit)}</td><td>${formatMoney(running)}</td></tr>`;}).join('');
        modal(`${result.contact.name} Ledger`,`<div class="panel-pad" style="background:#f5f9f7;border-radius:12px;margin-bottom:16px"><strong>${esc(result.contact.name)}</strong><br><span class="muted">${esc(result.contact.mobile)} &middot; ${esc(result.contact.address||'')}</span></div><div class="table-wrap"><table class="data-table"><thead><tr><th>#</th><th>Date</th><th>Reference</th><th>Type</th><th>Debit</th><th>Credit</th><th>Balance</th></tr></thead><tbody>${table||emptyRows(7,'No ledger entries found.')}</tbody></table></div>`, '<button class="button button-secondary modal-close-action">Close</button><button class="button button-primary" id="print-ledger">Print</button>',true);
        $('.modal-close-action').addEventListener('click',()=>{$('#modal-root').innerHTML='';});$('#print-ledger').addEventListener('click',()=>printDocument($('.modal-body').innerHTML,`${result.contact.name} Ledger`));
    }

    async function renderProductNew() {
        loading();
        const data = await api('product_form_data');
        const editId = new URLSearchParams(location.search).get('id');
        if (!state.editProduct && editId) {
            const products = await api('products');
            state.editProduct = products.data.find((product) => String(product.id) === editId) || null;
        }
        const item = state.editProduct || {};
        content().innerHTML = `<div class="page-enter">${pageHeader(item.id ? 'Edit Product' : 'Add New Product', 'Set prices, stock, barcode and product identity.', 'Product', '<button class="button button-secondary" data-page="product-list">Product List</button>')}
            <section class="panel form-panel"><form id="product-form"><input type="hidden" name="id" value="${esc(item.id || '')}"><div class="form-grid three">
                <div class="form-field full"><label>Product Name <span class="required">*</span></label><input name="name" required value="${esc(item.name || '')}" placeholder="Enter product name"></div>
                <div class="form-field"><label>Product Brand</label><select name="brand_id">${optionRows(data.brands, 'name', item.brand_id, 'Select Brand')}</select></div><div class="form-field"><label>Product Category</label><select name="category_id">${optionRows(data.categories, 'name', item.category_id, 'Select Category')}</select></div><div class="form-field"><label>Sub Category</label><select name="subcategory_id">${optionRows(data.subcategories, 'name', item.subcategory_id, 'Select Sub Category')}</select></div>
                <div class="form-field"><label>Product Unit</label><select name="unit_id">${optionRows(data.units, 'name', item.unit_id, 'Select Unit')}</select></div><div class="form-field"><label>SKU</label><input name="sku" value="${esc(item.sku || '')}" placeholder="SKU or model"></div><div class="form-field"><label>Barcode</label><input name="barcode" value="${esc(item.barcode || '')}" placeholder="Auto-generated if empty"></div>
                <div class="form-field"><label>Stock QTY</label><input name="stock" type="number" min="0" step="0.01" value="${esc(item.stock ?? 0)}"></div><div class="form-field"><label>Unit Cost</label><input name="cost_price" type="number" min="0" step="0.01" value="${esc(item.cost_price ?? 0)}"></div><div class="form-field"><label>Alert Quantity</label><input name="alert_qty" type="number" min="0" step="0.01" value="${esc(item.alert_qty ?? 5)}"></div>
                <div class="form-field"><label>Sale Price</label><input name="sale_price" type="number" min="0" step="0.01" value="${esc(item.sale_price ?? 0)}"></div><div class="form-field"><label>Dealer Price</label><input name="dealer_price" type="number" min="0" step="0.01" value="${esc(item.dealer_price ?? 0)}"></div><div class="form-field"><label>Warranty (months)</label><input name="warranty_months" type="number" min="0" value="${esc(item.warranty_months ?? 0)}"></div>
                <div class="form-field"><label>Manage Stock</label><label style="display:flex;align-items:center;gap:9px;min-height:43px"><input style="width:auto;min-height:auto" name="manage_stock" type="checkbox" ${item.manage_stock === 0 ? '' : 'checked'}> Track this product inventory</label></div>
                <div class="form-field full"><label>Product Image</label><label class="image-upload"><input id="product-image" name="image" type="file" accept="image/png,image/jpeg,image/webp,image/bmp"><span>Choose image</span><small>PNG, JPG, WEBP or BMP, maximum 3 MB</small>${item.image_data ? `<img src="${esc(item.image_data)}" alt="Product preview">` : ''}</label></div>
            </div><div class="form-actions"><button type="button" class="button button-secondary" data-page="product-list">Cancel</button><button class="button button-primary" type="submit">${item.id ? 'Update' : 'Create'} Product</button></div></form></section></div>`;
        $('#product-form').addEventListener('submit', async (event) => {
            event.preventDefault();
            try { const body = serializeForm(event.currentTarget); delete body.image; body.image_data = await fileToDataUrl($('#product-image')); const response = await api('product_save', { body }); toast(response.message); state.editProduct = null; await refreshBootstrap(); navigate('product-list'); } catch (error) { toast(apiErrorMessage(error), 'error'); }
        });
    }

    async function renderProductList(lowOnly = false) {
        loading();
        const result = await api('products');
        const records = lowOnly ? result.data.filter((item) => num(item.stock) <= num(item.alert_qty) && Number(item.manage_stock)) : result.data;
        const stockValue = records.reduce((sum, item) => sum + num(item.stock) * num(item.cost_price), 0);
        content().innerHTML = `<div class="page-enter">${pageHeader(lowOnly ? 'Low Stock Products' : 'Product List', lowOnly ? 'Products that need restocking.' : 'Search, price and monitor your complete inventory.', 'Product', '<button class="button button-primary" data-page="product-new">+ New Product</button>')}
            <div class="report-metrics"><div class="report-metric"><small>Total Products</small><strong>${records.length}</strong></div><div class="report-metric"><small>Stock Units</small><strong>${records.reduce((sum, item) => sum + num(item.stock), 0).toLocaleString()}</strong></div><div class="report-metric"><small>Stock Value</small><strong>${formatMoney(stockValue)}</strong></div><div class="report-metric"><small>Low Stock</small><strong class="negative">${result.data.filter((item) => num(item.stock) <= num(item.alert_qty) && Number(item.manage_stock)).length}</strong></div></div>
            <section class="panel table-panel"><div class="table-toolbar"><div><h2>Products</h2><p>${records.length} inventory items</p></div><div class="toolbar-tools"><input id="product-search" class="table-search" placeholder="Search name or barcode..."></div></div><div class="table-wrap"><table id="product-table" class="data-table"><thead><tr><th>#</th><th>Product</th><th>Barcode</th><th>Brand</th><th>Category</th><th>Stock</th><th>Cost</th><th>Sale Price</th><th>Status</th><th>Action</th></tr></thead><tbody>${records.length ? records.map((row, index) => `<tr><td>${index + 1}</td><td><strong>${esc(row.name)}</strong><br><small>${esc(row.sku || '')}</small></td><td>${esc(row.barcode || '-')}</td><td>${esc(row.brand_name || '-')}</td><td>${esc(row.category_name || '-')}</td><td>${num(row.stock).toLocaleString()} ${esc(row.unit_name || '')}</td><td>${formatMoney(row.cost_price)}</td><td><strong>${formatMoney(row.sale_price)}</strong></td><td>${badge(num(row.stock) <= num(row.alert_qty) ? 'Low stock' : 'In stock')}</td><td><button class="row-button edit-product" data-id="${row.id}">Edit</button></td></tr>`).join('') : emptyRows(10, 'No products found.')}</tbody></table></div></section></div>`;
        bindSearch('#product-search', '#product-table');
        $$('.edit-product').forEach((button) => button.addEventListener('click', () => { location.href = `product-new.php?id=${encodeURIComponent(button.dataset.id)}`; }));
    }

    async function renderLookup(type) {
    loading();

    const result = await api('lookups', {
        params: { type }
    });

    let categories = [];

    if (type === 'subcategory') {
        categories = (
            await api('lookups', {
                params: { type: 'category' }
            })
        ).data;
    }

    const labels = {
        brand: 'Brand',
        category: 'Category',
        subcategory: 'Sub Category',
        unit: 'Unit',
        expense_type: 'Expense Type'
    };

    const label = labels[type] || 'Item';

    content().innerHTML = `
        <div class="page-enter">

            ${pageHeader(
                `${label} Setup`,
                `Add, edit and manage ${label.toLowerCase()} values.`,
                type === 'expense_type' ? 'Expense' : 'Product'
            )}

            <div class="lookup-layout">

                <section class="panel form-panel">

                    <h2
                        class="section-title"
                        id="lookup-form-title">
                        Add ${label}
                    </h2>

                    <form id="lookup-form">

                        <input
                            type="hidden"
                            name="type"
                            value="${esc(type)}">

                        <input
                            type="hidden"
                            name="id"
                            value="">


                        <div class="form-field">

                            <label>
                                ${label} Name
                                <span class="required">*</span>
                            </label>

                            <input
                                name="name"
                                required
                                placeholder="Enter ${label.toLowerCase()} name">

                        </div>


                        ${type === 'unit' ? `
                            <div
                                class="form-field"
                                style="margin-top:14px">

                                <label>Short Name</label>

                                <input
                                    name="short_name"
                                    value="pcs"
                                    placeholder="pcs">

                            </div>
                        ` : ''}


                        ${type === 'subcategory' ? `
                            <div
                                class="form-field"
                                style="margin-top:14px">

                                <label>Parent Category</label>

                                <select name="category_id">
                                    ${optionRows(
                                        categories,
                                        'name',
                                        '',
                                        'Select Category'
                                    )}
                                </select>

                            </div>
                        ` : ''}


                        <div class="form-actions">

                            <button
                                id="lookup-cancel-edit"
                                class="button button-secondary"
                                type="button"
                                style="display:none">
                                Cancel
                            </button>

                            <button
                                id="lookup-save-button"
                                class="button button-primary"
                                type="submit">
                                Add ${label}
                            </button>

                        </div>

                    </form>

                </section>


                <section class="panel table-panel">

                    <div class="table-toolbar">

                        <div>
                            <h2>${label} List</h2>
                            <p>${result.data.length} values available</p>
                        </div>

                        <input
                            id="lookup-search"
                            class="table-search"
                            placeholder="Search ${label.toLowerCase()}...">

                    </div>


                    <div class="table-wrap">

                        <table
                            id="lookup-table"
                            class="data-table">

                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>${label}</th>

                                    ${type === 'unit'
                                        ? '<th>Short Name</th>'
                                        : ''
                                    }

                                    ${type === 'subcategory'
                                        ? '<th>Category</th>'
                                        : ''
                                    }

                                    <th>Action</th>
                                </tr>
                            </thead>

                            <tbody>

                                ${result.data.length
                                    ? result.data.map((row, index) => `

                                        <tr>

                                            <td>${index + 1}</td>

                                            <td>
                                                <strong>
                                                    ${esc(row.name)}
                                                </strong>
                                            </td>

                                            ${type === 'unit' ? `
                                                <td>
                                                    ${esc(
                                                        row.short_name || 'pcs'
                                                    )}
                                                </td>
                                            ` : ''}

                                            ${type === 'subcategory' ? `
                                                <td>
                                                    ${esc(
                                                        row.category_name || '-'
                                                    )}
                                                </td>
                                            ` : ''}

                                            <td>

                                                <div class="row-actions">

                                                    <button
                                                        type="button"
                                                        class="row-button edit-lookup"
                                                        data-id="${esc(row.id)}">
                                                        Edit
                                                    </button>

                                                    <button
                                                        type="button"
                                                        class="row-button danger delete-lookup"
                                                        data-id="${esc(row.id)}">
                                                        Delete
                                                    </button>

                                                </div>

                                            </td>

                                        </tr>

                                    `).join('')
                                    : emptyRows(
                                        type === 'unit' ||
                                        type === 'subcategory'
                                            ? 4
                                            : 3,
                                        `No ${label.toLowerCase()} found.`
                                    )
                                }

                            </tbody>

                        </table>

                    </div>

                </section>

            </div>

        </div>
    `;


    const form = $('#lookup-form');
    const saveButton = $('#lookup-save-button');
    const cancelButton = $('#lookup-cancel-edit');
    const formTitle = $('#lookup-form-title');


    /*
    |--------------------------------------------------------------------------
    | RESET FORM
    |--------------------------------------------------------------------------
    */

    const resetLookupForm = () => {

        form.reset();

        form.elements.type.value = type;
        form.elements.id.value = '';

        if (
            type === 'unit' &&
            form.elements.short_name
        ) {
            form.elements.short_name.value = 'pcs';
        }

        formTitle.textContent = `Add ${label}`;
        saveButton.textContent = `Add ${label}`;

        cancelButton.style.display = 'none';
    };


    /*
    |--------------------------------------------------------------------------
    | ADD / UPDATE
    |--------------------------------------------------------------------------
    */

    form.addEventListener(
        'submit',
        async event => {

            event.preventDefault();

            try {

                const body =
                    serializeForm(event.currentTarget);

                body.operation = 'save';

                const response =
                    await api(
                        'lookup_save',
                        { body }
                    );

                toast(response.message);

                await refreshBootstrap();

                await renderLookup(type);

            } catch (error) {

                toast(
                    error.message,
                    'error'
                );
            }
        }
    );


    /*
    |--------------------------------------------------------------------------
    | EDIT
    |--------------------------------------------------------------------------
    */

    $$('.edit-lookup').forEach(button => {

        button.addEventListener(
            'click',
            () => {

                const record =
                    result.data.find(
                        item =>
                            String(item.id) ===
                            String(button.dataset.id)
                    );

                if (!record) {
                    return;
                }

                form.elements.id.value =
                    record.id;

                form.elements.name.value =
                    record.name || '';


                if (
                    type === 'unit' &&
                    form.elements.short_name
                ) {
                    form.elements.short_name.value =
                        record.short_name || 'pcs';
                }


                if (
                    type === 'subcategory' &&
                    form.elements.category_id
                ) {
                    form.elements.category_id.value =
                        record.category_id || '';
                }


                formTitle.textContent =
                    `Edit ${label}`;

                saveButton.textContent =
                    `Update ${label}`;

                cancelButton.style.display =
                    'inline-flex';


                form.scrollIntoView({
                    behavior: 'smooth',
                    block: 'start'
                });


                form.elements.name.focus();
            }
        );

    });


    /*
    |--------------------------------------------------------------------------
    | CANCEL EDIT
    |--------------------------------------------------------------------------
    */

    cancelButton.addEventListener(
        'click',
        resetLookupForm
    );


    /*
    |--------------------------------------------------------------------------
    | DELETE
    |--------------------------------------------------------------------------
    */

    $$('.delete-lookup').forEach(button => {

        button.addEventListener(
            'click',
            async () => {

                const record =
                    result.data.find(
                        item =>
                            String(item.id) ===
                            String(button.dataset.id)
                    );

                if (!record) {
                    return;
                }


                const confirmed =
                    window.confirm(
                        `Delete "${record.name}"?`
                    );


                if (!confirmed) {
                    return;
                }


                try {

                    const response =
                        await api(
                            'lookup_save',
                            {
                                body: {
                                    type,
                                    id: record.id,
                                    operation: 'delete'
                                }
                            }
                        );


                    toast(response.message);

                    await refreshBootstrap();

                    await renderLookup(type);

                } catch (error) {

                    toast(
                        error.message,
                        'error'
                    );
                }
            }
        );

    });


    /*
    |--------------------------------------------------------------------------
    | SEARCH
    |--------------------------------------------------------------------------
    */

    bindSearch(
        '#lookup-search',
        '#lookup-table'
    );
}
    function transactionTotals(kind) {
        const subtotal = state.transactionItems.reduce((sum, item) => {
            const price = kind === 'purchase' ? num(item.cost_price) : num(item.price);
            return sum + num(item.qty) * price - (kind === 'sale' ? num(item.discount) : 0);
        }, 0);
        const discount = num($('#transaction-discount')?.value);
        const vat = num($('#transaction-vat')?.value);
        const other = num($('#transaction-other')?.value);
        return { subtotal, discount, vat, other, total: Math.max(0, subtotal - discount + vat + other) };
    }

    function renderTransactionRows(kind) {
        const body = $('#transaction-items');
        if (!body) return;
        body.innerHTML = state.transactionItems.length ? state.transactionItems.map((item, index) => {
            const price = kind === 'purchase' ? item.cost_price : item.price;
            const total = num(item.qty) * num(price) - (kind === 'sale' ? num(item.discount) : 0);
            return `<tr data-index="${index}"><td>${index + 1}</td><td class="item-name"><strong>${esc(item.name)}</strong><span class="stock-note">${esc(item.barcode || 'No barcode')}</span></td>${kind === 'sale' ? `<td>${num(item.stock).toLocaleString()}</td>` : ''}<td><input class="item-serials" placeholder="Serials" value="${esc((item.serials||[]).join(', '))}"></td><td><input class="item-warranty" type="number" min="0" value="${esc(item.warranty_months||0)}"></td><td><input class="item-qty" type="number" min="0.01" step="0.01" value="${esc(item.qty)}"></td>${kind === 'purchase' ? `<td><input class="item-cost" type="number" min="0" step="0.01" value="${esc(item.cost_price)}"></td><td><input class="item-sale-price" type="number" min="0" step="0.01" value="${esc(item.sale_price)}"></td><td class="line-margin">${num(item.cost_price) ? ((num(item.sale_price) - num(item.cost_price)) / num(item.cost_price) * 100).toFixed(1) : '0.0'}%</td><td><input class="item-dealer-price" type="number" min="0" step=".01" value="${esc(item.dealer_price||0)}"></td><td class="dealer-margin">${num(item.cost_price)?((num(item.dealer_price)-num(item.cost_price))/num(item.cost_price)*100).toFixed(1):'0.0'}%</td>` : `<td><input class="item-price" type="number" min="0" step="0.01" value="${esc(item.price)}"></td><td><input class="item-discount" type="number" min="0" step="0.01" value="${esc(item.discount)}"></td>`}<td><strong class="line-total">${formatMoney(total)}</strong></td><td><button class="row-button danger remove-item" type="button">Remove</button></td></tr>`;
        }).join('') : emptyRows(kind === 'purchase' ? 12 : 10, 'Select a product to start.');
        $$('tr[data-index]', body).forEach((row) => {
            const index = Number(row.dataset.index);
            $('.item-serials', row)?.addEventListener('input', (event) => { state.transactionItems[index].serials = event.target.value.split(/[,\n]/).map((value)=>value.trim()).filter(Boolean); });
            $('.item-warranty', row)?.addEventListener('input', (event) => { state.transactionItems[index].warranty_months = num(event.target.value); });
            $('.item-qty', row)?.addEventListener('input', (event) => { state.transactionItems[index].qty = num(event.target.value); updateTransaction(kind); });
            $('.item-cost', row)?.addEventListener('input', (event) => { state.transactionItems[index].cost_price = num(event.target.value); updateTransaction(kind); });
            $('.item-sale-price', row)?.addEventListener('input', (event) => { state.transactionItems[index].sale_price = num(event.target.value); updateTransaction(kind); });
            $('.item-dealer-price', row)?.addEventListener('input', (event) => { state.transactionItems[index].dealer_price = num(event.target.value); updateTransaction(kind); });
            $('.item-price', row)?.addEventListener('input', (event) => { state.transactionItems[index].price = num(event.target.value); updateTransaction(kind); });
            $('.item-discount', row)?.addEventListener('input', (event) => { state.transactionItems[index].discount = num(event.target.value); updateTransaction(kind); });
            $('.remove-item', row)?.addEventListener('click', () => { state.transactionItems.splice(index, 1); renderTransactionRows(kind); updateTransaction(kind); });
        });
    }

    function updateTransaction(kind) {
        const totals = transactionTotals(kind);
        if ($('#tx-subtotal')) $('#tx-subtotal').textContent = formatMoney(totals.subtotal);
        if ($('#tx-grand')) $('#tx-grand').textContent = formatMoney(totals.total);
        if ($('#tx-due')) $('#tx-due').textContent = formatMoney(Math.max(0, totals.total - num($('#transaction-paid')?.value)));
        $$('#transaction-items tr[data-index]').forEach((row) => {
            const item = state.transactionItems[Number(row.dataset.index)];
            if (!item) return;
            const price = kind === 'purchase' ? num(item.cost_price) : num(item.price);
            const total = num(item.qty) * price - (kind === 'sale' ? num(item.discount) : 0);
            if ($('.line-total', row)) $('.line-total', row).textContent = formatMoney(total);
            if ($('.line-margin', row)) $('.line-margin', row).textContent = `${price ? ((num(item.sale_price) - price) / price * 100).toFixed(1) : '0.0'}%`;
            if ($('.dealer-margin', row)) $('.dealer-margin', row).textContent = `${price ? ((num(item.dealer_price) - price) / price * 100).toFixed(1) : '0.0'}%`;
        });
    }

    async function renderTransaction(kind, vatMode = false) {
    loading();

    if (!state.bootstrap) {
        await refreshBootstrap();
    }

    state.transactionItems = [];

    const isSale = kind === 'sale';
    const contacts = isSale
        ? state.bootstrap.customers
        : state.bootstrap.suppliers;

    const title = isSale
        ? (vatMode ? 'Sale With VAT' : 'New Sale')
        : 'Create Purchase';

    const contactLabel = isSale ? 'Customer' : 'Supplier';

    const contactPlaceholder = isSale
        ? 'Walk-in Customer (Optional)'
        : 'Select Supplier';

    content().innerHTML = `
        <div class="page-enter">

            ${pageHeader(
                title,
                isSale
                    ? 'Fast ISP product sale and professional billing.'
                    : 'Purchase products and update stock automatically.',
                isSale ? 'Sale' : 'Purchase',
                `
                    <button
                        class="button button-secondary"
                        data-page="${isSale ? 'sale-list' : 'purchase-list'}">
                        View List
                    </button>
                `
            )}

            <div class="transaction-layout">

                <div class="transaction-main">

                    <!-- CUSTOMER / SUPPLIER -->
                    <section class="panel product-picker">

                        <div class="form-field">
                            <label>
                                ${contactLabel}
                                ${isSale
                                    ? '<small style="font-weight:600;color:#7b8782">(Optional)</small>'
                                    : '<span class="required">*</span>'
                                }
                            </label>

                            <select id="transaction-contact">
                                ${optionRows(
                                    contacts,
                                    'name',
                                    '',
                                    contactPlaceholder
                                )}
                            </select>
                        </div>

                        <div class="form-field">
                            <label>Invoice Date</label>

                            <input
                                id="transaction-date"
                                type="date"
                                value="${today()}">
                        </div>

                        <button
                            class="button button-secondary"
                            type="button"
                            data-page="${isSale ? 'customer' : 'supplier'}">
                            + Add ${contactLabel}
                        </button>

                    </section>


                    <!-- PRODUCT LIVE SEARCH -->
                    <section class="panel product-picker">

                        <div
                            class="form-field"
                            style="grid-column:1 / -2;position:relative">

                            <label>
                                Search Product
                                <span class="required">*</span>
                            </label>

                            <input
                                id="transaction-product-search"
                                type="text"
                                autocomplete="off"
                                placeholder="Type product name, model, SKU or barcode...">

                            <div
                                id="product-search-results"
                                style="
                                    display:none;
                                    position:absolute;
                                    z-index:50;
                                    top:100%;
                                    left:0;
                                    right:0;
                                    max-height:360px;
                                    overflow-y:auto;
                                    margin-top:5px;
                                    background:#fff;
                                    border:1px solid #dfe8e4;
                                    border-radius:12px;
                                    box-shadow:0 18px 45px rgba(20,50,40,.16);
                                ">
                            </div>

                        </div>
<button
    id="quick-add-product"
    class="button button-primary"
    type="button">
    + Add Product
</button>
                        <button
                            id="clear-product-search"
                            class="button button-secondary"
                            type="button">
                            Clear
                        </button>

                    </section>


                    <!-- PRODUCTS -->
                    <section class="panel table-panel">

                        <div class="table-wrap">

                            <table class="data-table item-table">

                                <thead>
                                    <tr>
                                        <th>No</th>
                                        <th>Product</th>

                                        ${isSale
                                            ? '<th>Stock</th>'
                                            : ''
                                        }

                                        <th>Serial / MAC</th>
                                        <th>Warranty</th>
                                        <th>Qty</th>

                                        ${isSale
                                            ? `
                                                <th>Sale Price</th>
                                                <th>Discount</th>
                                              `
                                            : `
                                                <th>Purchase Price</th>
                                                <th>Sale Price</th>
                                                <th>Margin</th>
                                                <th>DP/RP</th>
                                                <th>DP Margin</th>
                                              `
                                        }

                                        <th>Total</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>

                                <tbody id="transaction-items">

                                    ${emptyRows(
                                        isSale ? 10 : 12,
                                        'Search a product above to start.'
                                    )}

                                </tbody>

                            </table>

                        </div>

                    </section>


                    <!-- NOTE -->
                    <section class="panel form-panel">

                        <div class="form-grid">

                            <div class="form-field full">
                                <label>Invoice Note / Remarks</label>

                                <textarea
                                    id="transaction-note"
                                    placeholder="Warranty note, customer reference or other remarks"></textarea>
                            </div>

                        </div>

                    </section>

                </div>


                <!-- INVOICE SUMMARY -->
                <aside class="panel transaction-side">

                    <h3>Invoice Summary</h3>

                    <div class="total-line">
                        <span>Subtotal</span>
                        <strong id="tx-subtotal">
                            ${formatMoney(0)}
                        </strong>
                    </div>


                    <div class="side-fields">

                        <div class="form-field">
                            <label>Discount</label>

                            <input
                                id="transaction-discount"
                                type="number"
                                min="0"
                                step="0.01"
                                value="0">
                        </div>


                        ${vatMode
                            ? `
                                <div class="form-field">
                                    <label>VAT</label>

                                    <input
                                        id="transaction-vat"
                                        type="number"
                                        min="0"
                                        step="0.01"
                                        value="0">
                                </div>
                              `
                            : `
                                <input
                                    id="transaction-vat"
                                    type="hidden"
                                    value="0">
                              `
                        }


                        <div class="form-field">
                            <label>Other Cost</label>

                            <input
                                id="transaction-other"
                                type="number"
                                min="0"
                                step="0.01"
                                value="0">
                        </div>

                    </div>


                    <div class="total-line grand">
                        <span>Grand Total</span>

                        <strong id="tx-grand">
                            ${formatMoney(0)}
                        </strong>
                    </div>


                    <div class="payment-card">

                        <h4>Payment</h4>

                        <div class="form-field">

                            <label>Payment Account</label>

                            <select id="transaction-account">

                                ${optionRows(
                                    state.bootstrap.accounts,
                                    'name',
                                    state.bootstrap.accounts[0]?.id,
                                    'Select Account'
                                )}

                            </select>

                        </div>


                        <div
                            class="form-field"
                            style="margin-top:10px">

                            <label>Paid Amount</label>

                            <input
                                id="transaction-paid"
                                type="number"
                                min="0"
                                step="0.01"
                                value="0">

                        </div>


                        <div class="total-line">

                            <span>Due</span>

                            <strong
                                id="tx-due"
                                class="negative">

                                ${formatMoney(0)}

                            </strong>

                        </div>

                    </div>


                    <button
                        id="save-transaction"
                        class="button button-primary button-block"
                        style="margin-top:18px">

                        ${isSale
                            ? 'SAVE SALE'
                            : 'CREATE PURCHASE'
                        }

                    </button>

                </aside>

            </div>

        </div>
    `;


    /*
    |--------------------------------------------------------------------------
    | ADD PRODUCT
    |--------------------------------------------------------------------------
    */

    const addProduct = (product) => {

        if (!product) {
            return toast(
                'Product not found.',
                'error'
            );
        }

        const existing = state.transactionItems.find(
            item =>
                String(item.product_id) ===
                String(product.id)
        );


        if (existing) {

            if (
                isSale &&
                Number(product.manage_stock) === 1 &&
                num(existing.qty) + 1 > num(product.stock)
            ) {
                return toast(
                    `Only ${num(product.stock)} in stock.`,
                    'error'
                );
            }

            existing.qty += 1;

        } else {

            state.transactionItems.push({

                product_id: product.id,

                name: product.name,

                sku: product.sku || '',

                barcode: product.barcode || '',

                brand_name: product.brand_name || '',

                category_name: product.category_name || '',

                unit_name: product.unit_name || 'pcs',

                stock: num(product.stock),

                qty: 1,

                price: num(product.sale_price),

                discount: 0,

                cost_price: num(product.cost_price),

                sale_price: num(product.sale_price),

                dealer_price: num(product.dealer_price),

                warranty_months:
                    num(product.warranty_months),

                serials: []

            });

        }

        renderTransactionRows(kind);
        updateTransaction(kind);

        $('#transaction-product-search').value = '';

        $('#product-search-results').style.display =
            'none';

        $('#transaction-product-search').focus();
    };


    /*
    |--------------------------------------------------------------------------
    | PRODUCT SEARCH
    |--------------------------------------------------------------------------
    */

    const searchInput =
        $('#transaction-product-search');

    const searchResults =
        $('#product-search-results');


    const searchableText = product => [

        product.name,
        product.sku,
        product.barcode,
        product.brand_name,
        product.category_name

    ]
        .filter(Boolean)
        .join(' ')
        .toLowerCase();


    const showProductResults = query => {

        const keyword =
            String(query || '')
                .trim()
                .toLowerCase();


        if (!keyword) {

            searchResults.innerHTML = '';

            searchResults.style.display =
                'none';

            return;

        }


        const products =
            state.bootstrap.products
                .filter(product =>
                    searchableText(product)
                        .includes(keyword)
                )
                .slice(0, 12);


        if (!products.length) {

            searchResults.innerHTML = `
                <div
                    style="
                        padding:18px;
                        text-align:center;
                        color:#7b8782;
                    ">
                    No matching product found
                </div>
            `;

            searchResults.style.display =
                'block';

            return;

        }


        searchResults.innerHTML =
            products.map((product, index) => `

                <button
                    type="button"
                    class="product-live-result"
                    data-product-id="${esc(product.id)}"
                    style="
                        display:grid;
                        grid-template-columns:1fr auto;
                        gap:5px 15px;
                        width:100%;
                        padding:12px 14px;
                        border:0;
                        border-bottom:1px solid #edf2f0;
                        background:#fff;
                        text-align:left;
                        cursor:pointer;
                    ">

                    <span>

                        <strong
                            style="
                                display:block;
                                color:#15211d;
                                font-size:14px;
                            ">
                            ${esc(product.name)}
                        </strong>

                        <small
                            style="
                                display:block;
                                margin-top:3px;
                                color:#6b7973;
                            ">

                            ${product.brand_name
                                ? esc(product.brand_name)
                                : ''
                            }

                            ${product.sku
                                ? ` • ${esc(product.sku)}`
                                : ''
                            }

                            ${product.barcode
                                ? ` • ${esc(product.barcode)}`
                                : ''
                            }

                        </small>

                    </span>


                    <span
                        style="
                            text-align:right;
                            white-space:nowrap;
                        ">

                        <strong
                            style="
                                display:block;
                                color:#008f55;
                            ">
                            ${formatMoney(
                                product.sale_price
                            )}
                        </strong>

                        <small
                            style="
                                color:${
                                    num(product.stock) > 0
                                        ? '#6b7973'
                                        : '#d20e43'
                                };
                            ">
                            Stock:
                            ${num(product.stock).toLocaleString()}
                            ${esc(product.unit_name || '')}
                        </small>

                    </span>

                </button>

            `).join('');


        searchResults.style.display = 'block';


        $$('.product-live-result', searchResults)
            .forEach(button => {

                button.addEventListener(
                    'click',
                    () => {

                        const product =
                            state.bootstrap.products
                                .find(item =>
                                    String(item.id) ===
                                    String(
                                        button.dataset.productId
                                    )
                                );

                        addProduct(product);
                    }
                );

            });
    };


    searchInput.addEventListener(
        'input',
        event => {

            showProductResults(
                event.target.value
            );

        }
    );


    searchInput.addEventListener(
        'keydown',
        event => {

            if (event.key !== 'Enter') {
                return;
            }

            event.preventDefault();

            const query =
                event.target.value
                    .trim()
                    .toLowerCase();


            if (!query) {
                return;
            }


            const exactProduct =
                state.bootstrap.products.find(
                    product =>

                        String(product.barcode || '')
                            .toLowerCase() === query

                        ||

                        String(product.sku || '')
                            .toLowerCase() === query
                );


            if (exactProduct) {

                addProduct(exactProduct);

                return;
            }


            const firstResult =
                $('.product-live-result',
                    searchResults);


            if (firstResult) {

                const product =
                    state.bootstrap.products
                        .find(item =>
                            String(item.id) ===
                            String(
                                firstResult.dataset.productId
                            )
                        );

                addProduct(product);
            }

        }
    );


    $('#clear-product-search')
        .addEventListener(
            'click',
            () => {

                searchInput.value = '';

                searchResults.innerHTML = '';

                searchResults.style.display =
                    'none';

                searchInput.focus();
            }
        );


    document.addEventListener(
        'click',
        event => {

            if (
                !event.target.closest(
                    '#transaction-product-search'
                ) &&
                !event.target.closest(
                    '#product-search-results'
                )
            ) {
                searchResults.style.display =
                    'none';
            }

        },
        { once: true }
    );


    /*
    |--------------------------------------------------------------------------
    | TOTAL CALCULATION
    |--------------------------------------------------------------------------
    */

    [
        'transaction-discount',
        'transaction-vat',
        'transaction-other',
        'transaction-paid'

    ].forEach(id => {

        $(`#${id}`)?.addEventListener(
            'input',
            () => updateTransaction(kind)
        );

    });


    /*
    |--------------------------------------------------------------------------
    | SAVE SALE / PURCHASE
    |--------------------------------------------------------------------------
    */

    $('#save-transaction')
        .addEventListener(
            'click',
            async () => {

                const contactId =
                    $('#transaction-contact').value;


                /*
                 * Sale:
                 * Customer optional = Walk-in Customer
                 *
                 * Purchase:
                 * Supplier required
                 */

                if (
                    !isSale &&
                    !contactId
                ) {
                    return toast(
                        'Choose a supplier.',
                        'error'
                    );
                }


                if (
                    !state.transactionItems.length
                ) {
                    return toast(
                        'Add at least one product.',
                        'error'
                    );
                }


                const totals =
                    transactionTotals(kind);


                const body = {

                    date:
                        $('#transaction-date').value,

                    account_id:
                        $('#transaction-account').value,

                    discount:
                        totals.discount,

                    vat:
                        totals.vat,

                    other_cost:
                        totals.other,

                    paid:
                        num(
                            $('#transaction-paid').value
                        ),

                    note:
                        $('#transaction-note').value,

                    items:
                        state.transactionItems
                };


                body[
                    isSale
                        ? 'customer_id'
                        : 'supplier_id'
                ] = contactId || '';


                const saveButton =
                    $('#save-transaction');


                saveButton.disabled = true;

                saveButton.textContent =
                    isSale
                        ? 'SAVING SALE...'
                        : 'SAVING PURCHASE...';


                try {

                    const response =
                        await api(
                            isSale
                                ? 'sale_save'
                                : 'purchase_save',
                            { body }
                        );


                    toast(
                        `${response.message} ${response.invoice_no}`
                    );


                    await refreshBootstrap();


                    navigate(
                        isSale
                            ? 'sale-list'
                            : 'purchase-list'
                    );

                } catch (error) {

                    toast(
                        error.message,
                        'error'
                    );

                    saveButton.disabled =
                        false;

                    saveButton.textContent =
                        isSale
                            ? 'SAVE SALE'
                            : 'CREATE PURCHASE';
                }
            }
        );


    /*
    |--------------------------------------------------------------------------
    | READY
    |--------------------------------------------------------------------------
    */

    setTimeout(
        () =>
            $('#transaction-product-search')
                ?.focus(),
        100
    );
}

    async function renderInvoiceList(type, returnedOnly = false) {
        loading();
        const result = await api('invoices', { params: { type } });
        if (returnedOnly) return renderReturnList(type);
        const records = result.data;
        const title = `${type === 'sale' ? 'Sale' : 'Purchase'} ${returnedOnly ? 'Return ' : ''}List`;
        content().innerHTML = `<div class="page-enter">${pageHeader(title, 'Review totals, payments and outstanding balances.', type === 'sale' ? 'Sale' : 'Purchase', `<button class="button button-primary" data-page="${type === 'sale' ? 'sale-new' : 'purchase-new'}">+ Create ${type === 'sale' ? 'Sale' : 'Purchase'}</button>`)}
            <section class="panel table-panel"><div class="table-toolbar"><div><h2>${title}</h2><p>${records.length} invoices</p></div><input id="invoice-search" class="table-search" placeholder="Search invoice or contact..."></div><div class="table-wrap"><table id="invoice-table" class="data-table"><thead><tr><th>#</th><th>Invoice</th><th>${type === 'sale' ? 'Customer' : 'Supplier'}</th><th>Date</th><th>Total</th><th>Paid</th><th>Due</th><th>Status</th><th>Action</th></tr></thead><tbody>${records.length ? records.map((row, index) => `<tr><td>${index + 1}</td><td><strong>${esc(row.invoice_no)}</strong></td><td>${esc(row.contact)}</td><td>${formatDate(row[`${type === 'sale' ? 'sale' : 'purchase'}_date`])}</td><td>${formatMoney(row.total)}</td><td class="positive">${formatMoney(row.paid)}</td><td class="${num(row.due) ? 'negative' : ''}">${formatMoney(row.due)}</td><td>${badge(num(row.due) ? 'Due' : row.status)}</td><td><div class="row-actions"><button class="row-button view-invoice" data-id="${row.id}">View</button><button class="row-button danger return-invoice" data-id="${row.id}">Return</button></div></td></tr>`).join('') : emptyRows(9, 'No invoices found.')}</tbody></table></div></section></div>`;
        bindSearch('#invoice-search', '#invoice-table');
        $$('.view-invoice').forEach((button) => button.addEventListener('click', () => showInvoice(type, button.dataset.id).catch((error) => toast(apiErrorMessage(error), 'error'))));
        $$('.return-invoice').forEach((button) => button.addEventListener('click', () => showReturnModal(type, button.dataset.id).catch((error) => toast(apiErrorMessage(error), 'error'))));
    }

    async function renderReturnList(type) {
        loading();
        const result = await api('returns', { params: { type } });
        const isSale = type === 'sale';
        content().innerHTML = `<div class="page-enter">${pageHeader(`${isSale ? 'Sale' : 'Purchase'} Return List`, `Review all ${isSale ? 'customer refunds' : 'supplier returns'} and restored stock.`, isSale ? 'Sale' : 'Purchase', `<button class="button button-primary" data-page="${isSale ? 'sale-list' : 'purchase-list'}">Choose Invoice</button>`)}<section class="panel table-panel"><div class="table-toolbar"><div><h2>${isSale ? 'Sale' : 'Purchase'} Returns</h2><p>Showing ${result.data.length} records</p></div><input id="return-search" class="table-search" placeholder="Search returns..."></div><div class="table-wrap"><table id="return-table" class="data-table"><thead><tr><th>#</th><th>Reference</th><th>Source Invoice</th><th>${isSale ? 'Customer' : 'Supplier'}</th><th>Total</th><th>${isSale ? 'Refund' : 'Received'}</th><th>Created By</th><th>Date</th><th>Note</th></tr></thead><tbody>${result.data.length ? result.data.map((row, index) => `<tr><td>${index + 1}</td><td><strong>${esc(row.reference)}</strong></td><td>${esc(row.source_invoice)}</td><td>${esc(row.contact)}</td><td>${formatMoney(row.total)}</td><td class="${isSale ? 'negative' : 'positive'}">${formatMoney(isSale ? row.refund : row.received)}</td><td>${esc(row.created_by_name || 'System')}</td><td>${formatDate(row.return_date)}</td><td>${esc(row.note || '-')}</td></tr>`).join('') : emptyRows(9, `No ${isSale ? 'sale' : 'purchase'} returns found.`)}</tbody></table></div></section></div>`;
        bindSearch('#return-search', '#return-table');
    }

    async function showReturnModal(type, id) {
        if (!state.bootstrap) await refreshBootstrap();
        const result = await api('return_source', { params: { type, id } });
        const isSale = type === 'sale';
        const available = result.items.filter((item) => num(item.qty) > num(item.returned_qty));
        if (!available.length) return toast('All items from this invoice have already been returned.', 'error');
        const body = `<form id="return-form"><input type="hidden" name="${isSale ? 'sale_id' : 'purchase_id'}" value="${id}"><div class="panel-pad" style="background:#f5f9f7;border-radius:12px;margin-bottom:16px"><strong>${esc(result.document.invoice_no)}</strong> &middot; ${esc(result.document.contact)}</div><div class="table-wrap"><table class="data-table"><thead><tr><th>Product</th><th>Sold/Purchased</th><th>Already Returned</th><th>Return Qty</th><th>Rate</th><th>Total</th></tr></thead><tbody>${available.map((item) => `<tr class="return-line" data-product-id="${item.product_id}" data-price="${item.price}" data-max="${num(item.qty) - num(item.returned_qty)}"><td><strong>${esc(item.product_name)}</strong></td><td>${num(item.qty)}</td><td>${num(item.returned_qty)}</td><td><input class="return-qty" type="number" min="0" max="${num(item.qty) - num(item.returned_qty)}" step=".01" value="0"></td><td>${formatMoney(item.price)}</td><td class="return-line-total">${formatMoney(0)}</td></tr>`).join('')}</tbody></table></div><div class="form-grid" style="margin-top:18px"><div class="form-field"><label>Return Date</label><input name="date" type="date" value="${today()}"></div><div class="form-field"><label>Account</label><select name="account_id">${optionRows(state.bootstrap.accounts, 'name', state.bootstrap.accounts[0]?.id, 'Select Account')}</select></div><div class="form-field"><label>${isSale ? 'Refund Amount' : 'Received Amount'}</label><input name="${isSale ? 'refund' : 'received'}" id="return-payment" type="number" min="0" step=".01" value="0"></div><div class="form-field"><label>Return Total</label><input id="return-total" value="${formatMoney(0)}" disabled></div><div class="form-field full"><label>Note</label><textarea name="note"></textarea></div></div></form>`;
        const close = modal(`Create ${isSale ? 'Sale' : 'Purchase'} Return`, body, '<button class="button button-secondary cancel-return">Cancel</button><button class="button button-danger" id="save-return">Create Return</button>', true);
        const recalc = () => { let total = 0; $$('.return-line').forEach((row) => { const line = num($('.return-qty', row).value) * num(row.dataset.price); total += line; $('.return-line-total', row).textContent = formatMoney(line); }); $('#return-total').value = formatMoney(total); $('#return-payment').value = total.toFixed(2); };
        $$('.return-qty').forEach((input) => input.addEventListener('input', recalc));
        $('.cancel-return').addEventListener('click', close);
        $('#save-return').addEventListener('click', async () => { const formData = serializeForm($('#return-form')); formData.items = $$('.return-line').map((row) => ({ product_id: row.dataset.productId, qty: num($('.return-qty', row).value) })).filter((item) => item.qty > 0); try { const response = await api(isSale ? 'sale_return_save' : 'purchase_return_save', { body: formData }); toast(`${response.message} ${response.reference}`); close(); await refreshBootstrap(); navigate(isSale ? 'sale-return' : 'purchase-return'); } catch (error) { toast(apiErrorMessage(error), 'error'); } });
    }

    async function showInvoice(type, id) {
    const result = await api('invoice_detail', {
        params: { type, id }
    });

    const doc = result.document;
    const settings = result.settings || {};
    const isSale = type === 'sale';
    const dateKey = isSale ? 'sale_date' : 'purchase_date';

    const invoiceStatus = num(doc.due) > 0 ? 'DUE' : 'PAID';

    const logo = settings.logo_data
        ? `<img class="xsc-logo" src="${esc(settings.logo_data)}" alt="Logo">`
        : `<div class="xsc-logo-fallback">X</div>`;

    const itemRows = result.items.map((item, index) => {
        const rate = isSale ? item.price : item.cost_price;
        const discount = isSale ? num(item.discount) : 0;

        const productMeta = [
            item.sku ? `Model/SKU: ${esc(item.sku)}` : '',
            item.barcode ? `Code: ${esc(item.barcode)}` : ''
        ].filter(Boolean).join(' • ');

        return `
            <tr>
                <td class="center">${index + 1}</td>

                <td class="product-cell">
                    <strong>${esc(item.product_name)}</strong>
                    ${productMeta
                        ? `<small>${productMeta}</small>`
                        : ''
                    }
                </td>

                <td class="serial-cell">
                    ${esc(item.serial_numbers || '-')}
                </td>

                <td class="center">
                    ${num(item.warranty_months)
                        ? `${num(item.warranty_months)} Mo`
                        : '-'
                    }
                </td>

                <td class="center">
                    ${num(item.qty).toLocaleString('en-BD')}
                </td>

                <td class="center">
                    ${esc(item.unit_name || 'pcs')}
                </td>

                <td class="right">
                    ${formatMoney(rate)}
                </td>

                <td class="right">
                    ${formatMoney(discount)}
                </td>

                <td class="right">
                    <strong>${formatMoney(item.total)}</strong>
                </td>
            </tr>
        `;
    }).join('');

    const invoiceHtml = `
        <style>
            .xsc-invoice {
                --invoice-green:#009a5a;
                --invoice-dark:#12241d;
                --invoice-muted:#65736d;
                --invoice-line:#dce8e2;
                width:100%;
                color:var(--invoice-dark);
                font-family:Arial, Helvetica, sans-serif;
                background:#fff;
            }

            .xsc-invoice * {
                box-sizing:border-box;
            }

            .xsc-top {
                display:flex;
                justify-content:space-between;
                align-items:flex-start;
                gap:22px;
                padding-bottom:16px;
                border-bottom:3px solid var(--invoice-green);
            }

            .xsc-brand {
                display:flex;
                align-items:flex-start;
                gap:13px;
                min-width:0;
            }

            .xsc-logo {
                width:70px;
                height:70px;
                object-fit:contain;
                border-radius:8px;
            }

            .xsc-logo-fallback {
                display:grid;
                place-items:center;
                width:70px;
                height:70px;
                border-radius:10px;
                color:#fff;
                background:var(--invoice-green);
                font-size:34px;
                font-weight:900;
            }

            .xsc-brand h1 {
                margin:0;
                font-size:24px;
                line-height:1.05;
                letter-spacing:-.4px;
            }

            .xsc-tagline {
                margin:5px 0 8px;
                color:var(--invoice-green);
                font-size:11px;
                font-weight:700;
            }

            .xsc-company-info {
                margin:0;
                color:var(--invoice-muted);
                font-size:10px;
                line-height:1.55;
            }

            .xsc-invoice-title {
                min-width:175px;
                text-align:right;
            }

            .xsc-invoice-title h2 {
                margin:0 0 5px;
                color:var(--invoice-green);
                font-size:29px;
                letter-spacing:1px;
            }

            .xsc-invoice-number {
                margin:0;
                font-size:15px;
                font-weight:800;
            }

            .xsc-status {
                display:inline-block;
                margin-top:8px;
                padding:4px 11px;
                border-radius:20px;
                color:#fff;
                background:${num(doc.due) > 0 ? '#d8234e' : '#009a5a'};
                font-size:10px;
                font-weight:800;
            }

            .xsc-info-grid {
                display:grid;
                grid-template-columns:1fr 1fr;
                gap:12px;
                margin:16px 0;
            }

            .xsc-info-card {
                padding:12px 14px;
                border:1px solid var(--invoice-line);
                border-radius:8px;
                background:#fafcfb;
            }

            .xsc-info-card h3 {
                margin:0 0 8px;
                color:var(--invoice-green);
                font-size:10px;
                letter-spacing:.8px;
                text-transform:uppercase;
            }

            .xsc-info-row {
                display:grid;
                grid-template-columns:90px 1fr;
                gap:8px;
                margin:4px 0;
                font-size:10.5px;
            }

            .xsc-info-row span {
                color:var(--invoice-muted);
            }

            .xsc-info-row strong {
                font-weight:700;
            }

            .xsc-table {
                width:100%;
                border-collapse:collapse;
                table-layout:auto;
            }

            .xsc-table th {
                padding:8px 6px;
                border:1px solid #d7e3dd;
                color:#fff;
                background:var(--invoice-green);
                font-size:9px;
                text-align:left;
                text-transform:uppercase;
            }

            .xsc-table td {
                padding:8px 6px;
                border:1px solid #e1e9e5;
                font-size:9.5px;
                vertical-align:top;
            }

            .xsc-table tbody tr:nth-child(even) {
                background:#f8fbf9;
            }

            .product-cell {
                min-width:125px;
            }

            .product-cell strong {
                display:block;
                font-size:10px;
            }

            .product-cell small {
                display:block;
                margin-top:3px;
                color:var(--invoice-muted);
                font-size:8px;
                line-height:1.35;
            }

            .serial-cell {
                max-width:115px;
                white-space:normal;
                word-break:break-word;
                font-size:8.5px !important;
            }

            .center {
                text-align:center !important;
            }

            .right {
                text-align:right !important;
                white-space:nowrap;
            }

            .xsc-bottom {
                display:grid;
                grid-template-columns:minmax(0,1fr) 265px;
                gap:22px;
                margin-top:16px;
            }

            .xsc-note {
                padding:12px 14px;
                border-left:3px solid var(--invoice-green);
                border-radius:4px;
                background:#f5faf7;
            }

            .xsc-note h4 {
                margin:0 0 6px;
                font-size:10px;
                text-transform:uppercase;
            }

            .xsc-note p {
                margin:0;
                color:#52615b;
                font-size:8.5px;
                line-height:1.5;
                white-space:pre-line;
            }

            .xsc-totals {
                width:100%;
            }

            .xsc-total-row {
                display:flex;
                justify-content:space-between;
                gap:15px;
                padding:5px 0;
                border-bottom:1px solid #e8eeeb;
                font-size:10px;
            }

            .xsc-total-row span {
                color:var(--invoice-muted);
            }

            .xsc-total-row.grand {
                margin-top:4px;
                padding:9px 10px;
                border:0;
                border-radius:6px;
                color:#fff;
                background:var(--invoice-green);
            }

            .xsc-total-row.grand span,
            .xsc-total-row.grand strong {
                color:#fff;
                font-size:13px;
            }

            .xsc-total-row.due strong {
                color:#d8234e;
            }

            .xsc-signatures {
                display:grid;
                grid-template-columns:1fr 1fr;
                gap:70px;
                margin-top:46px;
            }

            .xsc-signature {
                padding-top:7px;
                border-top:1px solid #68756f;
                color:#5d6a65;
                font-size:9px;
                text-align:center;
            }

            .xsc-footer {
                margin-top:18px;
                padding-top:10px;
                border-top:1px solid var(--invoice-line);
                color:#66736e;
                font-size:8.5px;
                line-height:1.5;
                text-align:center;
                white-space:pre-line;
            }

            @media(max-width:650px) {
                .xsc-top {
                    gap:10px;
                }

                .xsc-logo,
                .xsc-logo-fallback {
                    width:48px;
                    height:48px;
                }

                .xsc-brand h1 {
                    font-size:16px;
                }

                .xsc-invoice-title h2 {
                    font-size:20px;
                }

                .xsc-invoice-title {
                    min-width:115px;
                }

                .xsc-bottom {
                    grid-template-columns:1fr;
                }
            }
        </style>

        <div id="printable-invoice" class="xsc-invoice">

            <div class="xsc-top">

                <div class="xsc-brand">

                    ${logo}

                    <div>
                        <h1>${esc(settings.business_name || "Xtreme'x Solutions & Communication")}</h1>

                        <div class="xsc-tagline">
                            ${esc(settings.tagline || '')}
                        </div>

                        <p class="xsc-company-info">
                            ${esc(settings.address || '')}<br>
                            Phone: ${esc(settings.phone || '')}
                            ${settings.email ? `<br>Email: ${esc(settings.email)}` : ''}
                            ${settings.website ? `<br>Web: ${esc(settings.website)}` : ''}
                        </p>
                    </div>

                </div>


                <div class="xsc-invoice-title">

                    <h2>${isSale ? 'INVOICE' : 'PURCHASE'}</h2>

                    <p class="xsc-invoice-number">
                        ${esc(doc.invoice_no)}
                    </p>

                    <div class="xsc-status">
                        ${esc(invoiceStatus)}
                    </div>

                </div>

            </div>


            <div class="xsc-info-grid">

                <div class="xsc-info-card">

                    <h3>${isSale ? 'Bill To' : 'Supplier'}</h3>

                    <div class="xsc-info-row">
                        <span>Name</span>
                        <strong>${esc(doc.contact || (isSale ? 'Walk-in Customer' : 'General Supplier'))}</strong>
                    </div>

                    <div class="xsc-info-row">
                        <span>Mobile</span>
                        <strong>${esc(doc.mobile || '-')}</strong>
                    </div>

                    <div class="xsc-info-row">
                        <span>Address</span>
                        <strong>${esc(doc.address || '-')}</strong>
                    </div>

                </div>


                <div class="xsc-info-card">

                    <h3>Invoice Details</h3>

                    <div class="xsc-info-row">
                        <span>Invoice No</span>
                        <strong>${esc(doc.invoice_no)}</strong>
                    </div>

                    <div class="xsc-info-row">
                        <span>Date</span>
                        <strong>${formatDate(doc[dateKey])}</strong>
                    </div>

                    <div class="xsc-info-row">
                        <span>Sales By</span>
                        <strong>${esc(doc.created_by_name || 'System')}</strong>
                    </div>

                    <div class="xsc-info-row">
                        <span>Payment</span>
                        <strong>${esc(doc.account_name || '-')}</strong>
                    </div>

                </div>

            </div>


            <table class="xsc-table">

                <thead>
                    <tr>
                        <th class="center">SL</th>
                        <th>Product / Model</th>
                        <th>Serial / MAC</th>
                        <th class="center">Warranty</th>
                        <th class="center">Qty</th>
                        <th class="center">Unit</th>
                        <th class="right">Rate</th>
                        <th class="right">Discount</th>
                        <th class="right">Amount</th>
                    </tr>
                </thead>

                <tbody>
                    ${itemRows}
                </tbody>

            </table>


            <div class="xsc-bottom">

                <div>

                    <div class="xsc-note">
                        <h4>Terms & Warranty</h4>

                        <p>${esc(
                            doc.note ||
                            settings.invoice_note ||
                            'Thank you for your business.'
                        )}</p>
                    </div>

                </div>


                <div class="xsc-totals">

                    <div class="xsc-total-row">
                        <span>Subtotal</span>
                        <strong>${formatMoney(doc.subtotal)}</strong>
                    </div>

                    <div class="xsc-total-row">
                        <span>Discount</span>
                        <strong>${formatMoney(doc.discount)}</strong>
                    </div>

                    ${isSale && num(doc.vat) > 0 ? `
                        <div class="xsc-total-row">
                            <span>VAT</span>
                            <strong>${formatMoney(doc.vat)}</strong>
                        </div>
                    ` : ''}

                    ${num(doc.other_cost) > 0 ? `
                        <div class="xsc-total-row">
                            <span>Other Cost</span>
                            <strong>${formatMoney(doc.other_cost)}</strong>
                        </div>
                    ` : ''}

                    <div class="xsc-total-row grand">
                        <span>Grand Total</span>
                        <strong>${formatMoney(doc.total)}</strong>
                    </div>

                    <div class="xsc-total-row">
                        <span>Paid</span>
                        <strong>${formatMoney(doc.paid)}</strong>
                    </div>

                    <div class="xsc-total-row due">
                        <span>Due</span>
                        <strong>${formatMoney(doc.due)}</strong>
                    </div>

                </div>

            </div>


            <div class="xsc-signatures">

                <div class="xsc-signature">
                    Customer Signature
                </div>

                <div class="xsc-signature">
                    Authorized Signature
                </div>

            </div>


            <div class="xsc-footer">
                ${esc(
                    settings.invoice_footer ||
                    `Thank you for choosing ${settings.business_name || "Xtreme'x Solutions & Communication"}.`
                )}
            </div>

        </div>
    `;


    modal(
        isSale ? 'Sale Invoice' : 'Purchase Invoice',
        invoiceHtml,
        `
            <button class="button button-secondary modal-close-action">
                Close
            </button>

            <button
                class="button button-secondary"
                id="print-half-invoice">
                Print Half A4
            </button>

            <button
                class="button button-primary"
                id="print-a4-invoice">
                Print A4
            </button>
        `,
        true
    );


    $('.modal-close-action')
        ?.addEventListener('click', () => {
            $('#modal-root').innerHTML = '';
        });


    $('#print-a4-invoice')
        ?.addEventListener('click', () => {
            printProfessionalInvoice(
                $('#printable-invoice').outerHTML,
                doc.invoice_no,
                'A4'
            );
        });


    $('#print-half-invoice')
        ?.addEventListener('click', () => {
            printProfessionalInvoice(
                $('#printable-invoice').outerHTML,
                doc.invoice_no,
                'A5'
            );
        });
}


function printProfessionalInvoice(html, title, paperSize = 'A4') {

    const popup = window.open(
        '',
        '_blank',
        'width=1000,height=800'
    );

    if (!popup) {
        return toast(
            'Allow popups to print the invoice.',
            'error'
        );
    }

    const isHalf = paperSize === 'A5';

    popup.document.write(`
        <!doctype html>

        <html>

        <head>

            <meta charset="utf-8">

            <meta name="viewport"
                  content="width=device-width,initial-scale=1">

            <title>${esc(title)}</title>

            <style>

                @page {
                    size: ${isHalf ? 'A5 portrait' : 'A4 portrait'};
                    margin: ${isHalf ? '6mm' : '9mm'};
                }

                * {
                    box-sizing:border-box;
                }

                html,
                body {
                    margin:0;
                    padding:0;
                    background:#fff;
                }

                body {
                    font-family:Arial,Helvetica,sans-serif;
                    color:#12241d;
                }

                .xsc-invoice {
                    --invoice-green:#009a5a;
                    --invoice-dark:#12241d;
                    --invoice-muted:#65736d;
                    --invoice-line:#dce8e2;
                    width:100%;
                    color:var(--invoice-dark);
                    background:#fff;
                }

                .xsc-top {
                    display:flex;
                    justify-content:space-between;
                    align-items:flex-start;
                    gap:15px;
                    padding-bottom:12px;
                    border-bottom:3px solid var(--invoice-green);
                }

                .xsc-brand {
                    display:flex;
                    gap:10px;
                    align-items:flex-start;
                }

                .xsc-logo,
                .xsc-logo-fallback {
                    width:${isHalf ? '45px' : '65px'};
                    height:${isHalf ? '45px' : '65px'};
                    flex:0 0 auto;
                    object-fit:contain;
                    border-radius:7px;
                }

                .xsc-logo-fallback {
                    display:grid;
                    place-items:center;
                    color:#fff;
                    background:var(--invoice-green);
                    font-weight:900;
                }

                .xsc-brand h1 {
                    margin:0;
                    font-size:${isHalf ? '15px' : '22px'};
                    line-height:1.05;
                }

                .xsc-tagline {
                    margin:4px 0 6px;
                    color:var(--invoice-green);
                    font-size:${isHalf ? '7px' : '10px'};
                    font-weight:700;
                }

                .xsc-company-info {
                    margin:0;
                    color:var(--invoice-muted);
                    font-size:${isHalf ? '7px' : '9px'};
                    line-height:1.4;
                }

                .xsc-invoice-title {
                    text-align:right;
                    white-space:nowrap;
                }

                .xsc-invoice-title h2 {
                    margin:0 0 4px;
                    color:var(--invoice-green);
                    font-size:${isHalf ? '18px' : '28px'};
                }

                .xsc-invoice-number {
                    margin:0;
                    font-size:${isHalf ? '8px' : '12px'};
                    font-weight:800;
                }

                .xsc-status {
                    display:inline-block;
                    margin-top:5px;
                    padding:3px 8px;
                    border-radius:15px;
                    color:#fff;
                    background:#009a5a;
                    font-size:${isHalf ? '6px' : '8px'};
                    font-weight:800;
                }

                .xsc-info-grid {
                    display:grid;
                    grid-template-columns:1fr 1fr;
                    gap:${isHalf ? '6px' : '10px'};
                    margin:${isHalf ? '8px' : '13px'} 0;
                }

                .xsc-info-card {
                    padding:${isHalf ? '6px 7px' : '9px 11px'};
                    border:1px solid var(--invoice-line);
                    border-radius:6px;
                    background:#fafcfb;
                }

                .xsc-info-card h3 {
                    margin:0 0 5px;
                    color:var(--invoice-green);
                    font-size:${isHalf ? '6px' : '8px'};
                    text-transform:uppercase;
                }

                .xsc-info-row {
                    display:grid;
                    grid-template-columns:${isHalf ? '48px' : '70px'} 1fr;
                    gap:5px;
                    margin:2px 0;
                    font-size:${isHalf ? '6.5px' : '8.5px'};
                }

                .xsc-info-row span {
                    color:var(--invoice-muted);
                }

                .xsc-table {
                    width:100%;
                    border-collapse:collapse;
                }

                .xsc-table th {
                    padding:${isHalf ? '4px 3px' : '6px 5px'};
                    border:1px solid #d5e1db;
                    color:#fff;
                    background:var(--invoice-green);
                    font-size:${isHalf ? '5.5px' : '7.5px'};
                    text-transform:uppercase;
                }

                .xsc-table td {
                    padding:${isHalf ? '4px 3px' : '6px 5px'};
                    border:1px solid #e0e8e4;
                    font-size:${isHalf ? '6px' : '8px'};
                    vertical-align:top;
                }

                .xsc-table tbody tr:nth-child(even) {
                    background:#f8fbf9;
                }

                .product-cell strong {
                    display:block;
                }

                .product-cell small {
                    display:block;
                    margin-top:2px;
                    color:#66736e;
                    font-size:${isHalf ? '5px' : '6.5px'};
                }

                .serial-cell {
                    max-width:${isHalf ? '58px' : '100px'};
                    white-space:normal;
                    word-break:break-word;
                }

                .center {
                    text-align:center;
                }

                .right {
                    text-align:right;
                    white-space:nowrap;
                }

                .xsc-bottom {
                    display:grid;
                    grid-template-columns:minmax(0,1fr) ${isHalf ? '140px' : '220px'};
                    gap:${isHalf ? '8px' : '18px'};
                    margin-top:${isHalf ? '8px' : '13px'};
                }

                .xsc-note {
                    padding:${isHalf ? '6px' : '9px'};
                    border-left:3px solid var(--invoice-green);
                    background:#f5faf7;
                }

                .xsc-note h4 {
                    margin:0 0 4px;
                    font-size:${isHalf ? '6px' : '8px'};
                }

                .xsc-note p {
                    margin:0;
                    color:#52615b;
                    font-size:${isHalf ? '5px' : '7px'};
                    line-height:1.4;
                    white-space:pre-line;
                }

                .xsc-total-row {
                    display:flex;
                    justify-content:space-between;
                    gap:8px;
                    padding:${isHalf ? '2px 0' : '4px 0'};
                    border-bottom:1px solid #e8eeeb;
                    font-size:${isHalf ? '6px' : '8px'};
                }

                .xsc-total-row.grand {
                    margin-top:3px;
                    padding:${isHalf ? '5px' : '7px'};
                    border:0;
                    border-radius:4px;
                    color:#fff;
                    background:var(--invoice-green);
                    font-weight:800;
                }

                .xsc-total-row.grand span,
                .xsc-total-row.grand strong {
                    color:#fff;
                }

                .xsc-total-row.due strong {
                    color:#d8234e;
                }

                .xsc-signatures {
                    display:grid;
                    grid-template-columns:1fr 1fr;
                    gap:${isHalf ? '35px' : '70px'};
                    margin-top:${isHalf ? '25px' : '40px'};
                }

                .xsc-signature {
                    padding-top:5px;
                    border-top:1px solid #69756f;
                    color:#5d6964;
                    font-size:${isHalf ? '6px' : '8px'};
                    text-align:center;
                }

                .xsc-footer {
                    margin-top:${isHalf ? '8px' : '14px'};
                    padding-top:7px;
                    border-top:1px solid var(--invoice-line);
                    color:#66736e;
                    font-size:${isHalf ? '5px' : '7px'};
                    line-height:1.4;
                    text-align:center;
                    white-space:pre-line;
                }

            </style>

        </head>

        <body>

            ${html}

        </body>

        </html>
    `);

    popup.document.close();

    popup.onload = () => {
        setTimeout(() => {
            popup.focus();
            popup.print();
        }, 300);
    };
}

    function printDocument(html, title) {
        const popup = window.open('', '_blank', 'width=900,height=700');
        if (!popup) return toast('Allow popups to print this document.', 'error');
        popup.document.write(`<!doctype html><html><head><title>${esc(title)}</title><link rel="stylesheet" href="${location.origin}${location.pathname.replace(/[^/]+$/, '')}style.css"><link rel="stylesheet" href="${location.origin}${location.pathname.replace(/[^/]+$/, '')}ui-complete.css?v=1.0.0"></head><body style="padding:30px;background:#fff">${html}</body></html>`);
        popup.document.close();
        popup.onload = () => { popup.focus(); popup.print(); };
    }

    async function renderExpense(reportOnly = false) {
        loading();
        const [expenses, types] = await Promise.all([api('expenses'), api('lookups', { params: { type: 'expense_type' } })]);
        if (!state.bootstrap) await refreshBootstrap();
        const total = expenses.data.reduce((sum, item) => sum + num(item.amount), 0);
        content().innerHTML = `<div class="page-enter">${pageHeader(reportOnly ? 'Expense Report' : 'Expense Management', 'Record and review operating expenses.', 'Expense', '<button class="button button-secondary" data-page="expense-type">Expense Types</button>')}
            ${reportOnly ? `<div class="report-metrics"><div class="report-metric"><small>Total Expense</small><strong class="negative">${formatMoney(total)}</strong></div><div class="report-metric"><small>Transactions</small><strong>${expenses.data.length}</strong></div><div class="report-metric"><small>Average</small><strong>${formatMoney(expenses.data.length ? total / expenses.data.length : 0)}</strong></div><div class="report-metric"><small>Expense Types</small><strong>${types.data.length}</strong></div></div>` : `<div class="two-column"><section class="panel form-panel"><h2 class="section-title">Add Expense</h2><form id="expense-form"><div class="form-grid"><div class="form-field"><label>Expense Type</label><select name="expense_type_id">${optionRows(types.data, 'name', '', 'Select Type')}</select></div><div class="form-field"><label>Account</label><select name="account_id">${optionRows(state.bootstrap.accounts, 'name', state.bootstrap.accounts[0]?.id, 'Select Account')}</select></div><div class="form-field"><label>Date</label><input name="date" type="date" value="${today()}"></div><div class="form-field"><label>Amount <span class="required">*</span></label><input name="amount" required type="number" min="0.01" step="0.01"></div><div class="form-field full"><label>Note</label><textarea name="note"></textarea></div></div><div class="form-actions"><button class="button button-primary" type="submit">Add Expense</button></div></form></section><aside class="panel panel-pad"><div class="panel-title"><span>EX</span><div><h3>Expense Summary</h3><p>All recorded expenses</p></div></div><div class="record-summary"><div class="summary-item"><span>Total</span><strong class="negative">${formatMoney(total)}</strong></div><div class="summary-item"><span>Records</span><strong>${expenses.data.length}</strong></div></div></aside></div>`}
            <section class="panel table-panel" style="margin-top:22px"><div class="table-toolbar"><div><h2>Expense List</h2><p>${expenses.data.length} records</p></div><input id="expense-search" class="table-search" placeholder="Search expenses..."></div><div class="table-wrap"><table id="expense-table" class="data-table"><thead><tr><th>#</th><th>Date</th><th>Type</th><th>Account</th><th>Note</th><th>Amount</th></tr></thead><tbody>${expenses.data.length ? expenses.data.map((row, index) => `<tr><td>${index + 1}</td><td>${formatDate(row.expense_date)}</td><td>${esc(row.type_name || 'Other')}</td><td>${esc(row.account_name || '-')}</td><td>${esc(row.note || '-')}</td><td class="negative"><strong>${formatMoney(row.amount)}</strong></td></tr>`).join('') : emptyRows(6, 'No expenses recorded.')}</tbody></table></div></section></div>`;
        bindSearch('#expense-search', '#expense-table');
        $('#expense-form')?.addEventListener('submit', async (event) => { event.preventDefault(); try { const response = await api('expense_save', { body: serializeForm(event.currentTarget) }); toast(response.message); await refreshBootstrap(); await renderExpense(false); } catch (error) { toast(apiErrorMessage(error), 'error'); } });
    }

    async function renderBank() {
    loading();

    const result = await api('accounts');

    content().innerHTML = `
    <div class="page-enter">
        ${pageHeader(
            'Bank Accounts',
            'Manage cash, mobile banking and bank balances.',
            'Bank Accounts',
            '<button class="button button-secondary" data-page="transfer">Balance Transfer</button>'
        )}

        <div class="two-column">

            <section class="panel form-panel">

                <h2 class="section-title" id="account-form-title">
                    Add Account
                </h2>

                <form id="account-form">

                    <input type="hidden" name="id" value="">

                    <div class="form-grid">

                        <div class="form-field full">
                            <label>
                                Account Name
                                <span class="required">*</span>
                            </label>

                            <input
                                name="name"
                                required
                                placeholder="Cash, bKash, Bank account"
                            >
                        </div>

                        <div class="form-field">
                            <label>Account Number</label>

                            <input name="account_no">
                        </div>

                        <div class="form-field">
                            <label>Bank / Method</label>

                            <input name="bank_name">
                        </div>

                        <div class="form-field full">

                            <label id="account-balance-label">
                                Opening Balance
                            </label>

                            <input
                                id="account-balance"
                                name="balance"
                                type="number"
                                min="0"
                                step="0.01"
                                value="0"
                            >

                            <small
                                id="account-balance-note"
                                class="muted"
                                style="display:none"
                            >
                                Live balance cannot be changed from Edit Account.
                                Use transactions or balance transfer instead.
                            </small>

                        </div>

                    </div>

                    <div class="form-actions">

                        <button
                            class="button button-secondary"
                            type="button"
                            id="account-cancel-edit"
                            style="display:none"
                        >
                            Cancel Edit
                        </button>

                        <button
                            class="button button-primary"
                            type="submit"
                            id="account-submit-button"
                        >
                            Add Account
                        </button>

                    </div>

                </form>

            </section>


            <aside class="panel panel-pad">

                <div class="panel-title">

                    <span>BK</span>

                    <div>
                        <h3>Combined Balance</h3>
                        <p>Across all accounts</p>
                    </div>

                </div>

                <h2 style="font-size:32px">

                    ${formatMoney(
                        result.data.reduce(
                            (sum, item) => sum + num(item.balance),
                            0
                        )
                    )}

                </h2>

                <p class="muted">
                    ${result.data.length} active account(s)
                </p>

            </aside>

        </div>


        <section
            class="panel table-panel"
            style="margin-top:22px"
        >

            <div class="table-toolbar">

                <div>
                    <h2>Account List</h2>
                    <p>Live available balances</p>
                </div>

            </div>


            <div class="table-wrap">

                <table class="data-table">

                    <thead>

                        <tr>
                            <th>#</th>
                            <th>Account</th>
                            <th>Method</th>
                            <th>Account No</th>
                            <th>Balance</th>
                            <th>Created</th>
                            <th>Action</th>
                        </tr>

                    </thead>

                    <tbody>

                        ${
                            result.data.length
                                ? result.data.map(
                                    (row, index) => `
                                        <tr>

                                            <td>
                                                ${index + 1}
                                            </td>

                                            <td>
                                                <strong>
                                                    ${esc(row.name)}
                                                </strong>
                                            </td>

                                            <td>
                                                ${esc(row.bank_name || '-')}
                                            </td>

                                            <td>
                                                ${esc(row.account_no || '-')}
                                            </td>

                                            <td class="positive">
                                                <strong>
                                                    ${formatMoney(row.balance)}
                                                </strong>
                                            </td>

                                            <td>
                                                ${formatDate(
                                                    String(row.created_at).slice(0, 10)
                                                )}
                                            </td>

                                            <td>

                                                <button
                                                    class="row-button edit-account"
                                                    type="button"
                                                    data-id="${row.id}"
                                                >
                                                    Edit
                                                </button>

                                            </td>

                                        </tr>
                                    `
                                ).join('')
                                : emptyRows(7)
                        }

                    </tbody>

                </table>

            </div>

        </section>

    </div>
    `;


    const form = $('#account-form');

    const title =
        $('#account-form-title');

    const submitButton =
        $('#account-submit-button');

    const cancelButton =
        $('#account-cancel-edit');

    const balanceInput =
        $('#account-balance');

    const balanceLabel =
        $('#account-balance-label');

    const balanceNote =
        $('#account-balance-note');


    const resetAccountForm = () => {

        form.reset();

        form.elements.id.value = '';

        balanceInput.readOnly = false;

        balanceInput.value = '0';

        balanceLabel.textContent =
            'Opening Balance';

        balanceNote.style.display =
            'none';

        title.textContent =
            'Add Account';

        submitButton.textContent =
            'Add Account';

        cancelButton.style.display =
            'none';
    };


    $$('.edit-account').forEach((button) => {

        button.addEventListener('click', () => {

            const record = result.data.find(
                (item) =>
                    String(item.id) ===
                    String(button.dataset.id)
            );

            if (!record) {
                return;
            }


            form.elements.id.value =
                record.id ?? '';

            form.elements.name.value =
                record.name ?? '';

            form.elements.account_no.value =
                record.account_no ?? '';

            form.elements.bank_name.value =
                record.bank_name ?? '';


            balanceInput.value =
                num(record.balance).toFixed(2);

            balanceInput.readOnly =
                true;


            balanceLabel.textContent =
                'Current Balance';

            balanceNote.style.display =
                'block';

            title.textContent =
                'Edit Account';

            submitButton.textContent =
                'Update Account';

            cancelButton.style.display =
                '';


            form.scrollIntoView({
                behavior: 'smooth',
                block: 'start'
            });


            form.elements.name.focus();

        });

    });


    cancelButton.addEventListener(
        'click',
        resetAccountForm
    );


    form.addEventListener(
        'submit',
        async (event) => {

            event.preventDefault();

            try {

                const response =
                    await api(
                        'account_save',
                        {
                            body: serializeForm(
                                event.currentTarget
                            )
                        }
                    );


                toast(response.message);


                await refreshBootstrap();

                await renderBank();

            } catch (error) {

                toast(
                    error.message,
                    'error'
                );

            }

        }
    );
}

    async function renderTransfer() {
        loading(); if (!state.bootstrap) await refreshBootstrap();
        content().innerHTML = `<div class="page-enter">${pageHeader('Balance Transfer', 'Move money safely between business accounts.', 'Bank Accounts')}
            <div class="two-column"><section class="panel form-panel"><h2 class="section-title">New Transfer</h2><form id="transfer-form"><div class="form-grid"><div class="form-field"><label>From Account</label><select name="from_account_id">${optionRows(state.bootstrap.accounts, 'name', '', 'Select Source')}</select></div><div class="form-field"><label>To Account</label><select name="to_account_id">${optionRows(state.bootstrap.accounts, 'name', '', 'Select Destination')}</select></div><div class="form-field"><label>Date</label><input name="date" type="date" value="${today()}"></div><div class="form-field"><label>Amount</label><input name="amount" required type="number" min="0.01" step="0.01"></div><div class="form-field full"><label>Note</label><textarea name="note">Balance transfer</textarea></div></div><div class="form-actions"><button class="button button-primary">Transfer Balance</button></div></form></section><aside class="panel panel-pad"><div class="panel-title"><span>TR</span><div><h3>Transfer Rules</h3><p>Account-to-account movement</p></div></div><p class="muted">The source account is reduced and the destination account is increased in one database transaction.</p></aside></div></div>`;
        $('#transfer-form').addEventListener('submit', async (event) => { event.preventDefault(); try { const response = await api('transfer_save', { body: serializeForm(event.currentTarget) }); toast(response.message); await refreshBootstrap(); navigate('transactions'); } catch (error) { toast(apiErrorMessage(error), 'error'); } });
    }

    async function renderTransactions() {
        loading(); const result = await api('transactions');
        content().innerHTML = `<div class="page-enter">${pageHeader('Transactions', 'Every payment and account movement in one ledger.', 'Bank Accounts', '<button class="button button-secondary" data-page="bank">Accounts</button>')}
            <section class="panel table-panel"><div class="table-toolbar"><div><h2>Transaction Ledger</h2><p>${result.data.length} entries</p></div><input id="transaction-search" class="table-search" placeholder="Search ledger..."></div><div class="table-wrap"><table id="transaction-table" class="data-table"><thead><tr><th>#</th><th>Date</th><th>Account</th><th>Type</th><th>Source</th><th>Reference</th><th>Note</th><th>Amount</th></tr></thead><tbody>${result.data.length ? result.data.map((row, index) => `<tr><td>${index + 1}</td><td>${formatDate(row.transaction_date)}</td><td>${esc(row.account_name || '-')}</td><td>${badge(row.type)}</td><td>${esc(row.source)}</td><td>${esc(row.reference || '-')}</td><td>${esc(row.note || '-')}</td><td class="${/out/.test(row.type) ? 'negative' : 'positive'}"><strong>${/out/.test(row.type) ? '-' : '+'}${formatMoney(row.amount)}</strong></td></tr>`).join('') : emptyRows(8, 'No transactions found.')}</tbody></table></div></section></div>`;
        bindSearch('#transaction-search', '#transaction-table');
    }

    async function renderSerialList() {
        loading();
        if (!state.bootstrap) await refreshBootstrap();
        const result = await api('serials');
        content().innerHTML = `<div class="page-enter">${pageHeader('Serial List', 'Search product serial numbers and monitor warranty status.', 'Warranty', '<button class="button button-primary" data-page="rma">Open RMA</button>')}<div class="two-column"><section class="panel form-panel"><h2 class="section-title">Add Serial Number</h2><form id="serial-form"><div class="form-field"><label>Product</label><select name="product_id">${optionRows(state.bootstrap.products, 'name', '', 'Select Product')}</select></div><div class="form-field" style="margin-top:14px"><label>Serial / IMEI</label><input name="serial_no" required placeholder="Enter serial number"></div><div class="form-actions"><button class="button button-primary">Add Serial</button></div></form></section><aside class="panel panel-pad"><div class="panel-title"><span>SN</span><div><h3>Serial Summary</h3><p>Warranty inventory</p></div></div><div class="record-summary"><div class="summary-item"><span>Total Serials</span><strong>${result.data.length}</strong></div><div class="summary-item"><span>In Stock</span><strong>${result.data.filter((row) => row.status === 'stock').length}</strong></div><div class="summary-item"><span>Sold</span><strong>${result.data.filter((row) => row.status === 'sold').length}</strong></div><div class="summary-item"><span>RMA</span><strong>${result.data.filter((row) => row.status === 'rma').length}</strong></div></div></aside></div><section class="panel table-panel" style="margin-top:22px"><div class="table-toolbar"><div><h2>Serial List</h2><p>Showing ${result.data.length} serials</p></div><input id="serial-search" class="table-search" placeholder="Search Serial..."></div><div class="table-wrap"><table id="serial-table" class="data-table"><thead><tr><th>#</th><th>Serial / IMEI</th><th>Product</th><th>Warranty</th><th>Reference</th><th>Status</th><th>Date</th></tr></thead><tbody>${result.data.length ? result.data.map((row, index) => `<tr><td>${index + 1}</td><td><strong>${esc(row.serial_no)}</strong></td><td>${esc(row.product_name)}</td><td>${row.warranty_months} month(s)</td><td>${esc(row.reference_type || 'Manual')} ${row.reference_id || ''}</td><td>${badge(row.status)}</td><td>${formatDate(String(row.created_at).slice(0,10))}</td></tr>`).join('') : emptyRows(7, 'No serial numbers found.')}</tbody></table></div></section></div>`;
        bindSearch('#serial-search', '#serial-table');
        $('#serial-form').addEventListener('submit', async (event) => { event.preventDefault(); try { const response = await api('serial_save', { body: serializeForm(event.currentTarget) }); toast(response.message); await renderSerialList(); } catch (error) { toast(apiErrorMessage(error), 'error'); } });
    }

    async function renderRma() {
        loading();
        if (!state.bootstrap) await refreshBootstrap();
        const [result, serialResult] = await Promise.all([api('rmas'), api('serials')]);
        const statusCards = [['in_house','In House'],['in_process','In Process'],['ready','Ready For Delivery'],['delivered','Delivery']];
        content().innerHTML = `<div class="page-enter">${pageHeader('RMA', 'Track warranty products from reception to delivery.', 'Warranty', '<button class="button button-secondary" data-page="serial-list">Serial List</button>')}<div class="rma-board">${statusCards.map(([status,label]) => `<article class="panel rma-status"><span>${esc(label)}</span><strong>${result.data.filter((row) => row.status === status).length}</strong></article>`).join('')}</div><section class="panel form-panel"><h2 class="section-title">Create RMA</h2><form id="rma-form"><div class="form-grid four"><div class="form-field"><label>Serial Number</label><select name="serial_id">${optionRows(serialResult.data, 'serial_no', '', 'Select Serial')}</select></div><div class="form-field"><label>Product</label><select name="product_id">${optionRows(state.bootstrap.products, 'name', '', 'Select Product')}</select></div><div class="form-field"><label>Customer</label><select name="customer_id">${optionRows(state.bootstrap.customers, 'name', '', 'Select Customer')}</select></div><div class="form-field"><label>Received Date</label><input name="received_date" type="date" value="${today()}"></div><div class="form-field full"><label>Issue <span class="required">*</span></label><input name="issue" required placeholder="Warranty issue"></div><div class="form-field"><label>Warranty Cost</label><input name="cost" type="number" min="0" step=".01" value="0"></div><div class="form-field"><label>Customer Charge</label><input name="charge" type="number" min="0" step=".01" value="0"></div><div class="form-field full"><label>Note</label><textarea name="note"></textarea></div></div><div class="form-actions"><button class="button button-primary">Create RMA</button></div></form></section><section class="panel table-panel" style="margin-top:22px"><div class="table-toolbar"><div><h2>RMA Serial List</h2><p>Showing ${result.data.length} records</p></div><input id="rma-search" class="table-search" placeholder="Search Serial..."></div><div class="table-wrap"><table id="rma-table" class="data-table"><thead><tr><th>#</th><th>RMA No</th><th>Serial</th><th>Product</th><th>Customer</th><th>Issue</th><th>Cost</th><th>Charge</th><th>Status</th><th>Action</th></tr></thead><tbody>${result.data.length ? result.data.map((row,index) => `<tr><td>${index+1}</td><td><strong>${esc(row.rma_no)}</strong></td><td>${esc(row.serial_no || '-')}</td><td>${esc(row.product_name || '-')}</td><td>${esc(row.customer)}</td><td>${esc(row.issue)}</td><td>${formatMoney(row.cost)}</td><td>${formatMoney(row.charge)}</td><td>${badge(row.status)}</td><td><select class="rma-status-select" data-id="${row.id}"><option value="in_house" ${row.status==='in_house'?'selected':''}>In House</option><option value="in_process" ${row.status==='in_process'?'selected':''}>In Process</option><option value="ready" ${row.status==='ready'?'selected':''}>Ready</option><option value="delivered" ${row.status==='delivered'?'selected':''}>Delivered</option><option value="cancelled" ${row.status==='cancelled'?'selected':''}>Cancelled</option></select></td></tr>`).join('') : emptyRows(10, 'No In House List found.')}</tbody></table></div></section></div>`;
        bindSearch('#rma-search', '#rma-table');
        $('#rma-form').addEventListener('submit', async (event) => { event.preventDefault(); try { const response = await api('rma_save', { body: serializeForm(event.currentTarget) }); toast(`${response.message} ${response.rma_no}`); await refreshBootstrap(); await renderRma(); } catch (error) { toast(apiErrorMessage(error), 'error'); } });
        $$('.rma-status-select').forEach((select) => select.addEventListener('change', async () => { try { const response = await api('rma_status', { body: { id: select.dataset.id, status: select.value } }); toast(response.message); await renderRma(); } catch (error) { toast(error.message,'error'); } }));
    }

    async function renderService(create = true) {
        loading();
        if (!state.bootstrap) await refreshBootstrap();
        const result = await api('services');
        content().innerHTML = `<div class="page-enter">${pageHeader(create ? 'Create Service' : 'Service List', create ? 'Register a device and track repair progress.' : 'Monitor every service job and payment.', 'Service', `<button class="button ${create ? 'button-secondary' : 'button-primary'}" data-page="${create ? 'service-list' : 'service-new'}">${create ? 'Service List' : '+ New Service'}</button>`)}
            ${create ? `<section class="panel form-panel"><form id="service-form"><div class="panel-title"><span>CT</span><div><h3>Customer & Technician</h3><p>Assign the device owner and service person</p></div></div><div class="form-grid three" style="margin-top:18px"><div class="form-field"><label>Customer <span class="required">*</span></label><select name="customer_id">${optionRows(state.bootstrap.customers, 'name', '', 'Search customer...')}</select></div><div class="form-field"><label>Technician</label><select name="technician_id">${optionRows(state.bootstrap.employees, 'name', '', 'Select Technician')}</select></div><div class="form-field"><label>Status</label><select name="status"><option value="received">Pending</option><option value="working">In progress</option><option value="ready">Completed / Ready</option><option value="delivered">Delivered</option></select></div></div><h2 class="section-title" style="margin-top:28px">Service Details</h2><div class="form-grid three"><div class="form-field"><label>Service Name / Device Name <span class="required">*</span></label><input name="device" required placeholder="e.g., Screen Replacement, Battery Change"></div><div class="form-field"><label>Estimated Service Cost</label><input name="amount" type="number" min="0" step=".01" value="0"></div><div class="form-field"><label>Serial Number / IMEI Number</label><input name="serial_no" placeholder="Device serial number (optional)"></div><div class="form-field full"><label>Problem Description <span class="required">*</span></label><textarea name="issue" required placeholder="Describe the issue briefly"></textarea></div><div class="form-field"><label>Received Date</label><input name="received_date" type="date" value="${today()}"></div><div class="form-field"><label>Expected Delivery</label><input name="delivery_date" type="date"></div><div class="form-field"><label>Device Password</label><input name="device_password" type="password" autocomplete="new-password" placeholder="If device has password"></div><div class="form-field"><label>Device Condition</label><input name="device_condition" placeholder="e.g., Good, Scratched, Broken"></div><div class="form-field full"><label>Technician Notes</label><textarea name="technician_notes" placeholder="Internal notes for technician"></textarea></div><div class="form-field full"><label>Additional Information</label><textarea name="note"></textarea></div></div><h2 class="section-title" style="margin-top:28px">Payment Details</h2><div class="form-grid four"><div class="form-field"><label>Service Charge</label><input name="service_charge" type="number" min="0" step=".01" value="0"></div><div class="form-field"><label>Paid Amount</label><input name="paid" type="number" min="0" step=".01" value="0"></div><div class="form-field"><label>Payment Account</label><select name="account_id">${optionRows(state.bootstrap.accounts, 'name', state.bootstrap.accounts[0]?.id, 'Select Account')}</select></div></div><div class="form-actions"><button class="button button-primary">Create Service Request</button></div></form></section>` : ''}
            <section class="panel table-panel" style="margin-top:${create ? '22px' : '0'}"><div class="table-toolbar"><div><h2>Service List</h2><p>Showing ${result.data.length} services</p></div><div class="toolbar-tools"><input id="service-search" class="table-search" placeholder="Search Service..."><button class="button button-secondary" data-page="service-report">Report</button></div></div><div class="table-wrap"><table id="service-table" class="data-table"><thead><tr><th>#</th><th>Service No</th><th>Customer</th><th>Device / Serial</th><th>Problem</th><th>Technician</th><th>Date</th><th>Total Cost</th><th>Paid / Due</th><th>Credential</th><th>Status</th></tr></thead><tbody>${result.data.length ? result.data.map((row, index) => { const total = num(row.amount)+num(row.service_charge); return `<tr><td>${index + 1}</td><td><strong>${esc(row.service_no)}</strong></td><td>${esc(row.customer)}<br><small>${esc(row.customer_mobile || '')}</small></td><td>${esc(row.device)}<br><small>${esc(row.serial_no || '')}</small></td><td>${esc(row.issue)}</td><td>${esc(row.technician || '-')}</td><td>${formatDate(row.received_date)}</td><td>${formatMoney(total)}</td><td><span class="positive">${formatMoney(row.paid)}</span><br><small class="negative">Due ${formatMoney(Math.max(0,total-num(row.paid)))}</small></td><td>${Number(row.has_device_password) ? (row.credential_protection === 'encrypted' && can('service.credential_reveal') ? `<button class="row-button service-credential-reveal" data-id="${row.id}">Reveal</button>` : `<small>${esc(row.credential_protection === 'legacy' ? 'Legacy / migration required' : 'Stored')}</small>`) : '<small>None</small>'}</td><td><select class="service-status-select" data-id="${row.id}"><option value="received" ${row.status==='received'?'selected':''}>Pending</option><option value="working" ${row.status==='working'?'selected':''}>In Progress</option><option value="ready" ${row.status==='ready'?'selected':''}>Ready</option><option value="delivered" ${row.status==='delivered'?'selected':''}>Delivered</option><option value="cancelled" ${row.status==='cancelled'?'selected':''}>Cancelled</option></select></td></tr>`; }).join('') : emptyRows(11, 'No services found.')}</tbody></table></div></section></div>`;
        bindSearch('#service-search', '#service-table');
        $('#service-form')?.addEventListener('submit', async (event) => { event.preventDefault(); try { const response = await api('service_save', { body: serializeForm(event.currentTarget) }); toast(`${response.message} ${response.service_no}`); await renderService(false); } catch (error) { toast(apiErrorMessage(error), 'error'); } });
        $$('.service-status-select').forEach((select) => select.addEventListener('change', async () => { try { const response = await api('service_status', { body: { id: select.dataset.id, status: select.value } }); toast(response.message); } catch (error) { toast(error.message,'error'); } }));
        $$('.service-credential-reveal').forEach((button) => button.addEventListener('click', async () => { try { const response = await api('service_credential_reveal', { body: { id: button.dataset.id } }); modal('Device Credential', `<div class="security-notice"><strong>${esc(response.service_no || 'Service')}</strong><p>Credential: <code>${esc(response.credential)}</code></p><p class="muted">This reveal was recorded in the activity log.</p></div>`); } catch (error) { toast(apiErrorMessage(error), 'error'); } }));
    }

    async function renderServiceReport(from = today(), to = today()) {
        loading();
        const result = await api('report', { params: { type: 'service', from, to } });
        const totalServices = result.data.reduce((sum,row) => sum + num(row.count),0);
        const totalCost = result.data.reduce((sum,row) => sum + num(row.cost),0);
        const totalPaid = result.data.reduce((sum,row) => sum + num(row.paid),0);
        const totalRefund = result.data.reduce((sum,row) => sum + num(row.refund),0);
        content().innerHTML = `<div class="page-enter">${pageHeader('Service Report', 'Service earnings, refunds, dues and status breakdown.', 'Service', '<button class="button button-secondary" onclick="window.print()">Print</button>')}<div class="filter-bar"><span class="filter-icon">SV</span><select id="service-report-period"><option value="today">Today</option><option value="week">This Week</option><option value="month">This Month</option><option value="last_30">Last 30 Days</option></select><input id="service-report-from" type="date" value="${today()}"><input id="service-report-to" type="date" value="${today()}"><button id="service-report-run" class="button button-primary">Apply</button></div><div class="report-metrics"><div class="report-metric"><small>Total Services</small><strong>${totalServices}</strong></div><div class="report-metric"><small>Total Cost</small><strong>${formatMoney(totalCost)}</strong></div><div class="report-metric"><small>Total Paid</small><strong class="positive">${formatMoney(totalPaid)}</strong></div><div class="report-metric"><small>Total Refund</small><strong class="negative">${formatMoney(totalRefund)}</strong></div><div class="report-metric"><small>Total Due</small><strong class="negative">${formatMoney(Math.max(0,totalCost-totalPaid))}</strong></div><div class="report-metric"><small>Total Earned</small><strong>${formatMoney(totalPaid-totalRefund)}</strong></div></div><section class="panel table-panel"><div class="table-toolbar"><div><h2>By Status</h2><p>${formatDate(result.from)} to ${formatDate(result.to)}</p></div></div><div class="table-wrap"><table class="data-table"><thead><tr><th>#</th><th>Status</th><th>Count</th><th>Cost</th><th>Paid</th><th>Refund</th><th>Due</th><th>Services</th></tr></thead><tbody>${result.data.length ? result.data.map((row,index)=>`<tr><td>${index+1}</td><td>${badge(row.status)}</td><td>${row.count}</td><td>${formatMoney(row.cost)}</td><td>${formatMoney(row.paid)}</td><td>${formatMoney(row.refund)}</td><td>${formatMoney(row.due)}</td><td><button class="row-button" data-page="service-list">View</button></td></tr>`).join('') : emptyRows(8,'No service report data found.')}</tbody></table></div></section></div>`;
        $('#service-report-run').addEventListener('click', () => renderServiceReport($('#service-report-from').value,$('#service-report-to').value).catch(showError));
    }

    function renderQuotationRows() {
        const body = $('#quotation-items'); if (!body) return;
        body.innerHTML = state.quotationItems.length ? state.quotationItems.map((item, index) => `<tr data-index="${index}"><td>${index + 1}</td><td><strong>${esc(item.name)}</strong></td><td><input class="quote-qty" type="number" min=".01" step=".01" value="${item.qty}"></td><td><input class="quote-price" type="number" min="0" step=".01" value="${item.price}"></td><td><strong>${formatMoney(num(item.qty) * num(item.price))}</strong></td><td><button class="row-button danger quote-remove">Remove</button></td></tr>`).join('') : emptyRows(6, 'Add products to the quotation.');
        $('#quotation-total').textContent = formatMoney(state.quotationItems.reduce((sum, item) => sum + num(item.qty) * num(item.price), 0));
        $$('tr[data-index]', body).forEach((row) => { const index = Number(row.dataset.index); $('.quote-qty', row).addEventListener('input', (event) => { state.quotationItems[index].qty = num(event.target.value); renderQuotationRows(); }); $('.quote-price', row).addEventListener('input', (event) => { state.quotationItems[index].price = num(event.target.value); renderQuotationRows(); }); $('.quote-remove', row).addEventListener('click', () => { state.quotationItems.splice(index, 1); renderQuotationRows(); }); });
    }

    async function renderQuotation(create = true) {
        loading(); if (!state.bootstrap) await refreshBootstrap();
        if (!create) {
            const result = await api('quotations');
            content().innerHTML = `<div class="page-enter">${pageHeader('Quotation List', 'Review proposals sent to customers.', 'Quotation', '<button class="button button-primary" data-page="quotation-new">+ Create Quotation</button>')}<section class="panel table-panel"><div class="table-toolbar"><div><h2>Quotations</h2><p>${result.data.length} records</p></div><input id="quotation-search" class="table-search" placeholder="Search quotations..."></div><div class="table-wrap"><table id="quotation-table" class="data-table"><thead><tr><th>#</th><th>Reference</th><th>Customer</th><th>Total</th><th>Profit</th><th>Created By</th><th>Date</th><th>Status</th><th>Action</th></tr></thead><tbody>${result.data.length ? result.data.map((row, i) => `<tr><td>${i + 1}</td><td><strong>${esc(row.quote_no)}</strong></td><td>${esc(row.customer)}</td><td>${formatMoney(row.total)}</td><td class="positive">${formatMoney(row.profit)}</td><td>${esc(row.created_by_name)}</td><td>${formatDate(row.quote_date)}</td><td>${badge(row.status)}</td><td><button class="row-button quote-view" data-id="${row.id}">View / Print</button></td></tr>`).join('') : emptyRows(9, 'No quotations found.')}</tbody></table></div></section></div>`;
            bindSearch('#quotation-search', '#quotation-table');
            $$('.quote-view').forEach((button) => button.addEventListener('click', () => showQuotation(button.dataset.id).catch((error) => toast(apiErrorMessage(error), 'error'))));
            return;
        }
        state.quotationItems = [];
        content().innerHTML = `<div class="page-enter">${pageHeader('Create Quotation', 'Build a customer proposal without changing stock.', 'Quotation', '<button class="button button-secondary" data-page="quotation-list">Quotation List</button>')}<section class="panel form-panel"><div class="form-grid four"><div class="form-field"><label>Customer</label><select id="quote-customer">${optionRows(state.bootstrap.customers, 'name', '', 'Select Customer')}</select></div><div class="form-field"><label>Bill To</label><input id="quote-bill-to" placeholder="Customer or company name"></div><div class="form-field"><label>Quotation Date</label><input id="quote-date" type="date" value="${today()}"></div><div class="form-field"><label>Valid Until</label><input id="quote-valid" type="date"></div></div></section><section class="panel product-picker" style="margin-top:18px"><div class="form-field"><label>Product / Barcode</label><select id="quote-product">${optionRows(state.bootstrap.products, 'name', '', 'Select Product')}</select></div><div></div><button id="quote-add" class="button button-primary">Add Product</button></section><section class="panel table-panel" style="margin-top:18px"><div class="table-wrap"><table class="data-table item-table"><thead><tr><th>#</th><th>Product</th><th>Qty</th><th>Price</th><th>Total</th><th>Action</th></tr></thead><tbody id="quotation-items">${emptyRows(6, 'Add products to the quotation.')}</tbody></table></div><div class="panel-pad text-right"><span class="muted">Quotation Total</span><h2 id="quotation-total">${formatMoney(0)}</h2><div class="form-field" style="text-align:left"><label>Note</label><textarea id="quote-note"></textarea></div><button id="quote-save" class="button button-primary" style="margin-top:15px">Create Quotation</button></div></section></div>`;
        $('#quote-add').addEventListener('click', () => { const product = state.bootstrap.products.find((item) => String(item.id) === $('#quote-product').value); if (!product) return toast('Choose a product.', 'error'); state.quotationItems.push({ product_id: product.id, name: product.name, qty: 1, price: num(product.sale_price) }); renderQuotationRows(); });
        $('#quote-customer').addEventListener('change', (event) => { const customer = state.bootstrap.customers.find((item) => String(item.id) === event.target.value); if (customer && !$('#quote-bill-to').value) $('#quote-bill-to').value = customer.name; });
        $('#quote-save').addEventListener('click', async () => { try { const response = await api('quotation_save', { body: { customer_id: $('#quote-customer').value, bill_to: $('#quote-bill-to').value, date: $('#quote-date').value, valid_until: $('#quote-valid').value, note: $('#quote-note').value, items: state.quotationItems } }); toast(`${response.message} ${response.quote_no}`); navigate('quotation-list'); } catch (error) { toast(apiErrorMessage(error), 'error'); } });
    }

    async function showQuotation(id) {
        const result = await api('quotation_details', { params: { id } });
        const quote = result.quotation;
        const documentHtml = `<div class="print-invoice"><h1>${esc(state.settings.business_name)}</h1><p>${esc(state.settings.address || '')} ${esc(state.settings.phone || '')}</p><hr><h2>QUOTATION ${esc(quote.quote_no)}</h2><p><strong>Bill To:</strong> ${esc(quote.customer)}<br><strong>Date:</strong> ${formatDate(quote.quote_date)} &nbsp; <strong>Valid Until:</strong> ${formatDate(quote.valid_until)}</p><table><thead><tr><th>#</th><th>Product</th><th>Warranty</th><th>Qty</th><th>Price</th><th>Total</th></tr></thead><tbody>${result.items.map((item,index)=>`<tr><td>${index+1}</td><td>${esc(item.product_name)}</td><td>${item.warranty_months} month(s)</td><td>${esc(item.qty)}</td><td>${formatMoney(item.price)}</td><td>${formatMoney(item.total)}</td></tr>`).join('')}</tbody></table><h2 class="text-right">Total: ${formatMoney(quote.total)}</h2><p>${esc(quote.note || state.settings.invoice_note || '')}</p></div>`;
        const close = modal('Quotation Details', documentHtml, '<button class="button button-secondary quote-close">Close</button><button class="button button-primary" id="quote-print">Print Quotation</button>');
        $('.quote-close').addEventListener('click', close);
        $('#quote-print').addEventListener('click', () => printDocument(documentHtml, quote.quote_no));
    }

    async function renderDamage(listOnly = false) {
        loading(); if (!state.bootstrap) await refreshBootstrap(); const [result, serialResult] = await Promise.all([api('damages'), api('serials')]);
        content().innerHTML = `<div class="page-enter">${pageHeader(listOnly ? 'Damage List' : 'Add Damage', 'Record damaged inventory and reduce stock.', 'Damage', `<button class="button button-secondary" data-page="${listOnly ? 'damage' : 'damage-list'}">${listOnly ? '+ Add Damage' : 'Damage List'}</button>`)}${listOnly ? '' : `<section class="panel form-panel"><form id="damage-form"><div class="form-grid four"><div class="form-field"><label>Date</label><input name="date" type="date" value="${today()}"></div><div class="form-field"><label>Product</label><select id="damage-product" name="product_id">${optionRows(state.bootstrap.products, 'name', '', 'Select Product')}</select></div><div class="form-field"><label>Barcode</label><input id="damage-barcode" placeholder="Product barcode"></div><div class="form-field"><label>Serial / IMEI</label><select name="serial_no"><option value="">Without Serial</option>${serialResult.data.filter((item)=>['stock','rma'].includes(item.status)).map((item)=>`<option value="${esc(item.serial_no)}" data-product="${item.product_id}">${esc(item.serial_no)} - ${esc(item.product_name)}</option>`).join('')}</select></div><div class="form-field"><label>Stock</label><input id="damage-stock" disabled value="0"></div><div class="form-field"><label>Quantity</label><input name="qty" required type="number" min=".01" step=".01" value="1"></div><div class="form-field"><label>Purchase Price</label><input id="damage-cost" name="purchase_price" type="number" min="0" step=".01" value="0"></div><div class="form-field"><label>Total Purchase</label><input id="damage-total" disabled value="${formatMoney(0)}"></div><div class="form-field full"><label>Note / Reason</label><textarea name="reason"></textarea></div></div><div class="form-actions"><button class="button button-primary">Save Damage</button></div></form></section>`}<section class="panel table-panel" style="margin-top:${listOnly ? 0 : 22}px"><div class="table-toolbar"><div><h2>Damage Records</h2><p>${result.data.length} entries</p></div><input id="damage-search" class="table-search" placeholder="Search damage..."></div><div class="table-wrap"><table id="damage-table" class="data-table"><thead><tr><th>#</th><th>Reference</th><th>Date</th><th>Product</th><th>Barcode</th><th>Serial</th><th>Qty</th><th>Purchase Price</th><th>Total Purchase</th><th>Note</th></tr></thead><tbody>${result.data.length ? result.data.map((row, i) => `<tr><td>${i + 1}</td><td><strong>${esc(row.reference_no || `DMG-${row.id}`)}</strong></td><td>${formatDate(row.damage_date)}</td><td>${esc(row.product_name)}</td><td>${esc(row.barcode || '-')}</td><td>${esc(row.serial_no || '-')}</td><td class="negative">-${esc(row.qty)}</td><td>${formatMoney(row.purchase_price)}</td><td>${formatMoney(row.total)}</td><td>${esc(row.reason || '-')}</td></tr>`).join('') : emptyRows(10, 'No damaged stock recorded.')}</tbody></table></div></section></div>`;
        bindSearch('#damage-search', '#damage-table');
        const damageForm = $('#damage-form');
        if (damageForm) {
            const quantityInput = $('[name="qty"]', damageForm);
            const updateDamageTotal = () => { $('#damage-total').value = formatMoney(num($('#damage-cost').value) * num(quantityInput.value)); };
            const updateDamageProduct = () => { const product = state.bootstrap.products.find((item) => String(item.id) === $('#damage-product').value); if (!product) return; $('#damage-barcode').value = product.barcode || ''; $('#damage-stock').value = product.stock; $('#damage-cost').value = product.cost_price; updateDamageTotal(); };
            $('#damage-product').addEventListener('change', updateDamageProduct);
            quantityInput.addEventListener('input', updateDamageTotal);
            $('#damage-cost').addEventListener('input', updateDamageTotal);
            damageForm.addEventListener('submit', async (event) => { event.preventDefault(); try { const response = await api('damage_save', { body: serializeForm(event.currentTarget) }); toast(response.message); await refreshBootstrap(); await renderDamage(false); } catch (error) { toast(apiErrorMessage(error), 'error'); } });
        }
    }

    async function renderCheque(addMode = false, status = 'pending') {
        loading();
        if (!state.bootstrap) await refreshBootstrap();
        const result = await api('cheques', { params: { status: addMode ? '' : status } });
        const contacts = [...state.bootstrap.customers, ...state.bootstrap.suppliers].filter((item,index,array) => array.findIndex((other) => String(other.id) === String(item.id)) === index);
        if (addMode) {
            content().innerHTML = `<div class="page-enter">${pageHeader('Add Cheque', 'Register a received or payment cheque.', 'Bank Accounts', '<button class="button button-secondary" data-page="cheque">Cheque List</button>')}<section class="panel form-panel"><form id="cheque-form"><div class="form-grid three"><div class="form-field"><label>Account</label><select name="account_id">${optionRows(state.bootstrap.accounts,'name','','Select Account')}</select></div><div class="form-field"><label>Contact</label><select name="contact_id">${optionRows(contacts,'name','','Search Contact...')}</select></div><div class="form-field"><label>Cheque No</label><input name="cheque_no" required></div><div class="form-field"><label>Amount</label><input name="amount" type="number" min=".01" step=".01" required></div><div class="form-field"><label>Issue Date</label><input name="issue_date" type="date" value="${today()}"></div><div class="form-field"><label>Cheque Date</label><input name="cheque_date" type="date" value="${today()}"></div><div class="form-field"><label>Type</label><select name="type"><option value="receive">Receive</option><option value="payment">Payment</option></select></div><div class="form-field full"><label>Note</label><textarea name="note"></textarea></div></div><div class="form-actions"><button class="button button-primary">Add Cheque</button></div></form></section></div>`;
            $('#cheque-form').addEventListener('submit', async (event) => { event.preventDefault(); try { const response=await api('cheque_save',{body:serializeForm(event.currentTarget)}); toast(response.message); navigate('cheque'); } catch(error){ toast(error.message,'error'); } });
            return;
        }
        const all = await api('cheques');
        const totalPayment = all.data.filter((row)=>row.type==='payment').reduce((sum,row)=>sum+num(row.amount),0);
        const totalReceive = all.data.filter((row)=>row.type==='receive').reduce((sum,row)=>sum+num(row.amount),0);
        content().innerHTML = `<div class="page-enter">${pageHeader('Cheque Management', 'Monitor pending, deposited, bounced and cleared cheques.', 'Bank Accounts', '<button class="button button-primary" data-page="cheque-new">+ Add New Cheque</button>')}<div class="status-tabs">${['pending','deposited','bounce','cleared'].map((item)=>`<button class="${status===item?'active':''}" data-cheque-status="${item}">${item[0].toUpperCase()+item.slice(1)}</button>`).join('')}</div><div class="report-metrics"><div class="report-metric"><small>Total Payment</small><strong class="negative">${formatMoney(totalPayment)}</strong><span>${all.data.filter((row)=>row.type==='payment').length} cheques</span></div><div class="report-metric"><small>Total Receive</small><strong class="positive">${formatMoney(totalReceive)}</strong><span>${all.data.filter((row)=>row.type==='receive').length} cheques</span></div><div class="report-metric"><small>Net Receivable</small><strong>${formatMoney(totalReceive-totalPayment)}</strong><span>${all.data.length} total cheques</span></div></div><section class="panel table-panel"><div class="table-toolbar"><div><h2>${status[0].toUpperCase()+status.slice(1)} Cheques</h2><p>Showing ${result.data.length} records</p></div><input id="cheque-search" class="table-search" placeholder="Search by name, mobile, cheque no, or amount..."></div><div class="table-wrap"><table id="cheque-table" class="data-table"><thead><tr><th>#</th><th>Contact</th><th>Account</th><th>Cheque No</th><th>Amount</th><th>Issue Date</th><th>Cheque Date</th><th>Type</th><th>Status</th><th>Actions</th></tr></thead><tbody>${result.data.length ? result.data.map((row,index)=>`<tr><td>${index+1}</td><td><strong>${esc(row.contact)}</strong><br><small>${esc(row.mobile||'')}</small></td><td>${esc(row.account_name||'-')}</td><td>${esc(row.cheque_no)}</td><td>${formatMoney(row.amount)}</td><td>${formatDate(row.issue_date)}</td><td>${formatDate(row.cheque_date)}</td><td>${badge(row.type)}</td><td>${badge(row.status)}</td><td><select class="cheque-status-select" data-id="${row.id}"><option value="pending" ${row.status==='pending'?'selected':''}>Pending</option><option value="deposited" ${row.status==='deposited'?'selected':''}>Deposited</option><option value="bounce" ${row.status==='bounce'?'selected':''}>Bounce</option><option value="cleared" ${row.status==='cleared'?'selected':''}>Cleared</option></select></td></tr>`).join('') : emptyRows(10,`No ${status} cheques found.`)}</tbody></table></div></section></div>`;
        bindSearch('#cheque-search','#cheque-table');
        $$('[data-cheque-status]').forEach((button)=>button.addEventListener('click',()=>renderCheque(false,button.dataset.chequeStatus).catch(showError)));
        $$('.cheque-status-select').forEach((select)=>select.addEventListener('change',async()=>{try{const response=await api('cheque_status',{body:{id:select.dataset.id,status:select.value}});toast(response.message);await refreshBootstrap();await renderCheque(false,status);}catch(error){toast(error.message,'error');}}));
    }

    async function renderInvestor() {
        loading(); const result = await api('investors');
        content().innerHTML = `<div class="page-enter">${pageHeader('Investor List', 'Track business investment and investor contacts.', 'Investment')}<div class="two-column"><section class="panel form-panel"><h2 class="section-title">Add Investor</h2><form id="investor-form"><div class="form-grid"><div class="form-field"><label>Name</label><input name="name" required></div><div class="form-field"><label>Mobile</label><input name="mobile"></div><div class="form-field"><label>Investment</label><input name="amount" type="number" min="0" step=".01"></div><div class="form-field"><label>Join Date</label><input name="date" type="date" value="${today()}"></div><div class="form-field full"><label>Note</label><textarea name="note"></textarea></div></div><div class="form-actions"><button class="button button-primary">Add Investor</button></div></form></section><aside class="panel panel-pad"><div class="panel-title"><span>IN</span><div><h3>Total Investment</h3><p>${result.data.length} investor(s)</p></div></div><h2 style="font-size:30px">${formatMoney(result.data.reduce((sum, item) => sum + num(item.amount), 0))}</h2></aside></div><section class="panel table-panel" style="margin-top:22px"><div class="table-wrap"><table class="data-table"><thead><tr><th>#</th><th>Name</th><th>Mobile</th><th>Join Date</th><th>Investment</th><th>Note</th></tr></thead><tbody>${result.data.length ? result.data.map((row, i) => `<tr><td>${i + 1}</td><td><strong>${esc(row.name)}</strong></td><td>${esc(row.mobile || '-')}</td><td>${formatDate(row.join_date)}</td><td class="positive"><strong>${formatMoney(row.amount)}</strong></td><td>${esc(row.note || '-')}</td></tr>`).join('') : emptyRows(6, 'No investors found.')}</tbody></table></div></section></div>`;
        $('#investor-form').addEventListener('submit', async (event) => { event.preventDefault(); try { const response = await api('investor_save', { body: serializeForm(event.currentTarget) }); toast(response.message); await renderInvestor(); } catch (error) { toast(apiErrorMessage(error), 'error'); } });
    }

    async function renderEmi(create = false) {
        loading(); if (!state.bootstrap) await refreshBootstrap(); const result = await api('emis');
        content().innerHTML = `<div class="page-enter">${pageHeader(create ? 'Create EMI' : 'EMI', 'Link a sale and manage installment schedules.', 'EMI', `<button class="button ${create ? 'button-secondary' : 'button-primary'}" data-page="${create ? 'emi-list' : 'emi-new'}">${create ? 'EMI List' : '+ Create EMI'}</button>`)}${create ? `<section class="panel form-panel"><form id="emi-form"><div class="form-grid three"><div class="form-field full"><label>Sale</label><select name="sale_id" id="emi-sale"><option value="">Search sale by reference or customer...</option>${state.bootstrap.sales.map((sale)=>`<option value="${sale.id}">${esc(sale.invoice_no)} - ${esc(sale.customer)} - ${formatMoney(sale.total)}</option>`).join('')}</select></div><div class="form-field"><label>Customer</label><select name="customer_id" id="emi-customer">${optionRows(state.bootstrap.customers,'name','','Auto-filled from sale')}</select></div><div class="form-field"><label>Grand Total</label><input name="total" id="emi-total" type="number" min=".01" step=".01" required></div><div class="form-field"><label>Advance</label><input name="down_payment" type="number" min="0" step=".01" value="0"></div><div class="form-field"><label>EMI No</label><input name="installment_count" type="number" min="1" value="6"></div><div class="form-field"><label>Type</label><select name="frequency"><option value="monthly">Monthly</option><option value="weekly">Weekly</option><option value="daily">Daily</option></select></div><div class="form-field"><label>Start Date</label><input name="start_date" type="date" value="${today()}"></div></div><div class="form-actions"><button class="button button-primary">Create</button></div></form></section>` : ''}<div class="status-tabs" style="margin-top:${create?22:0}px"><button class="active">Active</button><button>Completed</button><button>Cancelled</button><button data-page="installment-report">Installment Report</button></div><section class="panel table-panel"><div class="table-toolbar"><div><h2>EMI List</h2><p>Showing ${result.data.length} active EMI</p></div><input id="emi-search" class="table-search" placeholder="Search by customer name or mobile..."></div><div class="table-wrap"><table id="emi-table" class="data-table"><thead><tr><th>#</th><th>Customer</th><th>Grand Total</th><th>Paid / Due</th><th>Progress</th><th>Schedule</th><th>Status</th><th>Date</th><th>Action</th></tr></thead><tbody>${result.data.length ? result.data.map((row, i) => { const paid = num(row.down_payment) + num(row.paid_installments); const progress=num(row.total)?Math.min(100,paid/num(row.total)*100):0; return `<tr><td>${i + 1}</td><td><strong>${esc(row.customer || '-')}</strong><br><small>${esc(row.mobile||'')}</small></td><td>${formatMoney(row.total)}</td><td><span class="positive">${formatMoney(paid)}</span><br><span class="negative">${formatMoney(Math.max(0,num(row.total)-paid))}</span></td><td><div class="progress"><i style="width:${progress}%"></i></div><small>${progress.toFixed(1)}%</small></td><td>${row.installment_count} ${esc(row.frequency)}<br><small>Next: ${formatDate(row.next_due)}</small></td><td>${badge(row.status)}</td><td>${formatDate(row.start_date)}</td><td><button class="row-button pay-emi" data-id="${row.id}">Pay</button></td></tr>`; }).join('') : emptyRows(9, 'No EMI plans found.')}</tbody></table></div></section></div>`;
        bindSearch('#emi-search','#emi-table');
        $('#emi-sale')?.addEventListener('change',(event)=>{const sale=state.bootstrap.sales.find((item)=>String(item.id)===event.target.value);if(!sale)return;$('#emi-customer').value=String(sale.customer_id||'');$('#emi-total').value=num(sale.total).toFixed(2);});
        $('#emi-form')?.addEventListener('submit', async (event) => { event.preventDefault(); try { const response = await api('emi_save', { body: serializeForm(event.currentTarget) }); toast(response.message); await renderEmi(false); } catch (error) { toast(apiErrorMessage(error), 'error'); } });
        $$('.pay-emi').forEach((button) => button.addEventListener('click', () => { const close = modal('Record Installment', `<form id="emi-payment-form"><input type="hidden" name="emi_id" value="${button.dataset.id}"><div class="form-grid"><div class="form-field"><label>Date</label><input name="date" type="date" value="${today()}"></div><div class="form-field"><label>Amount</label><input name="amount" required type="number" min=".01" step=".01"></div><div class="form-field full"><label>Note</label><input name="note"></div></div></form>`, '<button class="button button-secondary cancel-modal">Cancel</button><button class="button button-primary" id="save-emi-payment">Save Payment</button>'); $('.cancel-modal').addEventListener('click', close); $('#save-emi-payment').addEventListener('click', async () => { try { const response = await api('emi_payment', { body: serializeForm($('#emi-payment-form')) }); toast(response.message); close(); await renderEmi(false); } catch (error) { toast(apiErrorMessage(error), 'error'); } }); }));
    }

    async function renderInstallmentReport(status='due'){
        loading();const result=await api('installments',{params:{status}});const amount=result.data.reduce((sum,row)=>sum+num(row.amount),0);const paid=result.data.reduce((sum,row)=>sum+num(row.paid),0);
        content().innerHTML=`<div class="page-enter">${pageHeader('Installment Report','Track due and paid installment schedules.','EMI','<button class="button button-secondary" onclick="window.print()">Print</button>')}<div class="status-tabs"><button class="${status==='due'?'active':''}" data-installment-status="due">Due</button><button class="${status==='paid'?'active':''}" data-installment-status="paid">Paid</button></div><div class="report-metrics"><div class="report-metric"><small>Amount</small><strong>${formatMoney(amount)}</strong></div><div class="report-metric"><small>Paid</small><strong class="positive">${formatMoney(paid)}</strong></div><div class="report-metric"><small>Due</small><strong class="negative">${formatMoney(amount-paid)}</strong></div></div><section class="panel table-panel"><div class="table-toolbar"><div><h2>${status==='due'?'Due':'Paid'} Installment Report</h2><p>${result.data.length} installments</p></div><input id="installment-search" class="table-search" placeholder="Search customer, mobile, note..."></div><div class="table-wrap"><table id="installment-table" class="data-table"><thead><tr><th>#</th><th>Customer</th><th>Mobile</th><th>Installment</th><th>Amount</th><th>Paid</th><th>Due</th><th>Due Date</th><th>Paid Date</th><th>Status</th><th>Note</th><th>Action</th></tr></thead><tbody>${result.data.length?result.data.map((row,index)=>`<tr><td>${index+1}</td><td><strong>${esc(row.customer||'-')}</strong></td><td>${esc(row.mobile||'-')}</td><td>${row.installment_no}/${row.installment_count}</td><td>${formatMoney(row.amount)}</td><td class="positive">${formatMoney(row.paid)}</td><td class="negative">${formatMoney(num(row.amount)-num(row.paid))}</td><td>${formatDate(row.due_date)}</td><td>${formatDate(row.paid_date)}</td><td>${badge(row.status)}</td><td>${esc(row.note||'-')}</td><td><button class="row-button" data-page="emi-list">View EMI</button></td></tr>`).join(''):emptyRows(12,'No installment records found.')}</tbody></table></div></section></div>`;
        bindSearch('#installment-search','#installment-table');$$('[data-installment-status]').forEach((button)=>button.addEventListener('click',()=>renderInstallmentReport(button.dataset.installmentStatus).catch(showError)));
    }

    async function renderTeam(srOnly = false) {
        loading(); if (!state.bootstrap) await refreshBootstrap(); const result = await api('employees'); const records = srOnly ? result.data.filter((item) => Number(item.is_sr) || /sales|sr/i.test(item.designation)) : result.data;
        content().innerHTML = `<div class="page-enter">${pageHeader(srOnly ? 'SR List' : 'Team', 'Manage employees, roles and payroll basics.', 'HRM', '<button class="button button-secondary" data-page="attendance">Attendance</button>')}<div class="two-column"><section class="panel form-panel"><h2 class="section-title">Add New Team Member</h2><form id="employee-form"><div class="form-grid"><div class="form-field"><label>Name <span class="required">*</span></label><input name="name" required placeholder="Member Name"></div><div class="form-field"><label>Mobile <span class="required">*</span></label><input name="mobile" required placeholder="01XXXXXXXXX"></div><div class="form-field"><label>Designation</label><input name="designation" value="${srOnly ? 'Sales Representative' : ''}"></div><div class="form-field"><label>Role</label><select name="role_id">${optionRows(state.bootstrap.roles,'name','','Select Role')}</select></div><div class="form-field"><label>Salary</label><input name="salary" type="number" min="0" step=".01"></div><div class="form-field"><label>Salary Date</label><select name="salary_day">${Array.from({length:28},(_,i)=>`<option value="${i+1}">${i+1}</option>`).join('')}</select></div><div class="form-field"><label>Join Date</label><input name="join_date" type="date" value="${today()}"></div><div class="form-field"><label>Access</label><div class="toggle-row"><label><input name="manage_business" type="checkbox"> Manage Business</label><label><input name="is_sr" type="checkbox" ${srOnly?'checked':''}> Is SR?</label></div></div></div><div class="form-actions"><button class="button button-primary">Add Team</button></div></form></section><aside class="panel panel-pad"><div class="panel-title"><span>HR</span><div><h3>Team Summary</h3><p>Active workforce</p></div></div><div class="record-summary"><div class="summary-item"><span>Team Members</span><strong>${records.length}</strong></div><div class="summary-item"><span>Monthly Payroll</span><strong>${formatMoney(records.reduce((sum, item) => sum + num(item.salary), 0))}</strong></div><div class="summary-item"><span>Sales Representatives</span><strong>${result.data.filter((item)=>Number(item.is_sr)).length}</strong></div></div></aside></div><section class="panel table-panel" style="margin-top:22px"><div class="table-wrap"><table class="data-table"><thead><tr><th>#</th><th>Name</th><th>User Mobile</th><th>Designation / Role</th><th>Salary</th><th>Salary Date</th><th>Balance</th><th>Active</th><th>Action</th></tr></thead><tbody>${records.length ? records.map((row, i) => `<tr><td>${i + 1}</td><td><strong>${esc(row.name)}</strong></td><td>${esc(row.mobile || '-')}</td><td>${esc(row.designation || '-')}<br><small>${esc(row.role_name||'')}</small></td><td>${formatMoney(row.salary)}</td><td>${row.salary_day}</td><td>${formatMoney(0)}</td><td>${badge(row.status)}</td><td><button class="row-button">Edit</button></td></tr>`).join('') : emptyRows(9, 'No Member found.')}</tbody></table></div></section></div>`;
        $('#employee-form').addEventListener('submit', async (event) => { event.preventDefault(); try { const response = await api('employee_save', { body: serializeForm(event.currentTarget) }); toast(response.message); await refreshBootstrap(); await renderTeam(srOnly); } catch (error) { toast(apiErrorMessage(error), 'error'); } });
    }

    async function renderAttendance() {
        loading(); if (!state.bootstrap) await refreshBootstrap(); const [result,scheduleResult,teamResult] = await Promise.all([api('attendance'),api('attendance_schedule'),api('employees')]);
        const schedule=scheduleResult.schedule||{}; const offDays=String(schedule.off_days||'').split(',');
        content().innerHTML = `<div class="page-enter">${pageHeader('Attendance Time Schedule', 'Create your team schedule and mark daily attendance.', 'HRM')}<section class="panel form-panel"><h2 class="section-title">Business Time Schedule</h2><form id="schedule-form"><div class="form-field full"><label>Off Days (optional)</label><div class="day-picker">${['Sat','Sun','Mon','Tue','Wed','Thu','Fri'].map((day)=>`<label><input type="checkbox" value="${day}" ${offDays.includes(day)?'checked':''}> ${day}</label>`).join('')}</div></div><div class="form-grid four" style="margin-top:18px"><div class="form-field"><label>Check In Time <span class="required">*</span></label><input name="check_in" type="time" value="${esc(String(schedule.check_in||'09:00').slice(0,5))}"></div><div class="form-field"><label>Check Out Time <span class="required">*</span></label><input name="check_out" type="time" value="${esc(String(schedule.check_out||'18:00').slice(0,5))}"></div><div class="form-field"><label>Late Check-In (minutes)</label><input name="late_minutes" type="number" min="0" value="${esc(schedule.late_minutes||15)}"></div><div class="form-field"><label>Absent After (minutes)</label><input name="absent_minutes" type="number" min="0" value="${esc(schedule.absent_minutes||120)}"></div></div><div class="form-actions"><button class="button button-primary">${schedule.id?'Update':'Create'} Schedule</button></div></form></section><section class="panel form-panel" style="margin-top:22px"><h2 class="section-title">Mark Attendance</h2><form id="attendance-form"><div class="form-grid four"><div class="form-field"><label>Team Member</label><select name="employee_id">${optionRows(state.bootstrap.employees, 'name', '', 'Select Member')}</select></div><div class="form-field"><label>Date</label><input name="date" type="date" value="${today()}"></div><div class="form-field"><label>Status</label><select name="status"><option value="present">Present</option><option value="late">Late</option><option value="leave">Leave</option><option value="absent">Absent</option></select></div><div class="form-field"><label>Check In</label><input name="check_in" type="time"></div><div class="form-field"><label>Check Out</label><input name="check_out" type="time"></div></div><div class="form-actions"><button class="button button-primary">Save Attendance</button></div></form></section><section class="panel table-panel" style="margin-top:22px"><div class="table-toolbar"><div><h2>Team List</h2><p>Showing ${teamResult.data.length} team members</p></div></div><div class="table-wrap"><table class="data-table"><thead><tr><th>No</th><th>Name</th><th>Mobile</th><th>Designation</th><th>Action</th></tr></thead><tbody>${teamResult.data.length?teamResult.data.map((row,index)=>`<tr><td>${index+1}</td><td><strong>${esc(row.name)}</strong></td><td>${esc(row.mobile||'-')}</td><td>${esc(row.designation||'-')}</td><td><button class="row-button">Attendance</button></td></tr>`).join(''):emptyRows(5,'No team members found.')}</tbody></table></div></section><section class="panel table-panel" style="margin-top:22px"><div class="table-toolbar"><div><h2>Attendance History</h2><p>${result.data.length} records</p></div></div><div class="table-wrap"><table class="data-table"><thead><tr><th>#</th><th>Date</th><th>Employee</th><th>Designation</th><th>Check In</th><th>Check Out</th><th>Status</th></tr></thead><tbody>${result.data.length ? result.data.map((row, i) => `<tr><td>${i + 1}</td><td>${formatDate(row.attendance_date)}</td><td><strong>${esc(row.employee)}</strong></td><td>${esc(row.designation || '-')}</td><td>${esc(row.check_in || '-')}</td><td>${esc(row.check_out || '-')}</td><td>${badge(row.status)}</td></tr>`).join('') : emptyRows(7, 'No attendance records found.')}</tbody></table></div></section></div>`;
        $('#schedule-form').addEventListener('submit',async(event)=>{event.preventDefault();const body=serializeForm(event.currentTarget);body.off_days=$$('input[type="checkbox"]',event.currentTarget).filter((input)=>input.checked).map((input)=>input.value);try{const response=await api('attendance_schedule_save',{body});toast(response.message);await renderAttendance();}catch(error){toast(error.message,'error');}});
        $('#attendance-form').addEventListener('submit', async (event) => { event.preventDefault(); try { const response = await api('attendance_save', { body: serializeForm(event.currentTarget) }); toast(response.message); await renderAttendance(); } catch (error) { toast(apiErrorMessage(error), 'error'); } });
    }

    async function renderRole() {
        loading(); const result=await api('roles');
        const modules=[['dashboard','Dashboard'],['customer','Customers'],['supplier','Suppliers'],['product','Products / Inventory'],['purchase','Purchases'],['sale','Sales'],['warranty','Warranty / RMA'],['service','Service / Repair'],['service.credential_reveal','Service Credential Reveal'],['quotation','Quotations'],['damage','Damage / Stock Loss'],['expense','Expenses'],['bank','Bank / Transactions'],['bank.accounts_manage','Bank Account Management'],['bank.transfer','Balance Transfer'],['emi','EMI / Installments'],['hrm','HRM / Attendance'],['report','Reports'],['settings','Business Settings'],['admin.users','User Administration'],['admin.roles','Role / Permission Administration'],['admin.backup','Backup / Export']];
        content().innerHTML=`<div class="page-enter">${pageHeader('Role Management','Create roles and assign module permissions.','HRM')}<div class="lookup-layout"><section class="panel form-panel"><h2 class="section-title">Create Role</h2><form id="role-form"><div class="form-field"><label>Role Name</label><input name="name" required placeholder="Role Name"></div><div class="form-field" style="margin-top:16px"><label>Permissions</label><div class="permission-grid">${modules.map(([value,label])=>`<label><input type="checkbox" value="${value}"> ${esc(label)}</label>`).join('')}</div></div><div class="form-actions"><button class="button button-primary">Create Role</button></div></form></section><section class="panel table-panel"><div class="table-toolbar"><div><h2>Role List</h2><p>${result.data.length} roles</p></div><input id="role-search" class="table-search" placeholder="Search Role"></div><div class="table-wrap"><table id="role-table" class="data-table"><thead><tr><th>#</th><th>Role Name</th><th>Permissions</th><th>Date</th></tr></thead><tbody>${result.data.length?result.data.map((row,index)=>`<tr><td>${index+1}</td><td><strong>${esc(row.name)}</strong></td><td>${esc(row.permissions||'-')}</td><td>${formatDate(String(row.created_at).slice(0,10))}</td></tr>`).join(''):emptyRows(4,'No roles found.')}</tbody></table></div></section></div></div>`;
        bindSearch('#role-search','#role-table');
        $('#role-form').addEventListener('submit',async(event)=>{event.preventDefault();const body=serializeForm(event.currentTarget);body.permissions=$$('input[type="checkbox"]',event.currentTarget).filter((input)=>input.checked).map((input)=>input.value);try{const response=await api('role_save',{body});toast(response.message);await refreshBootstrap();await renderRole();}catch(error){toast(error.message,'error');}});
    }

    async function renderProfile(){
        loading();const result=await api('profile');const user=result.data;
        const avatar=user.profile_photo?`<img src="${esc(user.profile_photo)}" alt="Profile photo">`:esc((user.name||'U')[0].toUpperCase());
        content().innerHTML=`<div class="page-enter">${pageHeader('Personal Information','Update your profile and preferred language.','Profile')}<section class="panel form-panel"><form id="profile-form"><div class="profile-editor"><div class="profile-avatar-large" id="profile-preview">${avatar}</div><div><strong>${esc(user.name)}</strong><p class="muted">${esc(user.phone)} &middot; ${esc(user.role)}</p></div></div><div class="form-grid" style="margin-top:24px"><div class="form-field"><label>Full Name <span class="required">*</span></label><input name="name" required value="${esc(user.name)}" placeholder="Enter your full name"></div><div class="form-field"><label>Mobile</label><input value="${esc(user.phone)}" disabled></div><div class="form-field full"><label>Address</label><textarea name="address" placeholder="Enter your complete address">${esc(user.address||'')}</textarea></div><div class="form-field"><label>Preferred Language</label><select name="language"><option value="English" ${user.language==='English'?'selected':''}>English</option><option value="Bangla" ${user.language==='Bangla'?'selected':''}>Bangla</option></select></div><div class="form-field"><label>Profile Photo</label><input id="profile-photo" type="file" accept="image/png,image/jpeg,image/webp,image/bmp"><small>Maximum 3 MB</small></div></div><div class="form-actions"><button class="button button-primary">Save Changes</button></div></form></section></div>`;
        $('.page-enter', content()).insertAdjacentHTML('beforeend', `<section class="panel form-panel security-panel">${Number(user.must_change_password) ? '<div class="security-notice"><strong>Password update required</strong><p>Change the temporary password before using other modules.</p></div>' : ''}<h2 class="section-title">Change Password</h2><form id="password-form"><div class="form-grid three"><div class="form-field"><label>Current Password</label><input name="current_password" type="password" autocomplete="current-password" required></div><div class="form-field"><label>New Password</label><input name="new_password" type="password" minlength="12" maxlength="128" autocomplete="new-password" required><small>12+ characters with uppercase, lowercase, number and symbol.</small></div><div class="form-field"><label>Confirm New Password</label><input name="confirm_password" type="password" minlength="12" maxlength="128" autocomplete="new-password" required></div></div><div class="form-actions"><button class="button button-primary">Update Password</button></div></form></section>`);
        $('#profile-photo').addEventListener('change',async()=>{try{const photo=await fileToDataUrl($('#profile-photo'));if(photo)$('#profile-preview').innerHTML=`<img src="${esc(photo)}" alt="Profile preview">`;}catch(error){toast(error.message,'error');$('#profile-photo').value='';}});
        $('#profile-form').addEventListener('submit',async(event)=>{event.preventDefault();try{const body=serializeForm(event.currentTarget);body.profile_photo=await fileToDataUrl($('#profile-photo'));const response=await api('profile_save',{body});toast(response.message);location.reload();}catch(error){toast(error.message,'error');}});
        $('#password-form').addEventListener('submit',async(event)=>{event.preventDefault();try{const response=await api('password_change',{body:serializeForm(event.currentTarget)});toast(response.message);event.currentTarget.reset();setTimeout(()=>location.href='dashboard.php',700);}catch(error){toast(error.message,'error');}});
    }

    async function renderMarketplace(){
        loading();const result=await api('marketplace');const request=result.request;const status=request?.status||result.settings.marketplace_status||'inactive';
        content().innerHTML=`<div class="page-enter">${pageHeader('Active Marketplace',`Enable marketplace features for ${result.settings.business_name}.`,'Marketplace')}<section class="panel marketplace-hero"><span class="marketplace-mark">MP</span><div><span class="eyebrow">MARKETPLACE</span><h2>${status==='approved'?'Marketplace is active':'Activate your marketplace'}</h2><p>${status==='pending'?'Your activation request is pending admin approval.':'Connect your product catalog with marketplace sales channels and manage orders from this ERP.'}</p>${badge(status)}</div></section><div class="marketplace-grid"><article class="panel panel-pad"><h3>Product Sync</h3><p class="muted">Publish selected inventory and keep stock synchronized.</p></article><article class="panel panel-pad"><h3>Order Inbox</h3><p class="muted">Receive marketplace orders in one list.</p></article><article class="panel panel-pad"><h3>Marketplace Report</h3><p class="muted">Review channel sales and settlement values.</p></article></div>${status==='inactive'?'<section class="panel form-panel" style="margin-top:22px"><form id="marketplace-form"><div class="form-field"><label>Request Note</label><textarea name="note" placeholder="Optional note for the administrator"></textarea></div><div class="form-actions"><button class="button button-primary">Request Activation</button></div></form></section>':''}</div>`;
        $('#marketplace-form')?.addEventListener('submit',async(event)=>{event.preventDefault();try{const response=await api('marketplace_request',{body:serializeForm(event.currentTarget)});toast(response.message);await renderMarketplace();}catch(error){toast(error.message,'error');}});
    }

    async function renderPaymentCenter(){
        loading();if(!state.bootstrap)await refreshBootstrap();const result=await api('contact_payments');
        const contacts=[...state.bootstrap.customers,...state.bootstrap.suppliers].filter((item,index,array)=>array.findIndex((other)=>String(other.id)===String(item.id))===index);
        content().innerHTML=`<div class="page-enter">${pageHeader('Account Payment','Receive customer dues and pay suppliers from one screen.','Payment','<button class="button button-secondary" data-page="transactions">Transaction Ledger</button>')}<div class="two-column"><section class="panel form-panel"><h2 class="section-title">New Payment Entry</h2><form id="payment-center-form"><div class="form-grid"><div class="form-field"><label>Payment Type</label><select name="type"><option value="receive">Customer Receive</option><option value="payment">Supplier Payment</option></select></div><div class="form-field"><label>Contact</label><select name="contact_id">${optionRows(contacts,'name','','Select Contact')}</select></div><div class="form-field"><label>Account</label><select name="account_id">${optionRows(state.bootstrap.accounts,'name',state.bootstrap.accounts[0]?.id,'Select Account')}</select></div><div class="form-field"><label>Date</label><input name="date" type="date" value="${today()}"></div><div class="form-field"><label>Amount</label><input name="amount" type="number" min=".01" step=".01" required></div><div class="form-field"><label>Discount</label><input name="discount" type="number" min="0" step=".01" value="0"></div><div class="form-field full"><label>Note</label><textarea name="note"></textarea></div></div><div class="form-actions"><button class="button button-primary">Save Payment</button></div></form></section><aside class="panel panel-pad"><div class="panel-title"><span>PY</span><div><h3>Payment Summary</h3><p>Customer and supplier settlements</p></div></div><div class="record-summary"><div class="summary-item"><span>Total Received</span><strong class="positive">${formatMoney(result.data.filter((row)=>row.type==='receive').reduce((sum,row)=>sum+num(row.amount),0))}</strong></div><div class="summary-item"><span>Total Paid</span><strong class="negative">${formatMoney(result.data.filter((row)=>row.type==='payment').reduce((sum,row)=>sum+num(row.amount),0))}</strong></div><div class="summary-item"><span>Entries</span><strong>${result.data.length}</strong></div></div></aside></div><section class="panel table-panel" style="margin-top:22px"><div class="table-toolbar"><div><h2>Payment History</h2><p>${result.data.length} records</p></div><input id="payment-search" class="table-search" placeholder="Search payment..."></div><div class="table-wrap"><table id="payment-table" class="data-table"><thead><tr><th>#</th><th>Date</th><th>Reference</th><th>Contact</th><th>Contact Type</th><th>Account</th><th>Type</th><th>Amount</th><th>Discount</th><th>Created By</th></tr></thead><tbody>${result.data.length?result.data.map((row,index)=>`<tr><td>${index+1}</td><td>${formatDate(row.payment_date)}</td><td><strong>PAY-${row.id}</strong></td><td>${esc(row.contact)}</td><td>${badge(row.contact_type)}</td><td>${esc(row.account)}</td><td>${badge(row.type)}</td><td class="${row.type==='receive'?'positive':'negative'}">${formatMoney(row.amount)}</td><td>${formatMoney(row.discount)}</td><td>${esc(row.created_by_name)}</td></tr>`).join(''):emptyRows(10,'No payment history found.')}</tbody></table></div></section></div>`;
        bindSearch('#payment-search','#payment-table');
        $('#payment-center-form').addEventListener('submit',async(event)=>{event.preventDefault();try{const response=await api('contact_payment_save',{body:serializeForm(event.currentTarget)});toast(response.message);await refreshBootstrap();await renderPaymentCenter();}catch(error){toast(error.message,'error');}});
    }

    async function renderBuySms(){
        loading();if(!state.bootstrap)await refreshBootstrap();const result=await api('sms_packages');const packages=[[100,120],[500,550],[1000,1000],[5000,4500]];
        content().innerHTML=`<div class="page-enter">${pageHeader('Buy SMS','Purchase SMS credits for invoices and customer notifications.','SMS',`<span class="sms-balance">Available SMS <strong>${result.balance}</strong></span>`)}<div class="sms-package-grid">${packages.map(([units,price],index)=>`<article class="panel sms-package ${index===2?'featured':''}"><span>${index===2?'POPULAR':'SMS PACKAGE'}</span><h2>${units.toLocaleString()} <small>SMS</small></h2><p>${formatMoney(price)}</p><button class="button ${index===2?'button-primary':'button-secondary'} sms-buy" data-units="${units}">Buy Package</button></article>`).join('')}</div><section class="panel table-panel" style="margin-top:22px"><div class="table-toolbar"><div><h2>Purchase History</h2><p>${result.history.length} packages</p></div></div><div class="table-wrap"><table class="data-table"><thead><tr><th>#</th><th>Package</th><th>SMS Units</th><th>Amount</th><th>Account</th><th>Status</th><th>Purchased At</th></tr></thead><tbody>${result.history.length?result.history.map((row,index)=>`<tr><td>${index+1}</td><td><strong>${esc(row.package_name)}</strong></td><td>${Number(row.units).toLocaleString()}</td><td>${formatMoney(row.amount)}</td><td>${esc(row.account)}</td><td>${badge(row.status)}</td><td>${formatDate(String(row.purchased_at).slice(0,10))}</td></tr>`).join(''):emptyRows(7,'No SMS packages purchased.')}</tbody></table></div></section></div>`;
        $$('.sms-buy').forEach((button)=>button.addEventListener('click',()=>{const units=button.dataset.units;const selected=packages.find((item)=>String(item[0])===units);const close=modal('Purchase SMS Package',`<form id="sms-purchase-form"><input type="hidden" name="units" value="${units}"><p class="confirm-copy">Purchase <strong>${Number(units).toLocaleString()} SMS</strong> for <strong>${formatMoney(selected[1])}</strong>.</p><div class="form-field"><label>Payment Account</label><select name="account_id">${optionRows(state.bootstrap.accounts,'name',state.bootstrap.accounts[0]?.id,'Select Account')}</select></div></form>`,'<button class="button button-secondary sms-cancel">Cancel</button><button class="button button-primary" id="sms-confirm">Confirm Purchase</button>');$('.sms-cancel').addEventListener('click',close);$('#sms-confirm').addEventListener('click',async()=>{try{const response=await api('sms_purchase',{body:serializeForm($('#sms-purchase-form'))});toast(response.message);close();await refreshBootstrap();await renderBuySms();}catch(error){toast(error.message,'error');}});}));
    }

    async function renderBarcode(single = false) {
        loading(); const result = await api('products'); const initial = single ? result.data.slice(0, 1) : result.data;
        content().innerHTML = `<div class="page-enter">${pageHeader(single ? 'Single Barcode' : 'Multi Barcode', 'Generate printable labels from product barcodes.', 'Barcode', '<button class="button button-primary no-print" id="print-barcodes">Print Labels</button>')}<section class="panel panel-pad"><div class="barcode-toolbar no-print"><div class="form-field"><label>Filter Product</label><select id="barcode-product">${optionRows(result.data, 'name', '', single ? 'Select Product' : 'All Products')}</select></div><div class="form-field"><label>Copies</label><input id="barcode-copies" type="number" min="1" max="100" value="1"></div><button id="barcode-generate" class="button button-secondary">Generate</button></div><div id="barcode-grid" class="barcode-grid">${barcodeLabels(initial, 1)}</div></section></div>`;
        $('#barcode-generate').addEventListener('click', () => { const id = $('#barcode-product').value; const products = id ? result.data.filter((item) => String(item.id) === id) : (single ? [] : result.data); $('#barcode-grid').innerHTML = barcodeLabels(products, Math.max(1, Number($('#barcode-copies').value) || 1)); });
        $('#print-barcodes').addEventListener('click', () => window.print());
    }

    function barcodeLabels(products, copies) {
        if (!products.length) return '<div class="empty-state"><span>BC</span><h3>No labels to show</h3><p>Add products or select a product to generate a label.</p></div>';
        return products.flatMap((product) => Array.from({ length: copies }, () => `<div class="barcode-label"><h4>${esc(product.name)}</h4><div class="barcode-lines"></div><p>${esc(product.barcode || `P${product.id}`)}</p><strong>${formatMoney(product.sale_price)}</strong></div>`)).join('');
    }

    const reportMap = {
        'business-report': { title:'Business Report', type:'business', columns:[['Reference','reference'],['Date','date','date'],['Name','name'],['Total','total','money'],['Paid','paid','money'],['Due','due','money']] },
        'sales-report': { title:'Sale Report', type:'sales', columns:[['Reference','reference'],['Customer Name','name'],['Sales Person','sales_person'],['Grand Total','total','money'],['Paid','paid','money'],['Due Amount','due','money'],['Profit','profit','money'],['Created Date','date','date']] },
        'top-customer': { title:'Top Customer List', type:'top_customer', columns:[['Customer Name','name'],['Address','address'],['Mobile','mobile'],['Total Sales','total','money'],['Total Profit','profit','money'],['Action','contact_id','ledger']] },
        'customer-report': { title:'Customer Report', type:'customer', columns:[['Name','name'],['Mobile','mobile'],['Address','address'],['Total Sales','total','money'],['Paid','paid','money'],['Balance','due','money'],['Ledger','contact_id','ledger']] },
        'receivable-report': { title:'Receivable Report', type:'receivable', columns:[['Name','name'],['Mobile','mobile'],['Address','address'],['Balance','due','money'],['Receive','contact_id','receive'],['Balance Sheet','contact_id','ledger']] },
        'payable-report': { title:'Payable Report', type:'payable', columns:[['Name','name'],['Mobile','mobile'],['Address','address'],['Balance','due','money'],['Pay','contact_id','payment'],['Balance Sheet','contact_id','ledger']] },
        'low-stock': { title:'Low Stock Product List', type:'alert_stock', columns:[['Product Name','product_name'],['Brand Name','brand_name'],['Category Name','category_name'],['Unit Cost','cost_price','money'],['MRP','sale_price','money'],['Barcode','barcode'],['Stock Qty','stock']] },
        'alert-product': { title:'Alert Product List', type:'alert_stock', columns:[['Product Name','product_name'],['Brand Name','brand_name'],['Category Name','category_name'],['Unit Cost','cost_price','money'],['MRP','sale_price','money'],['Barcode','barcode'],['Stock','stock'],['Alert Qty','alert_qty']] },
        'sale-product-report': { title:'Sale Product Report', type:'sale_product', columns:[['Date','date','date'],['Product Name','product_name'],['Qty','qty'],['Unit Cost','unit_cost','money'],['Unit Price','unit_price','money'],['Total Sales','total_sales','money'],['Profit','profit','money']] },
        'account-payment-report': { title:'Account Payment Report', type:'account_payment', columns:[['Date','date','date'],['Account Title','account_title'],['Reference (ID)','reference'],['Contact Info','contact'],['Created By','created_by'],['Payment/Cost','payment','money'],['Received','received','money']] },
        'full-expense-report': { title:'Expense Report', type:'expense_type', columns:[['Expense Type','expense_type'],['Amount','amount','money'],['Note','note'],['Date','date','date'],['Action','id','view']] },
        'transaction-report': { title:'Transactions Report', type:'transaction', columns:[['Date','date','date'],['Contact','contact'],['Type','type'],['Received/Purchase','received','money'],['Payment/Sale','payment','money'],['Note','note'],['Reference','reference']] },
        'daily-report': { title:'Daily Report', type:'account_payment', daily:true, columns:[['Date','date','date'],['Account Title','account_title'],['Reference','reference'],['Contact','contact'],['Source','source'],['Payment','payment','money'],['Received','received','money']] },
        'stock-report': { title:'Stock Report', type:'stock_detail', columns:[['Product','product'],['Brand','brand'],['Category','category'],['Purchase','purchase_qty'],['Purchase Return','purchase_return_qty'],['Total Sold','sold_qty'],['Return','return_qty'],['Damage','damage_qty'],['Alert Qty','alert_qty'],['Rate','rate','money'],['Stock','stock'],['Stock Value','stock_value','money'],['Purchase Date','purchase_date','date']] },
        'stock-list': { title:'Stock List', type:'stock_list', columns:[['Name','name'],['Brand','brand'],['Category','category'],['Stock','stock'],['Purchase Price','cost_price','money'],['Stock Value','stock_value','money'],['Sale Price','sale_price','money']] }
    };

    function reportCell(row,key,kind){
        if(kind==='money')return formatMoney(row[key]);if(kind==='date')return formatDate(row[key]);if(kind==='ledger')return `<button class="row-button report-ledger" data-contact-id="${row[key]}">Ledger</button>`;if(kind==='receive')return `<button class="row-button report-payment" data-contact-id="${row[key]}" data-contact-type="customer">Receive</button>`;if(kind==='payment')return `<button class="row-button report-payment" data-contact-id="${row[key]}" data-contact-type="supplier">Pay</button>`;if(kind==='view')return '<button class="row-button">View</button>';return esc(row[key]??'-');
    }

    async function renderReport(page,fromValue='',toValue='') {
        loading();const config=reportMap[page]||reportMap['business-report'];const defaultFrom=config.daily?today():`${today().slice(0,8)}01`;const result=await api('report',{params:{type:config.type,from:fromValue||defaultFrom,to:toValue||today()}});
        const metrics=result.metrics;const net=num(metrics.sales)+num(metrics.service_paid)+num(metrics.warranty_earned)-num(metrics.purchases)-num(metrics.expenses)-num(metrics.damage)-num(metrics.salary)-num(metrics.service_refund);
        const businessAssets=num(metrics.account_balance)+num(metrics.stock_value)+num(metrics.receivable)-num(metrics.payable);
        const businessSummary=config.type==='business'?`<div class="business-overview"><article class="panel panel-pad"><h3>Receivable</h3><div class="summary-item"><span>Receivable Amount</span><strong>${formatMoney(metrics.receivable)}</strong></div><div class="summary-item"><span>Customers</span><strong>${metrics.customer_count}</strong></div></article><article class="panel panel-pad"><h3>Payable</h3><div class="summary-item"><span>Payable Amount</span><strong>${formatMoney(metrics.payable)}</strong></div><div class="summary-item"><span>Suppliers</span><strong>${metrics.supplier_count}</strong></div></article><article class="panel panel-pad"><h3>Investment</h3><div class="summary-item"><span>Investment Balance</span><strong>${formatMoney(metrics.investments)}</strong></div><div class="summary-item"><span>Investors</span><strong>${metrics.investor_count}</strong></div></article><article class="panel panel-pad"><h3>Stock</h3><div class="summary-item"><span>Stock Value</span><strong>${formatMoney(metrics.stock_value)}</strong></div><div class="summary-item"><span>Products</span><strong>${metrics.product_count}</strong></div></article><article class="panel panel-pad"><h3>Business Asset</h3><div class="summary-item"><span>Account Balance</span><strong>${formatMoney(metrics.account_balance)}</strong></div><div class="summary-item"><span>Business Asset</span><strong>${formatMoney(businessAssets)}</strong></div></article></div><h2 class="section-title">Business Summary</h2><div class="summary-matrix">${[['Total Sales',metrics.sales],['Paid Sales',metrics.sales_paid],['Due Sales',metrics.sales_due],['Sales Profit',metrics.sales_profit],['Other Sales Earned',metrics.sales_other],['Total VAT',metrics.sales_vat],['Total Purchases',metrics.purchases],['Paid Purchases',metrics.purchases_paid],['Due Purchases',metrics.purchases_due],['Total Damage',metrics.damage],['Purchase Other Cost',metrics.purchase_other],['Expense',metrics.expenses],['Salary',metrics.salary],['Warranty Cost',metrics.warranty_cost],['Sale Return',metrics.sale_returns],['Service Refunded',metrics.service_refund],['Warranty Earned',metrics.warranty_earned],['Service',metrics.service_total],['Service Paid',metrics.service_paid],['Net Profit',net]].map(([label,value])=>`<div><small>${label}</small><strong>${formatMoney(value)}</strong></div>`).join('')}</div>`:'';
        content().innerHTML=`<div class="page-enter">${pageHeader(config.title,'Filterable business performance and account insight.','Report','<button class="button button-secondary" id="print-report">Print Report</button>')}<div class="filter-bar"><span class="filter-icon">RP</span><select id="report-period"><option>Custom</option><option>Today</option><option>This Week</option><option>This Month</option><option>Last 30 Days</option></select><input id="report-from" type="date" value="${result.from}"><input id="report-to" type="date" value="${result.to}"><button id="run-report" class="button button-primary">Apply Filter</button><span class="filter-spacer"></span></div>${businessSummary}<div class="report-metrics"><div class="report-metric"><small>Sales</small><strong class="positive">${formatMoney(metrics.sales)}</strong></div><div class="report-metric"><small>Purchases</small><strong>${formatMoney(metrics.purchases)}</strong></div><div class="report-metric"><small>Expenses</small><strong class="negative">${formatMoney(metrics.expenses)}</strong></div><div class="report-metric"><small>Net Profit</small><strong class="${net<0?'negative':'positive'}">${formatMoney(net)}</strong></div></div><section class="panel table-panel"><div class="table-toolbar"><div><h2>${esc(config.title)}</h2><p>${result.data.length} rows from ${formatDate(result.from)} to ${formatDate(result.to)}</p></div><input id="report-search" class="table-search" placeholder="Search report..."></div><div class="table-wrap"><table id="report-table" class="data-table"><thead><tr><th>#</th>${config.columns.map(([label])=>`<th>${esc(label)}</th>`).join('')}</tr></thead><tbody>${result.data.length?result.data.map((row,index)=>`<tr><td>${index+1}</td>${config.columns.map(([,key,kind])=>`<td>${reportCell(row,key,kind)}</td>`).join('')}</tr>`).join(''):emptyRows(config.columns.length+1,'No report data found.')}</tbody></table></div></section></div>`;
        bindSearch('#report-search','#report-table');$('#run-report').addEventListener('click',()=>renderReport(page,$('#report-from').value,$('#report-to').value).catch(showError));$('#print-report').addEventListener('click',()=>window.print());$$('.report-ledger').forEach((button)=>button.addEventListener('click',()=>showContactLedger(button.dataset.contactId).catch((error)=>toast(error.message,'error'))));$$('.report-payment').forEach((button)=>button.addEventListener('click',()=>showContactPayment(button.dataset.contactId,button.dataset.contactType).catch((error)=>toast(error.message,'error'))));
    }

    async function renderSettings() {
        loading();
        if (!state.bootstrap) await refreshBootstrap();
        const item = state.settings;
        if (item.tagline === 'Developed by Swapon Mahmud' || item.tagline === 'Cloud Core POS' || item.tagline === 'Cloudcore Soft' || item.tagline === 'Modern retail operations by Vib Tools' || !item.tagline) item.tagline = 'Retail operations, simplified by Vib Tools.';
        if (/^(https?:\/\/)?cloudcoresoft\.com\/?$/i.test(item.website || '') || item.website === 'https://github.com/vibtools' || !item.website) item.website = 'https://vib.tools/';

        const nav = [
            ['profile', 'Business Profile'],
            ['invoice', 'Invoice Setup'],
            ['pos', 'POS Settings'],
            ['backup', 'Backup'],
        ];

        content().innerHTML = `<div class="page-enter">${pageHeader('Business Setting', '', 'Settings', `<span class="badge ${item.marketplace_status==='approved'?'':'gold'}">${esc(item.marketplace_status||'Active Package')}</span>`)}
            <div class="settings-grid">
                <aside class="panel settings-nav" aria-label="Business settings sections">${nav.map(([key,label], index) => `<button type="button" data-settings-tab="${key}" class="${index===0?'active':''}" aria-selected="${index===0?'true':'false'}">${label}</button>`).join('')}</aside>
                <section class="panel form-panel settings-workspace">
                    <form id="settings-form">
                        <section class="settings-section" data-settings-section="profile">
                            <h2 class="section-title">Business Profile</h2>
                            <div class="form-grid">
                                <div class="form-field full"><label>Software Name</label><input name="business_name" readonly value="${esc(item.business_name)}"></div>
                                <div class="form-field"><label>Contact Number</label><input name="phone" value="${esc(item.phone || '')}"></div>
                                <div class="form-field"><label>Address</label><input name="address" value="${esc(item.address || '')}"></div>
                                <div class="form-field"><label>Currency</label><input name="currency" value="${esc(item.currency || 'BDT')}"></div>
                                <div class="form-field"><label>VAT Percentage</label><input name="vat_percentage" type="number" min="0" step=".01" value="${esc(item.vat_percentage||0)}"></div>
                                <div class="form-field"><label>TIN Number</label><input name="tin_number" value="${esc(item.tin_number||'')}"></div>
                                <div class="form-field"><label>Tag Line</label><input name="tagline" value="${esc(item.tagline||'')}"></div>
                                <div class="form-field"><label>Email</label><input name="email" type="email" value="${esc(item.email || '')}"></div>
                                <div class="form-field"><label>Website</label><input name="website" value="${esc(item.website||'')}"></div>
                                <div class="form-field full"><label>Upload Your Logo</label><div class="upload-placeholder settings-logo-upload"></div></div>
                            </div>
                        </section>

                        <section class="settings-section" data-settings-section="invoice" hidden>
                            <h2 class="section-title">Invoice Setup</h2>
                            <div class="form-grid three">
                                <div class="form-field"><label>Sale Invoice Prefix</label><input name="invoice_prefix" value="${esc(item.invoice_prefix || 'INV')}"></div>
                                <div class="form-field"><label>Purchase Prefix</label><input name="purchase_prefix" value="${esc(item.purchase_prefix || 'PUR')}"></div>
                                <div class="form-field"><label>Default Invoice</label><select name="default_invoice"><option ${item.default_invoice==='Invoice 1'?'selected':''}>Invoice 1</option><option ${item.default_invoice==='Invoice 2'?'selected':''}>Invoice 2</option><option ${item.default_invoice==='Invoice 3'?'selected':''}>Invoice 3</option></select></div>
                                <div class="form-field full"><label>Invoice Note</label><input name="invoice_note" value="${esc(item.invoice_note || '')}"></div>
                                <div class="form-field full"><label>Invoice Footer</label><input name="invoice_footer" value="${esc(item.invoice_footer||'')}"></div>
                            </div>
                        </section>

                        <section class="settings-section" data-settings-section="pos" hidden>
                            <h2 class="section-title">POS Settings</h2>
                            <div class="form-grid three">
                                <div class="form-field"><label>Low Stock Alert</label><input name="low_stock_alert" type="number" min="0" value="${esc(item.low_stock_alert || 5)}"></div>
                                <div class="form-field"><label>POS Printer Size</label><select name="printer_size"><option ${item.printer_size==='80mm'?'selected':''}>80mm</option><option ${item.printer_size==='58mm'?'selected':''}>58mm</option><option ${item.printer_size==='A4'?'selected':''}>A4</option></select></div>
                                <div class="form-field full"><div class="toggle-row settings-toggle-grid"><label><input name="sms_invoice" type="checkbox" ${Number(item.sms_invoice)?'checked':''}> SMS Invoice</label><label><input name="product_code" type="checkbox" ${Number(item.product_code)?'checked':''}> Product Code</label><label><input name="vat_on_product" type="checkbox" ${Number(item.vat_on_product)?'checked':''}> VAT On Product</label></div></div>
                            </div>
                        </section>

                        <section class="settings-section" data-settings-section="backup" hidden>
                            <h2 class="section-title">Backup</h2>
                            <div class="settings-backup-card"><div><strong>Download Browser Backup</strong><p>Export the supported business data snapshot for safekeeping. Server-side backup/restore tooling remains available through the secured maintenance workflow.</p></div><button type="button" id="backup-download" class="button button-secondary">Download Backup</button></div>
                        </section>

                        <div class="form-actions settings-save-actions"><button class="button button-primary">Update Settings</button></div>
                    </form>
                </section>
            </div>
        </div>`;

        const activateSettingsTab = (key) => {
            $$('[data-settings-tab]', content()).forEach((button) => {
                const active = button.dataset.settingsTab === key;
                button.classList.toggle('active', active);
                button.setAttribute('aria-selected', active ? 'true' : 'false');
            });
            $$('[data-settings-section]', content()).forEach((section) => { section.hidden = section.dataset.settingsSection !== key; });
            $('.settings-save-actions', content()).hidden = key === 'backup';
        };
        $$('[data-settings-tab]', content()).forEach((button) => button.addEventListener('click', () => activateSettingsTab(button.dataset.settingsTab)));

        const logoUpload = $('.settings-logo-upload', content());
        logoUpload.classList.add('image-upload');
        logoUpload.innerHTML = `${item.logo_data ? `<img src="${esc(item.logo_data)}" alt="Business logo">` : '<span>Choose business logo</span>'}<small>PNG, JPG, WEBP or BMP, maximum 3 MB</small><input id="settings-logo" type="file" accept="image/png,image/jpeg,image/webp,image/bmp">`;
        $('#settings-logo').addEventListener('change', async () => { try { const logo = await fileToDataUrl($('#settings-logo')); if (logo) logoUpload.querySelector('img,span')?.replaceWith(Object.assign(document.createElement('img'), { src: logo, alt: 'Business logo preview' })); } catch (error) { toast(apiErrorMessage(error), 'error'); $('#settings-logo').value = ''; } });
        $('#settings-form').addEventListener('submit', async (event) => { event.preventDefault(); try { const body = serializeForm(event.currentTarget); body.logo_data = await fileToDataUrl($('#settings-logo')); const response = await api('settings_save', { body }); toast(response.message); await refreshBootstrap(); document.title = `${state.settings.business_name} | VibRetail`; } catch (error) { toast(apiErrorMessage(error), 'error'); } });
        $('#backup-download').addEventListener('click',async()=>{try{const backup=await api('backup',{body:{}});const blob=new Blob([JSON.stringify(backup.data,null,2)],{type:'application/json'});const link=document.createElement('a');link.href=URL.createObjectURL(blob);link.download=`vibretail-backup-${today()}.json`;link.click();URL.revokeObjectURL(link.href);toast('Backup downloaded.');}catch(error){toast(apiErrorMessage(error),'error');}});
    }

    async function renderAbout() {
        const whatsappIcon = `<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"></circle><path d="M8.4 7.7c.4-.4.8-.3 1 .1l1 2c.2.4.1.7-.2 1l-.7.7c.8 1.7 2.1 3 3.8 3.8l.7-.7c.3-.3.6-.4 1-.2l2 1c.4.2.5.6.1 1-1 1.1-2.3 1.4-3.9.8-3.1-1.1-5.3-3.3-6.4-6.4-.6-1.6-.3-2.9.8-3.9z"></path></svg>`;
        const companyWebsite = config.companyWebsite || 'https://vib.tools/';
        const companyContact = config.companyContact || 'https://vib.tools/contact';
        const companyLogo = config.companyLogo || 'https://vibtools.github.io/vibtools-brand-assets/logos/icon-512.png';
        const companyGithub = config.companyGithub || 'https://github.com/vibtools';
        const companyFacebook = config.companyFacebook || 'https://www.facebook.com/vib.tools';
        const companyX = config.companyX || 'https://x.com/vibtools';
        const companyInstagram = config.companyInstagram || 'https://www.instagram.com/vib.tools';
        const companyReddit = config.companyReddit || 'https://www.reddit.com/user/VibTools/';
        const companyEmail = config.companyEmail || 'hello@vib.tools';
        const supportEmail = config.companySupportEmail || 'support@vib.tools';
        const whatsappNumber = config.companyWhatsappNumber || '+880 1795-470603';
        const whatsappUrl = config.companyWhatsappUrl || 'https://wa.me/8801795470603';

        content().innerHTML = `<div class="page-enter about-page">${pageHeader('About VibRetail', '', 'About')}
            <section class="about-hero panel"><div class="about-brand-logo"><img src="${esc(companyLogo)}" alt="Vib Tools logo"></div><div><span class="eyebrow">VIB TOOLS</span><h2>Retail operations, simplified.</h2><p>VibRetail is a compact retail operations suite that brings sales, purchasing, inventory, customers, suppliers, service workflows, finance, HRM and reporting into one focused workspace for day-to-day retail operations.</p><div class="about-actions"><a class="button button-primary" href="${esc(companyWebsite)}" target="_blank" rel="noopener">Visit Vib Tools</a><a class="button button-secondary" href="${esc(companyContact)}" target="_blank" rel="noopener">Contact</a><a class="button button-secondary" href="${esc(companyGithub)}" target="_blank" rel="noopener">GitHub</a></div></div></section>
            <div class="about-grid"><article class="panel panel-pad"><span class="about-icon">OP</span><h3>Operational by design</h3><p>Fast, information-dense workflows for daily retail operations without unnecessary visual noise.</p></article><article class="panel panel-pad"><span class="about-icon">ST</span><h3>Stock-aware</h3><p>Products, serials, purchases, sales, returns, transfers, damage and low-stock visibility stay connected.</p></article><article class="panel panel-pad"><span class="about-icon">FN</span><h3>Finance connected</h3><p>Payments, bank accounts, expenses, receivables, payables, EMI and reporting work from the same operational data.</p></article><article class="panel panel-pad"><span class="about-icon">SV</span><h3>Service ready</h3><p>Warranty, RMA and repair/service workflows stay inside the same retail operations environment.</p></article></div>
            <section class="panel panel-pad about-company"><div><span class="eyebrow">ABOUT VIB TOOLS</span><h2>Open tools for focused teams.</h2><p>Vib Tools builds practical software products and is an open-source developer ecosystem focused on simplifying operational complexity. Its products span deployment automation, identity, developer infrastructure and software tooling; VibRetail applies the same emphasis on ownership, predictable workflows and maintainable software to retail operations.</p></div><div class="about-meta"><div><span>Product</span><strong>VibRetail</strong></div><div><span>Developer</span><strong>Vib Tools</strong></div><div><span>Category</span><strong>Retail Operations</strong></div><div><span>License</span><strong>See repository LICENSE</strong></div></div></section>
            <section class="panel panel-pad about-contact"><div class="about-contact-copy"><span class="eyebrow">CONTACT & COMMUNITY</span><h2>Connect with Vib Tools.</h2><p>Product questions, ecosystem support, collaboration and community links are available through the official Vib Tools channels.</p></div><div class="about-contact-grid"><a href="${esc(companyContact)}" target="_blank" rel="noopener"><span>Contact</span><strong>vib.tools/contact</strong></a><a href="${esc(whatsappUrl)}" target="_blank" rel="noopener" class="about-whatsapp">${whatsappIcon}<span><small>WhatsApp</small><strong>${esc(whatsappNumber)}</strong></span></a><a href="mailto:${esc(companyEmail)}"><span>Email</span><strong>${esc(companyEmail)}</strong></a><a href="mailto:${esc(supportEmail)}"><span>Support</span><strong>${esc(supportEmail)}</strong></a></div><div class="about-socials"><a href="${esc(companyGithub)}" target="_blank" rel="noopener">GitHub</a><a href="${esc(companyX)}" target="_blank" rel="noopener">X</a><a href="${esc(companyFacebook)}" target="_blank" rel="noopener">Facebook</a><a href="${esc(companyInstagram)}" target="_blank" rel="noopener">Instagram</a><a href="${esc(companyReddit)}" target="_blank" rel="noopener">Reddit</a><a href="${esc(companyWebsite)}" target="_blank" rel="noopener">Website</a></div></section>
        </div>`;
    }

    async function renderAdmin() {
        loading(); if (!state.bootstrap) await refreshBootstrap(); const result = await api('admin_data');
        content().innerHTML = `<div class="page-enter">${pageHeader('Admin', 'Manage system users and review recent activity.', 'Administration')}<div class="two-column"><section class="panel form-panel"><h2 class="section-title">Create User</h2><form id="user-form"><div class="form-grid"><div class="form-field"><label>Name</label><input name="name" required></div><div class="form-field"><label>Phone</label><input name="phone" required></div><div class="form-field"><label>Role</label><select name="role">${optionRows(state.bootstrap.roles,'name','','Select Role')}</select></div><div class="form-field"><label>Temporary Password</label><input name="password" type="password" minlength="12" maxlength="128" required><small>12+ characters with uppercase, lowercase, number and symbol. User must change it after sign-in.</small></div></div><div class="form-actions"><button class="button button-primary">Create User</button></div></form><h2 class="section-title" style="margin-top:26px">System Users</h2><div class="table-wrap"><table class="data-table"><thead><tr><th>Name</th><th>Phone</th><th>Role</th><th>Status</th></tr></thead><tbody>${result.users.map((row) => `<tr><td><strong>${esc(row.name)}</strong></td><td>${esc(row.phone)}</td><td>${esc(row.role)}</td><td>${badge(Number(row.status) ? 'Active' : 'Inactive')}</td></tr>`).join('')}</tbody></table></div></section><aside class="panel panel-pad"><div class="panel-title"><span>LG</span><div><h3>Recent Activity</h3></div></div><div class="activity-list">${result.activity.length ? result.activity.map((row) => `<div class="activity-item"><span>EV</span><p><strong>${esc(row.action)}</strong><br><small>${esc(row.details || row.user_name || '')}</small></p><small>${formatDate(String(row.created_at).slice(0, 10))}</small></div>`).join('') : '<p class="muted">No activity yet.</p>'}</div></aside></div></div>`;
        $('#user-form').addEventListener('submit', async (event) => { event.preventDefault(); try { const response = await api('user_save', { body: serializeForm(event.currentTarget) }); toast(response.message); await renderAdmin(); } catch (error) { toast(apiErrorMessage(error), 'error'); } });
    }

    const routes = {
        dashboard: () => renderDashboard(), customer: () => renderContacts('customer'), supplier: () => renderContacts('supplier'),
        'product-new': renderProductNew, 'product-list': () => renderProductList(), brand: () => renderLookup('brand'), category: () => renderLookup('category'), subcategory: () => renderLookup('subcategory'), unit: () => renderLookup('unit'),
        'purchase-new': () => renderTransaction('purchase'), 'purchase-list': () => renderInvoiceList('purchase'), 'purchase-return': () => renderInvoiceList('purchase', true),
        'sale-new': () => renderTransaction('sale'), 'sale-vat': () => renderTransaction('sale', true), 'sale-list': () => renderInvoiceList('sale'), 'sale-return': () => renderInvoiceList('sale', true),
        'serial-list': renderSerialList, rma: renderRma,
        'service-new': () => renderService(true), 'service-list': () => renderService(false), 'service-report': renderServiceReport,
        'quotation-new': () => renderQuotation(true), 'quotation-list': () => renderQuotation(false), damage: () => renderDamage(false), 'damage-list': () => renderDamage(true),
        expense: () => renderExpense(false), 'expense-type': () => renderLookup('expense_type'), 'expense-report': () => renderExpense(true),
        barcode: () => renderBarcode(false), 'barcode-single': () => renderBarcode(true), bank: renderBank, transfer: renderTransfer, cheque: () => renderCheque(false), 'cheque-new': () => renderCheque(true), transactions: renderTransactions,
        investor: renderInvestor, 'emi-new': () => renderEmi(true), 'emi-list': () => renderEmi(false), 'installment-report': () => renderInstallmentReport('due'),
        team: () => renderTeam(false), 'sr-list': () => renderTeam(true), attendance: renderAttendance, role: renderRole,
        admin: renderAdmin, settings: renderSettings, about: renderAbout, profile:renderProfile, marketplace:renderMarketplace, 'payment-center':renderPaymentCenter, 'buy-sms':renderBuySms
    };

    Object.keys(reportMap).forEach((page) => { routes[page] = () => renderReport(page); });

    function pageUrl(page) {
        return `${page}.php`;
    }

    async function navigate(page, openPhysicalPage = true) {
        page = routes[page] ? page : 'dashboard';
        const physicalPage = config.initialPage || 'dashboard';
        if (openPhysicalPage && config.multiPage && page !== physicalPage) {
            location.href = pageUrl(page);
            return;
        }
        state.currentPage = page;
        $$('.nav-link, .nav-children a').forEach((link) => link.classList.toggle('active', link.dataset.page === page));
        const activeChild = $(`.nav-children a[data-page="${page}"]`);
        if (activeChild) activeChild.closest('.nav-group')?.classList.add('open');
        closeMobileSidebar();
        try { await routes[page](); finalizePageUi(content()); const heading = $('h1', content()); if (heading) document.title = `${heading.textContent} | ${state.settings.business_name}`; content().focus({ preventScroll: true }); } catch (error) { showError(error); }
    }

    function closeMobileSidebar() {
        $('#sidebar')?.classList.remove('open');
        $('#sidebar-scrim')?.classList.remove('open');
    }

    function setupApp() {
        $$('a[data-page]').forEach((link) => { link.href = pageUrl(link.dataset.page); });
        $$('.nav-parent').forEach((button) => button.addEventListener('click', () => button.closest('.nav-group').classList.toggle('open')));
        document.addEventListener('click', (event) => {
            const pageLink = event.target.closest('[data-page]');
            if (pageLink) { event.preventDefault(); navigate(pageLink.dataset.page); }
        });
        $('#menu-toggle')?.addEventListener('click', () => { $('#sidebar').classList.add('open'); $('#sidebar-scrim').classList.add('open'); });
        $('#sidebar-close')?.addEventListener('click', closeMobileSidebar);
        $('#sidebar-scrim')?.addEventListener('click', closeMobileSidebar);
        $('#quick-add')?.addEventListener('click', (event) => { event.stopPropagation(); $('#quick-menu').classList.toggle('open'); $('#profile-menu').classList.remove('open'); });
        $('#profile-button')?.addEventListener('click', (event) => { event.stopPropagation(); $('#profile-menu').classList.toggle('open'); $('#quick-menu').classList.remove('open'); });
        document.addEventListener('click', () => { $('#quick-menu')?.classList.remove('open'); $('#profile-menu')?.classList.remove('open'); });
        $('#logout-button')?.addEventListener('click', async () => { try { await api('logout', { body: {} }); location.href = 'index.php'; } catch (error) { toast(apiErrorMessage(error), 'error'); } });
        const uiObserver = new MutationObserver(scheduleFinalizePageUi);
        if (content()) uiObserver.observe(content(), { childList: true, subtree: true });
        if ($('#modal-root')) uiObserver.observe($('#modal-root'), { childList: true, subtree: true });
        const legacyHashPage = location.hash.slice(1);
        const initialPage = routes[legacyHashPage] ? legacyHashPage : (config.initialPage || 'dashboard');
        refreshBootstrap().then(() => navigate(initialPage, false)).catch(showError);
    }

    function setupLogin() {
        const form = $('#login-form');
        $('#toggle-password')?.addEventListener('click', () => { const input = form.elements.password; input.type = input.type === 'password' ? 'text' : 'password'; $('#toggle-password').textContent = input.type === 'password' ? 'Show' : 'Hide'; });
        form?.addEventListener('submit', async (event) => {
            event.preventDefault();
            const button = $('button[type="submit"]', form); const message = $('#login-message');
            button.disabled = true; button.textContent = 'Signing in...'; message.textContent = '';
            try { const result = await api('login', { body: serializeForm(form) }); location.href = result.password_change_required ? 'profile.php?password=required' : (config.loginRedirect || 'dashboard.php'); } catch (error) { message.textContent = apiErrorMessage(error); button.disabled = false; button.textContent = 'Sign in'; }
        });
    }

    config.loggedIn ? setupApp() : setupLogin();
})();
