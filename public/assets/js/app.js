// Grounds for Concern — progressive enhancement for the rule builder.
// The form works without this file; JS makes it feel alive.
(() => {
    'use strict';

    const form = document.getElementById('rule-builder');
    if (!form) return;

    const categories = JSON.parse(form.dataset.categories || '[]');
    const previewUrl = form.dataset.previewUrl;
    const backtestUrl = form.dataset.backtestUrl;

    const sentenceEl = document.getElementById('preview-sentence');
    const errorsEl = document.getElementById('preview-errors');
    const backtestBtn = document.getElementById('backtest-btn');
    const backtestEl = document.getElementById('backtest-results');
    const rowsEl = document.getElementById('condition-rows');
    const rowTemplate = document.getElementById('condition-template');

    // ----- condition rows ---------------------------------------------------

    function valueControl(field, value = '') {
        if (field === 'category') {
            const sel = document.createElement('select');
            sel.name = 'cond_value[]';
            sel.className = 'cond-value';
            sel.setAttribute('aria-label', 'Value');
            for (const c of categories) {
                const opt = document.createElement('option');
                opt.value = c;
                opt.textContent = c;
                if (c === value) opt.selected = true;
                sel.appendChild(opt);
            }
            return sel;
        }
        const input = document.createElement('input');
        input.type = 'text';
        input.name = 'cond_value[]';
        input.className = 'cond-value';
        input.setAttribute('list', 'merchant-names');
        input.setAttribute('aria-label', 'Value');
        input.placeholder = 'e.g. Starbucks';
        input.value = value;
        return input;
    }

    function wireRow(row) {
        const fieldSel = row.querySelector('.cond-field');
        const slot = row.querySelector('[data-value-slot]');
        fieldSel.addEventListener('change', () => {
            slot.replaceChildren(valueControl(fieldSel.value));
            schedulePreview();
        });
        row.querySelector('[data-remove]').addEventListener('click', () => {
            if (rowsEl.querySelectorAll('[data-row]').length > 1) {
                row.remove();
            } else {
                // Last row: clear instead of removing — a rule needs a condition.
                fieldSel.selectedIndex = 0;
                slot.replaceChildren(valueControl('category'));
                const op = row.querySelector('.cond-op');
                op.selectedIndex = 0;
            }
            schedulePreview();
        });
    }

    document.getElementById('add-condition').addEventListener('click', () => {
        const row = rowTemplate.content.firstElementChild.cloneNode(true);
        rowsEl.appendChild(row);
        wireRow(row);
        schedulePreview();
    });

    rowsEl.querySelectorAll('[data-row]').forEach(wireRow);

    // ----- live preview -------------------------------------------------------

    let previewTimer = null;
    function schedulePreview() {
        clearTimeout(previewTimer);
        previewTimer = setTimeout(runPreview, 300);
    }

    async function runPreview() {
        try {
            const res = await fetch(previewUrl, {
                method: 'POST',
                body: new FormData(form),
            });
            const data = await res.json();
            if (data.ok) {
                sentenceEl.textContent = data.sentence;
                errorsEl.hidden = true;
                errorsEl.replaceChildren();
            } else {
                sentenceEl.textContent = 'Alert me when…';
                errorsEl.hidden = false;
                errorsEl.replaceChildren(...data.errors.map(e => {
                    const li = document.createElement('li');
                    li.textContent = e;
                    return li;
                }));
            }
        } catch {
            // Preview is a nicety; never block the form on it.
        }
    }

    form.addEventListener('input', schedulePreview);
    form.addEventListener('change', schedulePreview);

    // ----- backtest -------------------------------------------------------------

    backtestBtn.addEventListener('click', async () => {
        backtestBtn.disabled = true;
        backtestBtn.textContent = 'Running…';
        try {
            const res = await fetch(backtestUrl, { method: 'POST', body: new FormData(form) });
            const data = await res.json();
            if (data.ok) {
                backtestEl.innerHTML = data.html;
            } else {
                backtestEl.innerHTML = '<p class="notice notice-warn">' +
                    data.errors.map(e => e.replace(/</g, '&lt;')).join(' ') + '</p>';
            }
        } catch {
            backtestEl.innerHTML = '<p class="notice notice-warn">Backtest failed.</p>';
        } finally {
            backtestBtn.disabled = false;
            backtestBtn.textContent = 'Run backtest';
        }
    });
})();
