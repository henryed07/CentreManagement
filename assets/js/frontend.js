/* QM Booking Plugin — Frontend JS */
(function () {
    'use strict';

    var CART_KEY = 'rhcm_cart';

    // ── Cart state ────────────────────────────────────────────────────────────

    function getCart() {
        try { return JSON.parse(sessionStorage.getItem(CART_KEY) || '[]'); }
        catch (e) { return []; }
    }

    function saveCart(cart) {
        sessionStorage.setItem(CART_KEY, JSON.stringify(cart));
    }

    function isInCart(sessionId) {
        return getCart().some(function (item) { return String(item.id) === String(sessionId); });
    }

    function addToCart(session) {
        if (isInCart(session.id)) return;
        var cart = getCart();
        cart.push(session);
        saveCart(cart);
        updateCartUI();
    }

    function removeFromCart(sessionId) {
        saveCart(getCart().filter(function (item) { return String(item.id) !== String(sessionId); }));
        updateCartUI();
    }

    function clearCart() {
        sessionStorage.removeItem(CART_KEY);
        updateCartUI();
    }

    // ── Cart UI ───────────────────────────────────────────────────────────────

    function updateCartUI() {
        var cart  = getCart();
        var count = cart.length;

        // FAB visibility + badge
        var fab   = document.getElementById('rhcm-cart-fab');
        var badge = document.getElementById('rhcm-cart-badge');
        if (fab) {
            fab.style.display = count > 0 ? 'flex' : 'none';
            if (badge) badge.textContent = count;
        }

        // Checkout button state
        var checkoutBtn = document.getElementById('rhcm-cart-checkout-btn');
        if (checkoutBtn) checkoutBtn.disabled = count === 0;

        // "Add to Cart" button labels across the page
        document.querySelectorAll('.rhcm-add-to-cart').forEach(function (btn) {
            var sid  = btn.dataset.session;
            var full = btn.dataset.full === '1';
            if (isInCart(sid)) {
                btn.textContent = '✓ Added to Basket';
                btn.classList.add('rhcm-in-cart');
            } else {
                btn.textContent = full ? 'Join Waiting List' : 'Add to Cart';
                btn.classList.remove('rhcm-in-cart');
            }
        });

        // Cart drawer item list
        renderCartDrawer(cart);
    }

    function renderCartDrawer(cart) {
        var container = document.getElementById('rhcm-cart-items');
        if (!container) return;

        if (cart.length === 0) {
            container.innerHTML = '<p class="rhcm-cart-empty-msg">Your basket is empty.</p>';
            var totalEl = document.getElementById('rhcm-cart-total');
            if (totalEl) totalEl.textContent = '£0.00';
            return;
        }

        var total = 0;
        var html  = '';
        cart.forEach(function (item) {
            var spaces    = parseInt(item.spaces || 1, 10);
            var itemTotal = (parseFloat(item.price) || 0) * spaces;
            total += itemTotal;
            html += '<div class="rhcm-cart-item">' +
                '<div class="rhcm-cart-item-info">' +
                    '<span class="rhcm-cart-item-title">' + escHtml(item.title) + '</span>' +
                    '<span class="rhcm-cart-item-date">'  + escHtml(item.date)  + '</span>' +
                    (spaces > 1 ? '<span class="rhcm-cart-item-spaces">' + spaces + ' spaces &times; £' + parseFloat(item.price).toFixed(2) + '</span>' : '') +
                '</div>' +
                '<span class="rhcm-cart-item-price">£' + itemTotal.toFixed(2) + '</span>' +
                '<button class="rhcm-cart-remove" data-remove="' + escHtml(item.id) + '" title="Remove">&times;</button>' +
                '</div>';
        });
        container.innerHTML = html;

        var totalEl = document.getElementById('rhcm-cart-total');
        if (totalEl) totalEl.textContent = '£' + total.toFixed(2);
    }

    // ── Drawer open / close ───────────────────────────────────────────────────

    function openDrawer() {
        var drawer   = document.getElementById('rhcm-cart-drawer');
        var backdrop = document.getElementById('rhcm-cart-backdrop');
        if (drawer)   { drawer.classList.add('rhcm-open'); }
        if (backdrop) { backdrop.style.display = 'block'; }
        document.body.style.overflow = 'hidden';
    }

    function closeDrawer() {
        var drawer   = document.getElementById('rhcm-cart-drawer');
        var backdrop = document.getElementById('rhcm-cart-backdrop');
        if (drawer)   { drawer.classList.remove('rhcm-open'); }
        if (backdrop) { backdrop.style.display = 'none'; }
        document.body.style.overflow = '';
    }

    // ── Checkout modal ────────────────────────────────────────────────────────

    var modal = document.getElementById('rhcm-modal');

    function openCheckoutModal() {
        if (!modal) return;
        var cart = getCart();
        if (cart.length === 0) return;

        // Populate modal item list
        var itemsEl = document.getElementById('rhcm-modal-items');
        var totalEl = document.getElementById('rhcm-modal-total');
        var inputsEl = document.getElementById('rhcm-session-inputs');
        var waitingEl = document.getElementById('rhcm-waiting-notice');

        var total   = 0;
        var hasWait = false;
        var itemHtml  = '';
        var inputHtml = '';

        cart.forEach(function (item) {
            var spaces    = parseInt(item.spaces || 1, 10);
            var itemTotal = (parseFloat(item.price) || 0) * spaces;
            total += itemTotal;
            if (item.full === '1' || item.full === true) hasWait = true;

            itemHtml += '<div class="rhcm-modal-item">' +
                '<div>' +
                    '<span class="rhcm-modal-item-name">' + escHtml(item.title) + '</span>' +
                    '<span class="rhcm-modal-item-date">'  + escHtml(item.date)  + '</span>' +
                    (spaces > 1 ? '<span class="rhcm-modal-item-spaces">' + spaces + ' spaces &times; £' + parseFloat(item.price).toFixed(2) + '</span>' : '') +
                '</div>' +
                '<span class="rhcm-modal-item-price">£' + itemTotal.toFixed(2) + '</span>' +
                '</div>';

            inputHtml += '<input type="hidden" name="session_ids[]" value="' + escHtml(item.id) + '">';
            inputHtml += '<input type="hidden" name="session_spaces[]" value="' + spaces + '">';
        });

        if (itemsEl)  itemsEl.innerHTML  = itemHtml;
        if (totalEl)  totalEl.textContent = '£' + total.toFixed(2);
        if (inputsEl) inputsEl.innerHTML  = inputHtml;
        if (waitingEl) waitingEl.style.display = hasWait ? 'block' : 'none';

        var submitBtn = document.getElementById('rhcm-submit-btn');
        if (submitBtn) {
            submitBtn.textContent = cart.length > 1
                ? '✓ Confirm ' + cart.length + ' Bookings'
                : (hasWait ? '📋 Join Waiting List' : '✓ Confirm Booking');
        }

        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';

        var firstInput = modal.querySelector('input[name="first_name"]');
        if (firstInput) firstInput.focus();
    }

    function closeModal() {
        if (modal) modal.style.display = 'none';
        document.body.style.overflow = '';
    }

    // ── Event delegation ──────────────────────────────────────────────────────

    document.addEventListener('click', function (e) {

        // "Add to Cart" button on session cards
        var addBtn = e.target.closest('.rhcm-add-to-cart');
        if (addBtn) {
            e.preventDefault();
            var sid = addBtn.dataset.session;
            if (isInCart(sid)) {
                removeFromCart(sid);
            } else {
                addToCart({
                    id:     sid,
                    title:  addBtn.dataset.title,
                    date:   addBtn.dataset.date,
                    price:  addBtn.dataset.price,
                    full:   addBtn.dataset.full,
                    spaces: parseInt(addBtn.dataset.spaces || '1', 10),
                });
                // Brief visual confirmation
                addBtn.textContent = '✓ Added!';
                setTimeout(updateCartUI, 600);
            }
            return;
        }

        // Tab switching
        var tabBtn = e.target.closest('.rhcm-tab-btn');
        if (tabBtn) {
            var tabsEl = tabBtn.closest('.rhcm-tabs');
            if (tabsEl) {
                var targetId = tabBtn.dataset.tab;
                tabsEl.querySelectorAll('.rhcm-tab-btn').forEach(function (b) {
                    b.classList.remove('rhcm-tab-active');
                    b.setAttribute('aria-selected', 'false');
                });
                tabsEl.querySelectorAll('.rhcm-tab-panel').forEach(function (p) {
                    p.hidden = true;
                });
                tabBtn.classList.add('rhcm-tab-active');
                tabBtn.setAttribute('aria-selected', 'true');
                var panel = document.getElementById(targetId);
                if (panel) panel.hidden = false;
            }
            return;
        }

        // Spaces stepper: minus
        var minusBtn = e.target.closest('.rhcm-spaces-minus');
        if (minusBtn) {
            var inp = minusBtn.parentNode.querySelector('.rhcm-spaces-input');
            if (inp) {
                inp.value = Math.max(1, parseInt(inp.value, 10) - 1);
                updateSpacesUI(inp);
            }
            return;
        }

        // Spaces stepper: plus
        var plusBtn = e.target.closest('.rhcm-spaces-plus');
        if (plusBtn) {
            var inp = plusBtn.parentNode.querySelector('.rhcm-spaces-input');
            if (inp) {
                var max = parseInt(inp.getAttribute('max') || '99', 10);
                inp.value = Math.min(max, parseInt(inp.value, 10) + 1);
                updateSpacesUI(inp);
            }
            return;
        }

        // Remove item in cart drawer
        var removeBtn = e.target.closest('.rhcm-cart-remove');
        if (removeBtn) {
            removeFromCart(removeBtn.dataset.remove);
            return;
        }

        // FAB → open drawer
        if (e.target.closest('#rhcm-cart-fab')) {
            openDrawer();
            return;
        }

        // Drawer backdrop → close drawer
        if (e.target.id === 'rhcm-cart-backdrop') {
            closeDrawer();
            return;
        }

        // Drawer close button
        if (e.target.id === 'rhcm-cart-close') {
            closeDrawer();
            return;
        }

        // Drawer checkout button → open modal
        if (e.target.id === 'rhcm-cart-checkout-btn') {
            closeDrawer();
            openCheckoutModal();
            return;
        }

        // Clear cart
        if (e.target.id === 'rhcm-cart-clear') {
            if (confirm('Remove all items from your basket?')) clearCart();
            return;
        }

        // Modal backdrop → close modal
        if (e.target.classList.contains('rhcm-modal-backdrop')) {
            closeModal();
            return;
        }

        // Modal close button
        if (e.target.closest('.rhcm-modal-close')) {
            closeModal();
            return;
        }

        // Calendar day → scroll to session cards
        var cell = e.target.closest('.rhcm-cal-day');
        if (cell && !cell.classList.contains('rhcm-empty')) {
            var day   = cell.dataset.day;
            var cards = document.querySelectorAll('[id^="rhcm-day-' + day + '-"]');
            if (cards.length) {
                cards[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
                cards.forEach(function (c) {
                    c.classList.add('rhcm-highlighted');
                    setTimeout(function () { c.classList.remove('rhcm-highlighted'); }, 1600);
                });
            }
            document.querySelectorAll('.rhcm-cal-day.rhcm-selected').forEach(function (el) {
                el.classList.remove('rhcm-selected');
            });
            cell.classList.add('rhcm-selected');
            return;
        }

    });

    // Keyboard: Escape closes modal or drawer
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        if (modal && modal.style.display !== 'none') { closeModal(); return; }
        var drawer = document.getElementById('rhcm-cart-drawer');
        if (drawer && drawer.classList.contains('rhcm-open')) closeDrawer();
    });

    // FAB keyboard (Enter / Space)
    document.addEventListener('keydown', function (e) {
        if ((e.key === 'Enter' || e.key === ' ') && e.target.id === 'rhcm-cart-fab') {
            e.preventDefault();
            openDrawer();
        }
    });

    // ── Spaces stepper helpers ────────────────────────────────────────────────

    function updateSpacesUI(input) {
        var spaces = Math.max(1, parseInt(input.value, 10) || 1);
        var max    = parseInt(input.getAttribute('max') || '99', 10);
        spaces     = Math.min(spaces, max);
        input.value = spaces;

        var panel = input.closest('.rhcm-sd-order');
        if (!panel) return;

        var btn = panel.querySelector('.rhcm-add-to-cart');
        if (btn) btn.dataset.spaces = spaces;

        var price      = parseFloat(btn ? btn.dataset.price : 0) || 0;
        var subtotalEl = panel.querySelector('.rhcm-sd-subtotal');
        if (subtotalEl) subtotalEl.textContent = '£' + (price * spaces).toFixed(2);
    }

    document.addEventListener('input', function (e) {
        if (!e.target.classList.contains('rhcm-spaces-input')) return;
        updateSpacesUI(e.target);
    });

    // ── Helpers ───────────────────────────────────────────────────────────────

    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    // ── Discount code ─────────────────────────────────────────────────────────

    var appliedDiscount = null; // { type, amount, description }

    function resetDiscount() {
        appliedDiscount = null;
        var codeVal    = document.getElementById('rhcm-discount-code-val');
        var amountVal  = document.getElementById('rhcm-discount-amount-val');
        var msg        = document.getElementById('rhcm-discount-msg');
        var input      = document.getElementById('rhcm-discount-input');
        if (codeVal)   codeVal.value   = '';
        if (amountVal) amountVal.value = '0';
        if (msg)       { msg.style.display = 'none'; msg.textContent = ''; msg.className = 'rhcm-discount-msg'; }
        if (input)     input.value = '';
        updateModalTotal();
    }

    function updateModalTotal() {
        var cart     = getCart();
        var subtotal = cart.reduce(function (s, i) { return s + (parseFloat(i.price) || 0) * (parseInt(i.spaces || 1, 10)); }, 0);
        var saving   = 0;

        if (appliedDiscount) {
            if (appliedDiscount.type === 'percent') {
                saving = subtotal * appliedDiscount.amount / 100;
            } else {
                saving = Math.min(appliedDiscount.amount, subtotal);
            }
        }

        var total    = Math.max(0, subtotal - saving);
        var totalEl  = document.getElementById('rhcm-modal-total');

        // Build total rows
        var html = '';
        if (saving > 0) {
            html += '<div class="rhcm-discount-saving">' +
                '<span>Discount (' + escHtml(appliedDiscount.description || appliedDiscount.amount + (appliedDiscount.type === 'percent' ? '%' : ' off')) + ')</span>' +
                '<span>-£' + saving.toFixed(2) + '</span>' +
                '</div>';
        }

        var savingContainer = document.getElementById('rhcm-discount-saving-row');
        if (!savingContainer) {
            var totalRow = totalEl && totalEl.closest('.rhcm-modal-total-row');
            if (totalRow) {
                savingContainer = document.createElement('div');
                savingContainer.id = 'rhcm-discount-saving-row';
                totalRow.parentNode.insertBefore(savingContainer, totalRow);
            }
        }
        if (savingContainer) savingContainer.innerHTML = html;
        if (totalEl) totalEl.textContent = '£' + total.toFixed(2);

        // Update hidden discount amount
        var amountVal = document.getElementById('rhcm-discount-amount-val');
        if (amountVal) amountVal.value = saving.toFixed(2);
    }

    document.addEventListener('click', function (e) {
        if (e.target.id !== 'rhcm-discount-apply') return;
        var input = document.getElementById('rhcm-discount-input');
        var msg   = document.getElementById('rhcm-discount-msg');
        var code  = (input ? input.value.trim() : '').toUpperCase();
        if (!code) return;

        var cart        = getCart();
        var sessionIds  = cart.map(function (i) { return i.id; });
        var formData    = new FormData();
        formData.append('action',  'rhcm_validate_discount');
        formData.append('nonce',   RHCM.nonce);
        formData.append('code',    code);
        sessionIds.forEach(function (id) { formData.append('session_ids[]', id); });

        e.target.textContent = '...';
        e.target.disabled    = true;

        fetch(RHCM.ajaxUrl, { method: 'POST', body: formData })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                e.target.textContent = 'Apply';
                e.target.disabled    = false;
                if (!msg) return;
                msg.style.display = 'block';
                msg.textContent   = res.message || '';

                if (res.valid) {
                    msg.className    = 'rhcm-discount-msg rhcm-valid';
                    appliedDiscount  = { type: res.type, amount: res.amount, description: res.description };
                    var codeVal      = document.getElementById('rhcm-discount-code-val');
                    if (codeVal) codeVal.value = code;
                    updateModalTotal();
                } else {
                    msg.className   = 'rhcm-discount-msg rhcm-invalid';
                    appliedDiscount = null;
                    updateModalTotal();
                }
            })
            .catch(function () {
                e.target.textContent = 'Apply';
                e.target.disabled    = false;
            });
    });

    // Reset discount when checkout modal opens
    var _origOpenCheckout = openCheckoutModal;
    openCheckoutModal = function () {
        resetDiscount();
        _origOpenCheckout();
        updateModalTotal();
    };

    // ── Init ──────────────────────────────────────────────────────────────────

    updateCartUI();

    // ── Membership Join flow ──────────────────────────────────────────────────

    (function () {
        var join = document.getElementById('rhcm-join');
        if (!join) return;

        var state = { step: 1, catKey: '', catName: '', catPrice: '', catAnnual: 0, boltOns: [] };

        function goToStep(n) {
            state.step = n;
            join.querySelectorAll('.rhcm-join-panel').forEach(function (p) {
                p.style.display = 'none';
            });
            var panel = document.getElementById('rhcm-join-panel-' + n);
            if (panel) panel.style.display = '';

            // Update stepper
            join.querySelectorAll('.rhcm-join-step').forEach(function (el) {
                var s = parseInt(el.getAttribute('data-step'));
                el.classList.remove('rhcm-join-step-active', 'rhcm-join-step-done');
                if (s === n) el.classList.add('rhcm-join-step-active');
                else if (s < n) el.classList.add('rhcm-join-step-done');
            });

            join.querySelectorAll('.rhcm-join-connector').forEach(function (el, i) {
                el.classList.toggle('rhcm-join-done', i + 1 < n);
            });

            if (n === 3) buildSummary();
            if (n === 4) updateMonthlyAmount();
        }

        function buildSummary() {
            var catEl = document.getElementById('rhcm-join-summary-cat');
            var priceEl = document.getElementById('rhcm-join-summary-price');
            if (catEl) catEl.textContent = state.catName || '—';
            if (priceEl) priceEl.textContent = state.catPrice || 'POA';

            // Hidden input for category
            var catInput = document.getElementById('rhcm-join-field-cat');
            if (catInput) catInput.value = state.catKey;

            // Bolt-on summary rows
            var boltRows = document.getElementById('rhcm-join-summary-bolt-rows');
            var boltInputs = document.getElementById('rhcm-join-bolt-inputs');
            if (boltRows) boltRows.innerHTML = '';
            if (boltInputs) boltInputs.innerHTML = '';

            state.boltOns.forEach(function (bo) {
                if (boltRows) {
                    var row = document.createElement('div');
                    row.className = 'rhcm-join-summary-row';
                    row.innerHTML = '<span class="rhcm-join-summary-label">Add-on</span><span class="rhcm-join-summary-value">' +
                        escHtml(bo.name) + (bo.price ? ' &mdash; ' + escHtml(bo.price) : '') + '</span>';
                    boltRows.appendChild(row);
                }
                if (boltInputs) {
                    var inp = document.createElement('input');
                    inp.type = 'hidden';
                    inp.name = 'bolt_ons[]';
                    inp.value = bo.id;
                    boltInputs.appendChild(inp);
                }
            });
        }

        // Option card selection
        join.addEventListener('click', function (e) {
            var card = e.target.closest('.rhcm-join-option-card');
            if (card) {
                join.querySelectorAll('.rhcm-join-option-card').forEach(function (c) {
                    c.classList.remove('rhcm-join-selected');
                });
                card.classList.add('rhcm-join-selected');
                state.catKey    = card.getAttribute('data-key');
                state.catName   = card.getAttribute('data-name');
                state.catPrice  = card.getAttribute('data-price');
                state.catAnnual = parseFloat(card.getAttribute('data-annual') || '0');
                var btn = document.getElementById('rhcm-join-next-1');
                if (btn) btn.disabled = false;
                return;
            }

            // Bolt-on toggle
            var boltRow = e.target.closest('.rhcm-join-bolt-on');
            if (boltRow) {
                var chk = boltRow.querySelector('input[type="checkbox"]');
                if (chk) {
                    chk.checked = !chk.checked;
                    boltRow.classList.toggle('rhcm-bolt-checked', chk.checked);
                    var boId   = parseInt(boltRow.getAttribute('data-id'));
                    var boName = boltRow.getAttribute('data-name');
                    var boPrice = boltRow.getAttribute('data-price');
                    if (chk.checked) {
                        if (!state.boltOns.some(function (b) { return b.id === boId; })) {
                            state.boltOns.push({ id: boId, name: boName, price: boPrice });
                        }
                    } else {
                        state.boltOns = state.boltOns.filter(function (b) { return b.id !== boId; });
                    }
                }
                return;
            }

            // Continue / next
            if (e.target.classList.contains('rhcm-join-continue') || e.target.id === 'rhcm-join-next-2') {
                var cur = state.step;
                if (cur === 1 && !state.catKey) return;
                goToStep(cur + 1);
                return;
            }

            // Back
            var back = e.target.closest('.rhcm-join-back');
            if (back) {
                var to = parseInt(back.getAttribute('data-to') || '1');
                goToStep(to);
            }
        });

        function updateMonthlyAmount() {
            var el = document.getElementById('rhcm-join-monthly-amount');
            if (!el) return;
            if (state.catAnnual > 0) {
                el.textContent = '£' + (state.catAnnual / 12).toFixed(2) + '/month';
            } else {
                el.textContent = 'To be confirmed';
            }
        }

        // Sort code auto-format: 123456 → 12-34-56
        var scInput = document.getElementById('rhcm-join-sort-code');
        if (scInput) {
            scInput.addEventListener('input', function () {
                var v = this.value.replace(/\D/g, '').slice(0, 6);
                if (v.length > 4) v = v.slice(0, 2) + '-' + v.slice(2, 4) + '-' + v.slice(4);
                else if (v.length > 2) v = v.slice(0, 2) + '-' + v.slice(2);
                this.value = v;
            });
        }

        // Init step 1
        goToStep(1);
    })();

})();
