(() => {
  const DEFAULT_DAY_SERIES_STEP = 30;

  initConfirmForms();
  initPoolSorting();
  initPoolDetailsToggle();
  initPoolFeesCharts();
  initFeesSteppers();
  initPoolCompoundForms();
  initMarketRefresh();
  initMarketFilter();
  initMarketAutoAdd();
  initTransactionsTab();
  initSettingsTabs();
  initSortableTables();
  initDashboardTokenCharts();
  initDashboardMonthlyModal();
  initDashboardTargetsForm();
  initNexoRefreshLogs();

  function initConfirmForms() {
    document.querySelectorAll('form[data-confirm]').forEach((form) => {
      form.addEventListener('submit', (e) => {
        const msg = form.getAttribute('data-confirm') || 'Tens a certeza?';
        if (!window.confirm(msg)) {
          e.preventDefault();
        }
      });
    });
  }

  function initPoolSorting() {
    const tbody = document.getElementById('sortablePools');
    const form = document.getElementById('poolOrderForm');
    const hidden = document.getElementById('poolOrderJson');
    if (!tbody || !form || !hidden) return;

    let dragged = null;
    let orderChanged = false;

    tbody.querySelectorAll('tr[data-pool-row="1"]').forEach((row) => {
      const handle = row.querySelector('.ord-cell');
      let dragArmed = false;
      if (handle) {
        handle.addEventListener('pointerdown', () => {
          dragArmed = true;
          row.setAttribute('draggable', 'true');
        });
        handle.addEventListener('pointerup', () => {
          dragArmed = false;
          row.setAttribute('draggable', 'false');
        });
        handle.addEventListener('pointercancel', () => {
          dragArmed = false;
          row.setAttribute('draggable', 'false');
        });
      }

      row.addEventListener('dragstart', (e) => {
        if (!dragArmed) {
          e.preventDefault();
          row.setAttribute('draggable', 'false');
          return;
        }
        dragArmed = false;
        dragged = row;
        row.classList.add('dragging');
      });

      row.addEventListener('dragend', () => {
        row.classList.remove('dragging');
        row.setAttribute('draggable', 'false');
        dragged = null;
        renumberRows(tbody);
        if (orderChanged) {
          savePoolOrder(form, hidden, tbody);
          orderChanged = false;
        }
      });

      row.addEventListener('dragover', (e) => e.preventDefault());

      row.addEventListener('drop', (e) => {
        e.preventDefault();
        if (!dragged || dragged === row) return;

        const rows = [...tbody.querySelectorAll('tr[data-pool-row="1"]')];
        const draggedIndex = rows.indexOf(dragged);
        const targetIndex = rows.indexOf(row);

        if (draggedIndex < targetIndex) {
          movePoolRowGroup(dragged, row, true);
          orderChanged = true;
        } else if (draggedIndex > targetIndex) {
          movePoolRowGroup(dragged, row, false);
          orderChanged = true;
        }
      });
    });
  }

  function renumberRows(tbody) {
    [...tbody.querySelectorAll('tr[data-pool-row="1"]')].forEach((row, idx) => {
      const cell = row.querySelector('.ord-cell');
      if (cell) cell.textContent = String(idx + 1);
    });
  }

  function savePoolOrder(form, hidden, tbody) {
    const orderedIds = [...tbody.querySelectorAll('tr[data-pool-row="1"]')]
      .map((row) => row.dataset.poolId)
      .filter(Boolean);
    hidden.value = JSON.stringify(orderedIds);
    form.requestSubmit();
  }

  function movePoolRowGroup(draggedRow, targetRow, insertAfter) {
    const draggedExtra = draggedRow.nextElementSibling?.classList.contains('pool-extra-row')
      ? draggedRow.nextElementSibling
      : null;
    const targetExtra = targetRow.nextElementSibling?.classList.contains('pool-extra-row')
      ? targetRow.nextElementSibling
      : null;

    const fragment = document.createDocumentFragment();
    fragment.appendChild(draggedRow);
    if (draggedExtra) fragment.appendChild(draggedExtra);

    if (insertAfter) {
      const anchor = targetExtra || targetRow;
      anchor.after(fragment);
    } else {
      targetRow.before(fragment);
    }
  }

  function initPoolDetailsToggle() {
    document.querySelectorAll('[data-toggle-details]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const id = btn.getAttribute('data-toggle-details');
        if (!id) return;
        const row = document.getElementById(id);
        if (!row) return;
        const opening = row.hasAttribute('hidden');
        if (opening) {
          row.removeAttribute('hidden');
          btn.textContent = 'Fechar';
          const container = row.querySelector('.fees-history');
          if (container) {
            const step = getDaySeriesStep(container);
            container.dataset.feesPeriod = 'day';
            container.dataset.feesVisible = String(step);
            renderFeesHistory(container, 'day');
          }
        } else {
          row.setAttribute('hidden', '');
          btn.textContent = 'Ver tudo';
        }
      });
    });
  }

  function initPoolFeesCharts() {
    document.querySelectorAll('.fees-history').forEach((container) => {
      const step = getDaySeriesStep(container);
      container.dataset.feesPeriod = 'day';
      container.dataset.feesVisible = String(step);

      container.querySelectorAll('[data-fees-period]').forEach((btn) => {
        btn.addEventListener('click', () => {
          const period = btn.getAttribute('data-fees-period') || 'day';
          container.querySelectorAll('[data-fees-period]').forEach((b) => b.classList.remove('active'));
          btn.classList.add('active');
          container.dataset.feesPeriod = period;
          if (period === 'day') {
            container.dataset.feesVisible = String(getDaySeriesStep(container));
          }
          renderFeesHistory(container, period);
        });
      });

      const loadMoreBtn = container.querySelector('[data-fees-load-more]');
      if (loadMoreBtn) {
        loadMoreBtn.addEventListener('click', () => {
          const step = getDaySeriesStep(container);
          const currentVisible = Math.max(Number(container.dataset.feesVisible || step), step);
          container.dataset.feesVisible = String(currentVisible + step);
          renderFeesHistory(container, container.dataset.feesPeriod || 'day');
        });
      }

      renderFeesHistory(container, 'day');
    });
  }

  function renderFeesHistory(container, period) {
    const barsHost = container.querySelector('[data-fees-bars]');
    if (!barsHost) return;

    const parsed = readFeesSeries(container);
    const allSeries = aggregateFeeSeries(parsed, period);
    if (!allSeries.length) {
      barsHost.innerHTML = '<p class="muted">No records.</p>';
      updateFeesMoreControls(container, period, 0, 0);
      return;
    }

    let series = allSeries;
    if (period === 'day') {
      const step = getDaySeriesStep(container);
      const visible = Math.max(Number(container.dataset.feesVisible || step), step);
      series = allSeries.slice(-visible);
    }

    const maxAbsValue = Math.max(...series.map((r) => Math.abs(Number(r.value) || 0)), 1);
    const html = series.map((point) => {
      const v = Number(point.value) || 0;
      const absRatio = Math.abs(v) / maxAbsValue;
      const h = v === 0 ? 0 : Math.max(absRatio * 48, 2);
      const top = v < 0 ? 50 : 50 - h;
      const kindClass = v < 0 ? 'kind-negative' : 'kind-generated';
      return `
        <div class="fees-bar-col" title="${escapeHtml(point.label)} | $${v.toFixed(2)}">
          <div class="fees-bar-value">$${v.toFixed(2)}</div>
          <div class="fees-bar-track">
            <span class="fees-bar-zero"></span>
            <div class="fees-bar-fill ${kindClass}" style="top:${top.toFixed(2)}%;height:${h.toFixed(2)}%"></div>
          </div>
          <div class="fees-bar-label">${escapeHtml(point.label)}</div>
        </div>
      `;
    }).join('');

    barsHost.innerHTML = html;
    updateFeesMoreControls(container, period, series.length, allSeries.length);
  }

  function updateFeesMoreControls(container, period, shownCount, totalCount) {
    const wrap = container.querySelector('[data-fees-more-wrap]');
    const loadMoreBtn = container.querySelector('[data-fees-load-more]');
    const count = container.querySelector('[data-fees-count]');
    if (!wrap || !loadMoreBtn || !count) return;

    if (period !== 'day') {
      wrap.setAttribute('hidden', '');
      return;
    }

    wrap.removeAttribute('hidden');
    const hasMore = shownCount < totalCount;
    loadMoreBtn.disabled = !hasMore;
    count.textContent = `${shownCount}/${totalCount} dias`;
  }

  function readFeesSeries(container) {
    const b64 = container.getAttribute('data-fees-series-b64') || '';
    if (b64) {
      try {
        const decoded = atob(b64);
        const data = JSON.parse(decoded);
        if (Array.isArray(data)) return data;
      } catch {
        // Fallback para atributo legacy abaixo.
      }
    }

    try {
      const legacy = JSON.parse(container.getAttribute('data-fees-series') || '[]');
      return Array.isArray(legacy) ? legacy : [];
    } catch {
      return [];
    }
  }

  function getDaySeriesStep(container) {
    const parsed = Number(container?.dataset?.feesStep || DEFAULT_DAY_SERIES_STEP);
    if (!Number.isFinite(parsed) || parsed <= 0) return DEFAULT_DAY_SERIES_STEP;
    return Math.floor(parsed);
  }

  function aggregateFeeSeries(rows, period) {
    const clean = rows
      .map((r) => ({
        date: String(r.date || ''),
        value: Number(r.value) || 0,
      }))
      .filter((r) => r.date);
    if (!clean.length) return [];

    if (period === 'day') {
      return clean.map((r) => ({
        label: formatDayMonth(r.date),
        value: r.value,
      }));
    }

    const grouped = new Map();
    clean.forEach((r) => {
      const key = period === 'year' ? r.date.slice(0, 4) : r.date.slice(0, 7);
      grouped.set(key, (grouped.get(key) || 0) + r.value);
    });

    const out = [...grouped.entries()]
      .sort((a, b) => a[0].localeCompare(b[0]))
      .map(([label, value]) => ({ label, value }));
    if (period === 'year') return out.slice(-6).map((r) => ({ label: r.label, value: r.value }));
    return out.slice(-12).map((r) => ({ label: formatMonthYear(r.label), value: r.value }));
  }

  function escapeHtml(value) {
    return String(value)
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;')
      .replaceAll("'", '&#39;');
  }

  function formatDayMonth(isoDate) {
    const y = String(isoDate || '');
    if (y.length < 10) return y;
    const day = y.slice(8, 10);
    const month = y.slice(5, 7);
    return `${day}-${month}`;
  }

  function formatMonthYear(isoMonth) {
    const y = String(isoMonth || '');
    if (y.length < 7) return y;
    const month = y.slice(5, 7);
    const year = y.slice(0, 4);
    return `${month}-${year}`;
  }

  function initMarketRefresh() {
    const refreshBtn = document.getElementById('refreshMarketBtn');
    const form = document.getElementById('marketRefreshForm');
    const hidden = document.getElementById('marketPricesJson');
    const host = document.querySelector('[data-coingecko-map]');
    if (!refreshBtn || !form || !hidden || !host) return;

    refreshBtn.addEventListener('click', async () => {
      let map = {};
      try {
        map = JSON.parse(host.getAttribute('data-coingecko-map') || '{}');
      } catch {
        map = {};
      }
      const entries = Object.entries(map);
      if (!entries.length) {
        alert('No mapped tokens to update.');
        return;
      }

      refreshBtn.disabled = true;
      refreshBtn.textContent = 'A atualizar...';

      try {
        const ids = [...new Set(entries.map(([, id]) => id))].join(',');
        const url = `https://api.coingecko.com/api/v3/simple/price?ids=${encodeURIComponent(ids)}&vs_currencies=usd`;
        const res = await fetch(url);
        if (!res.ok) throw new Error('Falha no pedido');

        const data = await res.json();
        const out = {};
        for (const [symbol, id] of entries) {
          const price = data?.[id]?.usd;
          if (typeof price === 'number') out[symbol] = price;
        }

        hidden.value = JSON.stringify(out);
        if (typeof form.requestSubmit === 'function') {
          form.requestSubmit();
        } else {
          form.submit();
        }
      } catch (err) {
        alert(`Error updating market: ${err?.message || 'unknown'}`);
      } finally {
        refreshBtn.disabled = false;
        refreshBtn.textContent = 'Refresh from market';
      }
    });
  }

  function initMarketFilter() {
    const input = document.getElementById('marketFilterInput');
    const table = document.querySelector('.market-table');
    if (!input || !table) return;

    const rows = [...table.querySelectorAll('tbody tr')];
    const apply = () => {
      const q = String(input.value || '').trim().toLowerCase();
      rows.forEach((row) => {
        const token = String(row.getAttribute('data-market-token') || '').toLowerCase();
        if (!q) {
          row.style.display = '';
          return;
        }
        row.style.display = token.includes(q) ? '' : 'none';
      });
    };

    input.addEventListener('input', apply);
    apply();
  }

  function initMarketAutoAdd() {
    const form = document.querySelector('[data-market-auto-add]');
    const host = document.querySelector('[data-coingecko-map]');
    if (!form || !host) return;

    const tokenInput = form.querySelector('input[name="token"]');
    const idInput = form.querySelector('input[name="coingecko_id"]');
    const priceInput = form.querySelector('input[name="price"]');
    if (!tokenInput || !priceInput) return;

    const getMap = () => {
      try {
        return JSON.parse(host.getAttribute('data-coingecko-map') || '{}');
      } catch {
        return {};
      }
    };

    const setMap = (map) => {
      host.setAttribute('data-coingecko-map', JSON.stringify(map));
    };

    const findIdBySearch = async (symbol) => {
      const url = `https://api.coingecko.com/api/v3/search?query=${encodeURIComponent(symbol)}`;
      const res = await fetch(url);
      if (!res.ok) return '';
      const data = await res.json();
      const coins = Array.isArray(data?.coins) ? data.coins : [];
      const lowered = symbol.toLowerCase();
      const exact = coins.find((c) => String(c?.symbol || '').toLowerCase() === lowered);
      return String((exact || coins[0] || {}).id || '');
    };

    form.addEventListener('submit', async (evt) => {
      if (form.dataset.autoSubmitting === '1') {
        form.dataset.autoSubmitting = '0';
        return;
      }

      evt.preventDefault();

      const tokenRaw = String(tokenInput.value || '').trim();
      if (!tokenRaw) {
        alert('Indica o token.');
        return;
      }
      const token = tokenRaw.toUpperCase();
      let id = String(idInput?.value || '').trim();

      if (!id) {
        const map = getMap();
        id = String(map[token] || '');
      }

      if (!id) {
        try {
          id = await findIdBySearch(token);
        } catch {
          id = '';
        }
      }

      if (!id) {
        alert('Nao foi possivel encontrar o id. Indica o Coingecko ID.');
        return;
      }

      form.classList.add('is-loading');
      try {
        const url = `https://api.coingecko.com/api/v3/simple/price?ids=${encodeURIComponent(id)}&vs_currencies=usd`;
        const res = await fetch(url);
        if (!res.ok) throw new Error('Falha ao obter preco');
        const data = await res.json();
        const price = data?.[id]?.usd;
        if (typeof price !== 'number') throw new Error('Preco invalido');

        priceInput.value = String(price);
        tokenInput.value = token;

        const map = getMap();
        if (!map[token]) {
          map[token] = id;
          setMap(map);
        }

        form.dataset.autoSubmitting = '1';
        if (typeof form.requestSubmit === 'function') {
          form.requestSubmit();
        } else {
          form.submit();
        }
      } catch (err) {
        alert(`Erro ao buscar preco: ${err?.message || 'unknown'}`);
      } finally {
        form.classList.remove('is-loading');
      }
    });
  }

  function initPoolCompoundForms() {
    document.querySelectorAll('[data-compound-form]').forEach((form) => {
      const u1 = form.querySelector('input[name="compound_deposit_1_usd"]');
      const u2 = form.querySelector('input[name="compound_deposit_2_usd"]');
      const totalHost = form.querySelector('[data-compound-total]');
      if (!u1 || !u2 || !totalHost) return;

      const renderTotal = () => {
        const v1 = Number(String(u1.value || '0').replace(',', '.')) || 0;
        const v2 = Number(String(u2.value || '0').replace(',', '.')) || 0;
        totalHost.textContent = `$${(v1 + v2).toFixed(2)}`;
      };

      u1.addEventListener('input', renderTotal);
      u2.addEventListener('input', renderTotal);
      renderTotal();
    });
  }


  function initTransactionsTab() {
    document.querySelectorAll('form[data-tx-form]').forEach((form) => {
      const catalogRaw = form.getAttribute('data-tx-pool-catalog') || '{}';
      let catalog = {};
      try {
        catalog = JSON.parse(catalogRaw);
      } catch {
        catalog = {};
      }

      const poolInput = form.querySelector('[data-tx-pool-id]');
      const walletInput = form.querySelector('[data-tx-wallet]');
      const chainInput = form.querySelector('[data-tx-chain]');
      const asset1Input = form.querySelector('[data-tx-asset1]');
      const asset2Input = form.querySelector('[data-tx-asset2]');
      const walletView = form.querySelector('[data-tx-wallet-view]');
      const chainView = form.querySelector('[data-tx-chain-view]');
      const asset1View = form.querySelector('[data-tx-asset1-view]');
      const asset2View = form.querySelector('[data-tx-asset2-view]');
      const help = form.querySelector('[data-tx-help]');
      if (!poolInput) return;

      const setValue = (input, value) => {
        if (!input) return;
        input.value = value;
      };
      const setText = (host, value) => {
        if (!host) return;
        host.textContent = value || '-';
      };
      const setAll = (row) => {
        const wallet = String(row.wallet || '');
        const chain = String(row.chain || '');
        const asset1 = String(row.asset_1 || '');
        const asset2 = String(row.asset_2 || '');
        setValue(walletInput, wallet);
        setValue(chainInput, chain);
        setValue(asset1Input, asset1);
        setValue(asset2Input, asset2);
        setText(walletView, wallet);
        setText(chainView, chain);
        setText(asset1View, asset1);
        setText(asset2View, asset2);
      };

      const applyByPool = () => {
        const poolId = String(poolInput.value || '').trim().toUpperCase();
        if (!poolId || !catalog[poolId]) {
          setAll({});
          if (help) help.textContent = 'Pool not found. Use an existing Pool ID.';
          return;
        }
        const row = catalog[poolId] || {};
        setAll(row);
        if (help) help.textContent = `Pool ${poolId} loaded automatically.`;
      };

      poolInput.addEventListener('input', applyByPool);
      poolInput.addEventListener('change', applyByPool);
      applyByPool();
    });
  }

  function initSettingsTabs() {
    const host = document.querySelector('[data-settings-tabs]');
    if (!host) return;

    const buttons = [...host.querySelectorAll('[data-settings-tab-target]')];
    const panels = [...document.querySelectorAll('[data-settings-panel]')];
    if (!buttons.length || !panels.length) return;

    const panelMap = new Map();
    panels.forEach((panel) => {
      const key = panel.getAttribute('data-settings-panel') || '';
      if (key) panelMap.set(key, panel);
    });

    const activate = (key, pushUrl = true) => {
      buttons.forEach((btn) => {
        const target = btn.getAttribute('data-settings-tab-target') || '';
        btn.classList.toggle('active', target === key);
      });
      panels.forEach((panel) => {
        const target = panel.getAttribute('data-settings-panel') || '';
        if (target === key) {
          panel.removeAttribute('hidden');
        } else {
          panel.setAttribute('hidden', '');
        }
      });

      if (pushUrl) {
        const url = new URL(window.location.href);
        url.searchParams.set('tab', 'settings');
        url.searchParams.set('settings_tab', key);
        window.history.replaceState({}, '', url);
      }
    };

    buttons.forEach((btn) => {
      btn.addEventListener('click', () => {
        const key = btn.getAttribute('data-settings-tab-target') || '';
        if (!panelMap.has(key)) return;
        activate(key);
      });
    });

    const activeBtn = buttons.find((btn) => btn.classList.contains('active'));
    const initialKey = activeBtn?.getAttribute('data-settings-tab-target') || buttons[0]?.getAttribute('data-settings-tab-target') || 'account';
    if (panelMap.has(initialKey)) {
      activate(initialKey, false);
    }
  }

  function initDashboardTokenCharts() {
    document.querySelectorAll('.token-donut-canvas[data-token-chart]').forEach((canvas) => {
      let rows = [];
      try {
        rows = JSON.parse(canvas.getAttribute('data-token-chart') || '[]');
      } catch {
        rows = [];
      }
      if (!Array.isArray(rows) || rows.length === 0) return;

      const host = canvas.closest('.token-donut-interactive');
      if (!host) return;
      const labelEl = host.querySelector('[data-token-hover-label]');
      const valueEl = host.querySelector('[data-token-hover-value]');
      const rowEls = [...host.querySelectorAll('tr[data-token-idx]')];
      const ctx = canvas.getContext('2d');
      if (!ctx) return;

      const dpr = window.devicePixelRatio || 1;
      const cssWidth = canvas.clientWidth || 260;
      const cssHeight = canvas.clientHeight || 260;
      canvas.width = Math.floor(cssWidth * dpr);
      canvas.height = Math.floor(cssHeight * dpr);
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0);

      const cx = cssWidth / 2;
      const cy = cssHeight / 2;
      const outerR = Math.min(cssWidth, cssHeight) * 0.44;
      const innerR = outerR * 0.54;

      const segments = [];
      let acc = -Math.PI / 2;
      rows.forEach((row, idx) => {
        const pct = Math.max(0, Number(row.pct) || 0);
        const angle = (pct / 100) * Math.PI * 2;
        const start = acc;
        const end = acc + angle;
        segments.push({
          idx,
          start,
          end,
          color: String(row.color || '#60a5fa'),
          token: String(row.token || '-'),
          pct,
          usd: Number(row.usd) || 0,
        });
        acc = end;
      });

      const formatMoney = (v) => `$${v.toFixed(2)}`;
      let activeIdx = -1;

      const paint = () => {
        ctx.clearRect(0, 0, cssWidth, cssHeight);

        segments.forEach((seg) => {
          const isActive = seg.idx === activeIdx;
          const r = isActive ? outerR + 4 : outerR;
          ctx.beginPath();
          ctx.moveTo(cx, cy);
          ctx.arc(cx, cy, r, seg.start, seg.end);
          ctx.closePath();
          ctx.fillStyle = seg.color;
          ctx.globalAlpha = activeIdx === -1 || isActive ? 1 : 0.35;
          ctx.fill();
        });

        ctx.globalAlpha = 1;
        ctx.beginPath();
        ctx.arc(cx, cy, innerR, 0, Math.PI * 2);
        ctx.fillStyle = 'rgba(9,20,31,0.98)';
        ctx.fill();
      };

      const setActive = (idx) => {
        activeIdx = idx;
        rowEls.forEach((rowEl) => {
          const rowIdx = Number(rowEl.getAttribute('data-token-idx') || -1);
          rowEl.classList.toggle('token-row-active', rowIdx === idx);
        });

        if (idx >= 0) {
          const seg = segments[idx];
          if (labelEl) labelEl.textContent = seg.token;
          if (valueEl) valueEl.textContent = `${seg.pct.toFixed(1)}% | ${formatMoney(seg.usd)}`;
        } else {
          if (labelEl) labelEl.textContent = 'Portfolio';
          if (valueEl) valueEl.textContent = 'Passe o rato no grafico';
        }
        paint();
      };

      const hitTest = (evt) => {
        const rect = canvas.getBoundingClientRect();
        const x = evt.clientX - rect.left;
        const y = evt.clientY - rect.top;
        const dx = x - cx;
        const dy = y - cy;
        const d = Math.sqrt(dx * dx + dy * dy);
        if (d < innerR || d > outerR + 8) return -1;

        let a = Math.atan2(dy, dx);
        if (a < -Math.PI / 2) a += Math.PI * 2;
        for (const seg of segments) {
          if (a >= seg.start && a <= seg.end) return seg.idx;
        }
        return -1;
      };

      canvas.addEventListener('mousemove', (evt) => setActive(hitTest(evt)));
      canvas.addEventListener('mouseleave', () => setActive(-1));
      rowEls.forEach((rowEl) => {
        rowEl.addEventListener('mouseenter', () => {
          const idx = Number(rowEl.getAttribute('data-token-idx') || -1);
          setActive(idx);
        });
        rowEl.addEventListener('mouseleave', () => setActive(-1));
      });

      setActive(-1);
    });
  }

  function initDashboardMonthlyModal() {
    const modal = document.getElementById('monthlyDetailsModal');
    if (!modal) return;

    let monthMap = {};
    try {
      monthMap = JSON.parse(modal.getAttribute('data-monthly-details') || '{}');
    } catch {
      monthMap = {};
    }

    const titleEl = modal.querySelector('[data-month-modal-title]');
    const feesEl = modal.querySelector('[data-month-fees]');
    const inflowEl = modal.querySelector('[data-month-inflow]');
    const outflowEl = modal.querySelector('[data-month-outflow]');
    const netEl = modal.querySelector('[data-month-net]');
    const txCountEl = modal.querySelector('[data-month-txcount]');
    const rowsHost = modal.querySelector('[data-month-modal-rows]');
    const closeBtn = modal.querySelector('[data-month-modal-close]');

    const fmtMoney = (v) => `$${(Number(v) || 0).toFixed(2)}`;
    const esc = (v) => String(v || '')
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;')
      .replaceAll("'", '&#39;');

    const renderMonth = (monthKey) => {
      const detail = monthMap?.[monthKey];
      if (!detail) return;
      const summary = detail.summary || {};
      const rows = Array.isArray(detail.rows) ? detail.rows : [];

      if (titleEl) titleEl.textContent = `Monthly details ${monthKey}`;
      if (feesEl) feesEl.textContent = fmtMoney(summary.fees);
      if (inflowEl) inflowEl.textContent = fmtMoney(summary.inflow);
      if (outflowEl) outflowEl.textContent = fmtMoney(summary.outflow);
      if (netEl) {
        netEl.textContent = fmtMoney(summary.net);
        netEl.classList.toggle('error', Number(summary.net) < 0);
        netEl.classList.toggle('ok', Number(summary.net) >= 0);
      }
      if (txCountEl) txCountEl.textContent = String(Number(summary.tx_count) || 0);

      if (!rowsHost) return;
      if (!rows.length) {
        rowsHost.innerHTML = '<tr><td colspan="8" class="empty">No transactions this month.</td></tr>';
        return;
      }
      rowsHost.innerHTML = rows.map((row) => `
        <tr>
          <td>${esc(row.date_label)}</td>
          <td>${esc(row.pool_id)}</td>
          <td>${esc(row.action)}</td>
          <td>${esc(row.pair)}</td>
          <td>${fmtMoney(row.total)}</td>
          <td>${fmtMoney(row.fees)}</td>
          <td>${esc(row.wallet)}</td>
          <td>${esc(row.chain)}</td>
        </tr>
      `).join('');
    };

    document.querySelectorAll('.month-detail-btn[data-month-key]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const monthKey = btn.getAttribute('data-month-key') || '';
        if (!monthKey || !monthMap?.[monthKey]) return;
        renderMonth(monthKey);
        if (typeof modal.showModal === 'function') {
          modal.showModal();
        } else {
          modal.setAttribute('open', '');
        }
      });
    });

    if (closeBtn) {
      closeBtn.addEventListener('click', () => {
        if (typeof modal.close === 'function') {
          modal.close();
        } else {
          modal.removeAttribute('open');
        }
      });
    }
  }

  function initDashboardTargetsForm() {
    const form = document.getElementById('tokenTargetsForm');
    if (!form) return;

    form.addEventListener('submit', (e) => {
      const inputs = [...form.querySelectorAll('input[name^="target_pct["]')];
      if (!inputs.length) return;

      let sum = 0;
      for (const input of inputs) {
        const raw = String(input.value || '').trim();
        if (!/^\d+$/.test(raw)) {
          e.preventDefault();
          alert('Usa apenas numeros inteiros nos alvos.');
          input.focus();
          return;
        }
        const value = Number(raw);
        if (!Number.isInteger(value) || value < 0 || value > 100) {
          e.preventDefault();
          alert('Cada alvo deve ficar entre 0 e 100.');
          input.focus();
          return;
        }
        sum += value;
      }

      if (sum > 100) {
        e.preventDefault();
        alert(`The sum of targets cannot exceed 100% (current: ${sum}%).`);
      }
    });
  }

  function initSortableTables() {
    document.querySelectorAll('table.js-sortable-table').forEach((table) => {
      const tbody = table.tBodies?.[0];
      if (!tbody) return;

      const headerCells = [...table.querySelectorAll('thead th')];
      const sortableHeaders = headerCells
        .map((th, idx) => ({ th, idx, type: (th.getAttribute('data-sort-type') || 'text').toLowerCase() }))
        .filter((col) => col.type === 'number' || col.type === 'text');
      if (!sortableHeaders.length) return;

      let activeCol = -1;
      let activeDir = 'asc';

      const parseNumber = (raw) => {
        const text = String(raw || '').replace(',', '.').replace(/[^0-9.+-]/g, '');
        const value = Number(text);
        return Number.isFinite(value) ? value : 0;
      };

      const readValue = (row, colIdx, type) => {
        const cell = row.cells[colIdx];
        if (!cell) return type === 'number' ? 0 : '';
        const raw = cell.getAttribute('data-sort-value') || cell.textContent || '';
        return type === 'number' ? parseNumber(raw) : String(raw).trim().toLowerCase();
      };

      const sortRows = (colIdx, type, dir) => {
        const rows = [...tbody.rows];
        const emptyRows = rows.filter((r) => r.querySelector('td.empty'));
        const dataRows = rows.filter((r) => !r.querySelector('td.empty'));

        dataRows.sort((a, b) => {
          const av = readValue(a, colIdx, type);
          const bv = readValue(b, colIdx, type);
          let cmp = 0;
          if (type === 'number') {
            cmp = Number(av) - Number(bv);
          } else {
            cmp = String(av).localeCompare(String(bv), undefined, { numeric: true, sensitivity: 'base' });
          }
          return dir === 'asc' ? cmp : -cmp;
        });

        tbody.innerHTML = '';
        dataRows.forEach((row) => tbody.appendChild(row));
        emptyRows.forEach((row) => tbody.appendChild(row));
      };

      const setHeaderState = (colIdx, dir) => {
        headerCells.forEach((th, idx) => {
          th.classList.remove('sort-asc', 'sort-desc', 'sortable');
          if (sortableHeaders.some((col) => col.idx === idx)) th.classList.add('sortable');
          if (idx === colIdx) {
            th.classList.add(dir === 'asc' ? 'sort-asc' : 'sort-desc');
          }
        });
      };

      sortableHeaders.forEach(({ th, idx, type }) => {
        th.addEventListener('click', () => {
          const nextDir = activeCol === idx && activeDir === 'asc' ? 'desc' : 'asc';
          activeCol = idx;
          activeDir = nextDir;
          sortRows(idx, type, nextDir);
          setHeaderState(idx, nextDir);
        });
      });

      const defaultCol = Number(table.getAttribute('data-sort-default-col') || -1);
      const defaultDirRaw = String(table.getAttribute('data-sort-default-dir') || 'asc').toLowerCase();
      const defaultDir = defaultDirRaw === 'desc' ? 'desc' : 'asc';
      const defaultHeader = sortableHeaders.find((col) => col.idx === defaultCol) || sortableHeaders[0];
      if (!defaultHeader) return;

      activeCol = defaultHeader.idx;
      activeDir = defaultDir;
      sortRows(defaultHeader.idx, defaultHeader.type, defaultDir);
      setHeaderState(defaultHeader.idx, defaultDir);
    });
  }

  function initNexoRefreshLogs() {
    const host = document.querySelector('[data-coingecko-map]');
    const form = document.getElementById('nexoRefreshLogsForm');
    const jsonInput = document.getElementById('nexoPriceHistoryJson');
    const startInput = document.getElementById('nexoRefreshStart');
    const endInput = document.getElementById('nexoRefreshEnd');
    const btn = document.getElementById('nexoRefreshLogsBtn');
    if (!host || !form || !jsonInput || !startInput || !endInput || !btn) return;

    let map = {};
    try {
      map = JSON.parse(host.getAttribute('data-coingecko-map') || '{}');
    } catch {
      map = {};
    }
    if (!map.NEXO) {
      map.NEXO = 'nexo';
    }

    const toUnix = (dateStr, endOfDay = false) => {
      const [y, m, d] = String(dateStr).split('-').map((v) => Number(v));
      if (!y || !m || !d) return null;
      const iso = endOfDay ? `${y}-${String(m).padStart(2, '0')}-${String(d).padStart(2, '0')}T23:59:59Z`
        : `${y}-${String(m).padStart(2, '0')}-${String(d).padStart(2, '0')}T00:00:00Z`;
      const ts = Date.parse(iso);
      return Number.isFinite(ts) ? Math.floor(ts / 1000) : null;
    };

    const collectDaily = (prices) => {
      const daily = {};
      prices.forEach((entry) => {
        const ts = Array.isArray(entry) ? entry[0] : null;
        const price = Array.isArray(entry) ? entry[1] : null;
        if (!Number.isFinite(ts) || !Number.isFinite(price)) return;
        const day = new Date(ts).toISOString().slice(0, 10);
        daily[day] = price;
      });
      return daily;
    };

    btn.addEventListener('click', async () => {
      const start = startInput.value;
      const end = endInput.value;
      if (!start || !end) {
        alert('No range defined.');
        return;
      }
      const from = toUnix(start, false);
      const to = toUnix(end, true);
      if (!from || !to || to < from) {
        alert('Intervalo invalido.');
        return;
      }

      const tokens = Object.keys(map);
      if (!tokens.length) {
        alert('No tokens mapped for history.');
        return;
      }

      btn.disabled = true;
      btn.textContent = 'A atualizar...';

      const out = {};
      try {
        for (const token of tokens) {
          const id = map[token];
          if (!id) continue;
          const url = `https://api.coingecko.com/api/v3/coins/${encodeURIComponent(id)}/market_chart/range?vs_currency=usd&from=${from}&to=${to}`;
          const res = await fetch(url);
          if (!res.ok) throw new Error(`Falha Coingecko (${token})`);
          const data = await res.json();
          const daily = collectDaily(Array.isArray(data?.prices) ? data.prices : []);
          if (Object.keys(daily).length) {
            out[token] = daily;
          }
        }

        if (!Object.keys(out).length) {
          alert('No historical data received.');
          return;
        }

        jsonInput.value = JSON.stringify(out);
        if (typeof form.requestSubmit === 'function') {
          form.requestSubmit();
        } else {
          form.submit();
        }
      } catch (err) {
        alert(`Error fetching history: ${err?.message || 'unknown'}`);
      } finally {
        btn.disabled = false;
        btn.textContent = 'Refresh logs';
      }
    });
  }

  function initFeesSteppers() {
    document.addEventListener('click', (evt) => {
      const btn = evt.target.closest('[data-fees-step]');
      if (!btn) return;

      const wrap = btn.closest('.fees-input-wrap');
      const input = wrap?.querySelector('input[type="number"]');
      if (!input) return;

      const stepRaw = String(input.getAttribute('step') || '1');
      const step = Number(stepRaw) || 1;
      const dir = Number(btn.getAttribute('data-fees-step') || '0');
      const min = Number(input.getAttribute('min'));
      const current = Number(String(input.value || '0').replace(',', '.')) || 0;
      const multiplier = evt.shiftKey ? 10 : 1;
      const next = current + step * dir * multiplier;
      const decimals = stepRaw.includes('.') ? stepRaw.split('.')[1].length : 0;
      const bounded = Number.isFinite(min) ? Math.max(min, next) : next;

      input.value = bounded.toFixed(decimals);
      input.dispatchEvent(new Event('input', { bubbles: true }));
    });
  }
})();
