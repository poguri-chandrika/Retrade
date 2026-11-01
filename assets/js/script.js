// Retrade JavaScript Functions

document.addEventListener('DOMContentLoaded', function() {
    // Sticky topbar shadow on scroll
    (function(){
        const onScroll = ()=>{
            if (window.scrollY > 6) document.body.classList.add('topbar-scrolled');
            else document.body.classList.remove('topbar-scrolled');
        };
        onScroll();
        window.addEventListener('scroll', onScroll, { passive: true });
    })();

    // Edit Profile form handler
    (function(){
      const form = document.getElementById('editProfileForm');
      if (!form) return;
      const alertBox = document.getElementById('editProfileAlert');
      form.addEventListener('submit', async function(e){
        if (!form.checkValidity()) {
          e.preventDefault(); e.stopPropagation();
          form.classList.add('was-validated');
          return;
        }
        e.preventDefault();
        form.classList.add('was-validated');
        try {
          const fd = new FormData(form);
          const res = await fetch('api/profile_update.php', { method: 'POST', body: fd, credentials: 'same-origin' });
          let ok = res.ok; let msg = 'Profile updated successfully';
          try {
            const data = await res.json();
            if (!ok) msg = data && data.error ? data.error : 'Failed to update profile';
          } catch(_) {}
          if (alertBox) {
            alertBox.classList.remove('d-none');
            alertBox.className = 'alert ' + (ok ? 'alert-success' : 'alert-danger');
            alertBox.textContent = msg;
            setTimeout(()=>{ alertBox.classList.add('d-none'); }, 4000);
          }
          if (ok) {
            // Optionally refresh name in header avatar tooltip/name shown
            try { window.location.hash = '#edit-profile'; } catch(_) {}
          }
        } catch (err) {
          if (alertBox) {
            alertBox.classList.remove('d-none');
            alertBox.className = 'alert alert-danger';
            alertBox.textContent = 'Network error. Please try again.';
            setTimeout(()=>{ alertBox.classList.add('d-none'); }, 4000);
          }
        }
      });
    })();
    // Auto-open chat via URL flag (e.g., ?product_id=123&openChat=1)
    try {
        const url = new URL(window.location.href);
        const openChat = url.searchParams.get('openChat');
        if (openChat === '1' || openChat === 'true') {
            const btn = document.querySelector('.openChatBtn, #openChatBtn');
            if (btn) {
                // If product_id is present, ensure attribute exists for handlers
                const pid = url.searchParams.get('product_id') || url.searchParams.get('id');
                if (pid && !btn.getAttribute('data-product-id')) {
                    btn.setAttribute('data-product-id', pid);
                }
                // Default role buyer if not set
                if (!btn.getAttribute('data-role')) btn.setAttribute('data-role', 'buyer');
                setTimeout(()=> btn.click(), 150);
            }
        }
    } catch(_){}
    // Initialize tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });

    // Auto-hide alerts after 5 seconds
    setTimeout(function() {
        const alerts = document.querySelectorAll('.alert:not(.alert-permanent)');
        alerts.forEach(function(alert) {
            const bsAlert = new bootstrap.Alert(alert);
            bsAlert.close();
        });
    }, 5000);

    // Image preview for file uploads
    const imageInputs = document.querySelectorAll('input[type="file"][accept*="image"]');
    imageInputs.forEach(function(input) {
        input.addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    let preview = document.getElementById('imagePreview');
                    if (!preview) {
                        preview = document.createElement('img');
                        preview.id = 'imagePreview';
                        preview.className = 'image-preview mt-2';
                        input.parentNode.appendChild(preview);
                    }
                    preview.src = e.target.result;
                    preview.style.display = 'block';
                };
                reader.readAsDataURL(file);
            }
        });
    });

    // Confirm dialogs for dangerous actions
    const dangerousButtons = document.querySelectorAll('.btn-danger, .delete-btn');
    dangerousButtons.forEach(function(button) {
        button.addEventListener('click', function(e) {
            const action = this.dataset.action || 'delete this item';
            if (!confirm(`Are you sure you want to ${action}? This action cannot be undone.`)) {
                e.preventDefault();
            }
        });
    });

    // Form validation
    const forms = document.querySelectorAll('.needs-validation');
    forms.forEach(function(form) {
        form.addEventListener('submit', function(e) {
            if (!form.checkValidity()) {
                e.preventDefault();
                e.stopPropagation();
                form.classList.add('was-validated');
                return;
            }
            // Mark validated first
            form.classList.add('was-validated');

            // Apply loading state AFTER submit is allowed to proceed.
            // Using a micro-delay ensures the browser does not cancel submission
            // due to the submitter being disabled synchronously.
            const submitter = form.querySelector('button[type="submit"], input[type="submit"]');
            if (submitter) {
                const originalText = submitter.innerHTML;
                submitter.dataset.originalText = originalText;
                setTimeout(() => {
                    if (submitter.tagName.toLowerCase() === 'button') {
                        submitter.innerHTML = '<span class="loading"></span> Processing...';
                    }
                    submitter.disabled = true;
                }, 0);
                // Fallback to re-enable if still on the page after 6s (e.g., validation via AJAX)
                setTimeout(() => {
                    if (!submitter.disabled) return;
                    submitter.disabled = false;
                    if (submitter.tagName.toLowerCase() === 'button' && submitter.dataset.originalText) {
                        submitter.innerHTML = submitter.dataset.originalText;
                    }
                }, 6000);
            }
        });
    });

    // Search functionality
    const searchInput = document.getElementById('searchInput');
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase();
            const productCards = document.querySelectorAll('.product-card');
            
            productCards.forEach(function(card) {
                const title = card.querySelector('.card-title').textContent.toLowerCase();
                const description = card.querySelector('.card-text').textContent.toLowerCase();
                
                if (title.includes(searchTerm) || description.includes(searchTerm)) {
                    card.closest('.col-md-4').style.display = 'block';
                } else {
                    card.closest('.col-md-4').style.display = 'none';
                }
            });
        });
    }

    // Debounced auto-submit for the server-side search on home filters
    const serverSearch = document.getElementById('search');
    if (serverSearch) {
        let debounceTimer;
        serverSearch.addEventListener('input', function() {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(() => {
                const form = serverSearch.closest('form');
                if (form) form.submit();
            }, 450);
        });
    }

    // Price inputs: clamp to cap and ensure Min ≤ Max on blur
    (function(){
      const minInput = document.getElementById('min_price');
      const maxInput = document.getElementById('max_price');
      if (!minInput || !maxInput) return;
      const cap = parseInt(maxInput.getAttribute('max')||'100000',10) || 100000;
      const clamp = v => Math.max(0, Math.min(cap, isNaN(v)?0:v));
      const fixOrder = ()=>{
        let a = clamp(parseInt(minInput.value||'0',10));
        let b = clamp(parseInt(maxInput.value||'0',10));
        if (a > b) { const t = a; a = b; b = t; }
        minInput.value = String(a);
        maxInput.value = String(b);
      };
      minInput.addEventListener('blur', fixOrder);
      maxInput.addEventListener('blur', fixOrder);
    })();

    // Category checkbox dropdown: search, sync with hidden select, label summary, and active badges
    (function(){
      const hiddenSelect = document.getElementById('category');
      const list = document.getElementById('categoryList');
      const search = document.getElementById('categorySearchBox');
      const label = document.getElementById('categoryDropdownLabel');
      const clearBtn = document.getElementById('catClearBtn');
      const badgesWrap = document.getElementById('activeFilterBadges');
      if (!hiddenSelect || !list || !label) return;

      function selectedItems(){
        const arr = [];
        list.querySelectorAll('.category-check:checked').forEach(ch => {
          const name = ch.value;
          const iconEl = ch.parentElement.querySelector('i.fas, i.far, i.fa');
          // try to find the fa-* class
          let iconCls = 'fa-tag';
          if (iconEl) {
            const cls = Array.from(iconEl.classList).find(c => c.startsWith('fa-') && c !== 'fas' && c !== 'far' && c !== 'fa');
            if (cls) iconCls = cls;
          }
          arr.push({ name, icon: iconCls });
        });
        return arr;
      }

      function renderBadges(){
        if (!badgesWrap) return;
        const items = selectedItems();
        if (items.length === 0) { badgesWrap.innerHTML = ''; return; }
        badgesWrap.innerHTML = items.map(it => `
          <span class="badge bg-dark-subtle text-light me-1 mb-1 active-filter-badge" data-value="${it.name.replace(/"/g,'&quot;')}">
            <i class="fas ${it.icon} text-warning"></i>
            <span class="ms-1">${it.name.replace(/</g,'&lt;').replace(/>/g,'&gt;')}</span>
            <button type="button" class="btn-close btn-close-white btn-close-badge ms-2" aria-label="Remove" style="filter: invert(1);"></button>
          </span>`).join('');
      }

      function updateHiddenSelect(){
        const checks = list.querySelectorAll('.category-check');
        const selected = [];
        checks.forEach(ch => {
          const val = ch.value;
          let opt = hiddenSelect.querySelector(`option[value="${CSS.escape(val)}"]`);
          if (!opt) {
            opt = document.createElement('option');
            opt.value = val; opt.textContent = val;
            hiddenSelect.appendChild(opt);
          }
          opt.selected = ch.checked;
          if (ch.checked) selected.push(val);
        });
        if (selected.length === 0) {
          label.textContent = 'Choose categories';
        } else if (selected.length <= 2) {
          label.textContent = selected.join(', ');
        } else {
          label.textContent = `${selected[0]}, ${selected[1]} +${selected.length-2}`;
        }
        renderBadges();
      }

      // Initialize label based on pre-selected options
      const initialChecks = list.querySelectorAll('.category-check');
      initialChecks.forEach(ch => {
        const opt = hiddenSelect.querySelector(`option[value="${CSS.escape(ch.value)}"]`);
        if (opt) ch.checked = !!opt.selected;
      });
      updateHiddenSelect();

      list.addEventListener('change', function(e){
        if (e.target && e.target.classList.contains('category-check')) updateHiddenSelect();
      });

      if (search) {
        search.addEventListener('input', function(){
          const q = this.value.trim().toLowerCase();
          list.querySelectorAll('label.dropdown-item').forEach(row => {
            const name = row.textContent.trim().toLowerCase();
            row.style.display = name.includes(q) ? '' : 'none';
          });
        });
      }

      if (clearBtn) {
        clearBtn.addEventListener('click', function(){
          list.querySelectorAll('.category-check').forEach(ch => ch.checked = false);
          updateHiddenSelect();
        });
      }

      // Remove via badge close button
      if (badgesWrap) {
        badgesWrap.addEventListener('click', function(e){
          const btn = e.target.closest('.btn-close-badge');
          if (!btn) return;
          const badge = btn.closest('.active-filter-badge');
          if (!badge) return;
          const val = badge.getAttribute('data-value');
          const checkbox = list.querySelector(`.category-check[value="${CSS.escape(val)}"]`);
          if (checkbox) { checkbox.checked = false; updateHiddenSelect(); }
        });
      }
    })();

    // Copy link button on product details
    const copyBtn = document.getElementById('copyProductLink');
    if (copyBtn) {
        copyBtn.addEventListener('click', async function() {
            try {
                await navigator.clipboard.writeText(window.location.href);
                this.innerHTML = '<i class="fas fa-check"></i> Copied!';
                setTimeout(() => (this.innerHTML = '<i class="fas fa-link"></i> Copy Link'), 1500);
            } catch (e) {
                alert('Unable to copy link');
            }
        });
    }

    // Skeleton removal for card images
    document.querySelectorAll('.card-img-top[loading="lazy"]').forEach(img => {
        const wrapper = img.closest('.position-relative');
        if (wrapper) wrapper.classList.add('skeleton');
        if (img.complete) {
            if (wrapper) wrapper.classList.remove('skeleton');
            return;
        }
        img.addEventListener('load', () => {
            if (wrapper) wrapper.classList.remove('skeleton');
        });
        img.addEventListener('error', () => {
            if (wrapper) wrapper.classList.remove('skeleton');
        });
    });

    // Live price preview for seller forms
    const priceInput = document.getElementById('price');
    const pricePreview = document.getElementById('pricePreview');
    if (priceInput && pricePreview) {
        const fmt = new Intl.NumberFormat('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        const updatePreview = () => {
            const val = parseFloat(priceInput.value);
            if (isNaN(val)) { pricePreview.textContent = ''; return; }
            pricePreview.textContent = 'Preview: ₹' + fmt.format(val);
        };
        priceInput.addEventListener('input', updatePreview);
        updatePreview();
    }

    // Price filter
    const priceFilter = document.getElementById('priceFilter');
    if (priceFilter) {
        priceFilter.addEventListener('change', function() {
            const maxPrice = parseFloat(this.value) || Infinity;
            const productCards = document.querySelectorAll('.product-card');
            
            productCards.forEach(function(card) {
                const priceText = card.querySelector('.product-price').textContent;
                const price = parseFloat(priceText.replace(/[^\d.]/g, ''));
                
                if (price <= maxPrice) {
                    card.closest('.col-md-4').style.display = 'block';
                } else {
                    card.closest('.col-md-4').style.display = 'none';
                }
            });
        });
    }

    // Loading states for buttons are now handled in the form submit handler above

    // Notifications: polling and dropdown rendering
    (function(){
      const badge = document.getElementById('notifBadge');
      const list = document.getElementById('notifList');
      const markAllBtn = document.getElementById('notifMarkAll');
      if (!list || !badge) return;

      // Track previously seen unread IDs to trigger toasts only for new ones
      let seenUnread = new Set();

      function ensureToastContainer(){
        let c = document.getElementById('toastContainer');
        if (!c) {
          c = document.createElement('div');
          c.id = 'toastContainer';
          c.className = 'position-fixed top-0 end-0 p-3';
          c.style.zIndex = '1080';
          document.body.appendChild(c);
        }
        return c;
      }

      function showToast(title, message, link){
        const container = ensureToastContainer();
        const wrap = document.createElement('div');
        wrap.className = 'toast align-items-center text-bg-dark border-0';
        wrap.role = 'alert';
        wrap.ariaLive = 'assertive';
        wrap.ariaAtomic = 'true';
        wrap.innerHTML = `
          <div class="d-flex">
            <div class="toast-body">
              <strong>${title}</strong><br>
              <span class="text-muted">${message || ''}</span>
            </div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
          </div>`;
        container.appendChild(wrap);
        const t = new bootstrap.Toast(wrap, { delay: 4000 });
        t.show();
        if (link) {
          wrap.addEventListener('click', (e)=>{
            if (!e.target.closest('button')) window.location.href = link;
          });
        }
      }

      function typeIcon(type){
        switch ((type||'').toLowerCase()){
          case 'chat': return '<i class="fas fa-comments me-1 text-info"></i>';
          case 'order': return '<i class="fas fa-box me-1 text-success"></i>';
          case 'payment': return '<i class="fas fa-credit-card me-1 text-warning"></i>';
          case 'dispute': return '<i class="fas fa-exclamation-circle me-1 text-danger"></i>';
          default: return '<i class="fas fa-bell me-1 text-secondary"></i>';
        }
      }

      async function refreshNotifs(){
        try {
          const res = await fetch('api/notifications_fetch.php?limit=20', { credentials: 'same-origin' });
          const data = await res.json();
          if (!res.ok) return;
          // After filtering chat-type items, recompute unread for badge to avoid mismatch
          const allItems = Array.isArray(data.items) ? data.items : [];
          const chatItems = allItems.filter(n => String(n.type||'').toLowerCase() === 'chat');
          const nonChatItems = allItems.filter(n => String(n.type||'').toLowerCase() !== 'chat');
          const unread = nonChatItems.filter(n => !n.is_read).length;
          if (unread > 0) {
            badge.style.display = 'inline-block';
            badge.textContent = unread > 9 ? '9+' : String(unread);
          } else {
            badge.style.display = 'none';
          }
          // Use filtered non-chat items in the list
          const items = nonChatItems;
          // Render list, optionally with an Open Inbox helper button if chat items were hidden
          const helper = (chatItems.length > 0)
            ? `<div class="p-2 border-bottom d-flex justify-content-between align-items-center bg-dark-subtle">
                 <div class="small text-muted">${chatItems.length} chat notification${chatItems.length>1?'s':''} moved to Inbox</div>
                 <button class="btn btn-sm btn-primary" id="openInboxFromNotifs">Open Inbox</button>
               </div>`
            : '';

          if (items.length === 0) {
            list.innerHTML = helper + '<div class="p-3 text-muted small">No notifications</div>';
            return;
          }
          list.innerHTML = helper + items.map(n => {
            const cls = n.is_read ? 'text-muted' : 'bg-dark-subtle';
            // For chat notifications, avoid adding a stretched-link anchor to prevent hard navigation
            const linkHTML = (n.link && (String(n.type||'').toLowerCase() !== 'chat')) ? `<a href="${n.link}" class="stretched-link"></a>` : '';
            const badgeHTML = n.type && n.type !== 'info' ? `<span class="badge bg-secondary ms-2">${n.type}</span>` : '';
            const icon = typeIcon(n.type);
            return `
              <div class="position-relative p-2 border-bottom notif-item ${cls}" data-nid="${n.id}" data-link="${n.link || ''}" data-type="${n.type || ''}">
                <div class="fw-semibold">${icon}${n.title || 'Notification'} ${badgeHTML}</div>
                <div class="small">${(n.message||'')}</div>
                <div class="small text-muted">${(new Date(n.created_at)).toLocaleString()}</div>
                ${linkHTML}
              </div>`;
          }).join('');

          // Toast new unread items
          const currentUnreadIds = new Set(items.filter(n=>!n.is_read).map(n=>String(n.id)));
          items.filter(n=>!n.is_read && !seenUnread.has(String(n.id))).forEach(n=>{
            showToast(n.title || 'Notification', n.message || '', n.link || null);
          });
          seenUnread = currentUnreadIds;
        } catch(e) {}
      }

      async function markAll(){
        try {
          const form = new FormData();
          form.append('all','1');
          await fetch('api/notifications_mark_read.php', { method: 'POST', body: form, credentials: 'same-origin' });
          refreshNotifs();
        } catch(e) {}
      }
      markAllBtn && markAllBtn.addEventListener('click', function(e){ e.preventDefault(); markAll(); });
      // Click single notification -> mark read then follow link if present
      function parseChatParams(url){
        try {
          const u = new URL(url, window.location.origin);
          const pid = u.searchParams.get('product_id') || u.searchParams.get('id');
          const buyerId = u.searchParams.get('buyer_id');
          const sellerId = u.searchParams.get('seller_id');
          const role = (u.searchParams.get('role')||'').toLowerCase();
          const openChat = u.searchParams.get('openChat');
          if (pid && buyerId && sellerId) return { pid, buyerId, sellerId, role };
          // Fallback: if only product id is present, treat current user as buyer
          if (pid && (openChat === '1' || openChat === 'true')) return { pid, buyerId: null, sellerId: null, role: 'buyer' };
        } catch(_){}
        return null;
      }

      list.addEventListener('click', async function(e){
        // Open Inbox helper button
        const inboxBtn = e.target.closest('#openInboxFromNotifs');
        if (inboxBtn) {
          e.preventDefault();
          try {
            const ddTrigger = document.getElementById('messagesDropdown');
            if (ddTrigger) {
              const dd = bootstrap.Dropdown.getOrCreateInstance(ddTrigger);
              dd.show();
              if (typeof window.refreshMessages === 'function') window.refreshMessages();
            }
          } catch(_){ }
          return;
        }
        const row = e.target.closest('.notif-item');
        if (!row) return;
        const nid = row.getAttribute('data-nid');
        const link = row.getAttribute('data-link');
        const ntype = (row.getAttribute('data-type')||'').toLowerCase();
        try {
          const form = new FormData();
          form.append('ids', nid);
          await fetch('api/notifications_mark_read.php', { method: 'POST', body: form, credentials: 'same-origin' });
        } catch(err) {}
        // If chat-type OR link flagged with openChat, open inbox dropdown instead of chat modal
        if (link) {
          const p = parseChatParams(link);
          if (p) {
            e.preventDefault();
            try {
              const ddTrigger = document.getElementById('messagesDropdown');
              if (ddTrigger) {
                const dd = bootstrap.Dropdown.getOrCreateInstance(ddTrigger);
                dd.show();
                if (typeof window.refreshMessages === 'function') window.refreshMessages();
                return; // handled
              }
            } catch(_){}
          }
        }
        if (link) window.location.href = link; else refreshNotifs();
      });
      refreshNotifs();
      setInterval(refreshNotifs, 20000);
    })();

    // Messages: polling and dropdown rendering
    (function(){
      const badge = document.getElementById('messagesBadge');
      const list = document.getElementById('messagesList');
      const onlyUnreadToggle = document.getElementById('messagesOnlyUnread');
      if (!badge || !list) return;

      function escapeHtml(str){
        if (typeof str !== 'string') return '';
        return str.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');
      }

      function formatTime(ts){
        try { return new Date(ts).toLocaleString(); } catch(e){ return ''; }
      }

      async function refreshMessages(){
        try {
          const params = new URLSearchParams({ limit: '10' });
          if (onlyUnreadToggle && onlyUnreadToggle.checked) params.set('unread','1');
          const res = await fetch('api/messages_summary.php?' + params.toString(), { credentials: 'same-origin' });
          const data = await res.json();
          if (!res.ok) return;
          const unread = data.unread || 0;
          if (unread > 0) {
            badge.style.display = 'inline-block';
            badge.textContent = unread > 9 ? '9+' : String(unread);
          } else {
            badge.style.display = 'none';
          }
          const items = Array.isArray(data.items) ? data.items : [];
          if (items.length === 0) {
            list.innerHTML = '<div class="p-3 text-muted small">No messages yet</div>';
            return;
          }
          list.innerHTML = items.map(t => {
            const unreadBadge = t.unread > 0 ? `<span class="badge bg-warning text-dark ms-2">${t.unread}</span>` : '';
            const last = escapeHtml(t.last_message || '');
            const time = t.last_time ? formatTime(t.last_time) : '';
            const role = (t.role||'buyer').toLowerCase();
            const av = `https://ui-avatars.com/api/?name=${encodeURIComponent(t.other_name||'U')}&background=3f51b5&color=fff`;
            return `
              <button type="button" class="dropdown-item text-wrap openChatBtn msg-item" 
                data-role="${role}"
                data-product-id="${t.product_id}"
                data-seller-id="${t.seller_id}"
                data-buyer-id="${t.buyer_id}"
                data-product-title="${escapeHtml(t.product_title||'')}"
              >
                <div class="d-flex align-items-start gap-2">
                  <img src="${av}" alt="" class="avatar-xs rounded-circle mt-1" width="28" height="28">
                  <div class="flex-grow-1 min-w-0">
                    <div class="d-flex align-items-center justify-content-between gap-2">
                      <div class="fw-semibold text-truncate">${escapeHtml(t.other_name||'User')} • <span class="text-muted">${escapeHtml(t.product_title||'')}</span> ${unreadBadge}</div>
                      <small class="text-muted flex-shrink-0">${time}</small>
                    </div>
                    <div class="small text-muted text-truncate">${last}</div>
                  </div>
                </div>
              </button>`;
          }).join('');
        } catch(e) {}
      }

      // Expose globally for chat.js to trigger real-time updates
      window.refreshMessages = refreshMessages;
      refreshMessages();
      setInterval(refreshMessages, 20000);
      // Also refresh when the dropdown is opened to ensure latest data is shown
      const ddTrigger = document.getElementById('messagesDropdown');
      if (ddTrigger) {
        // Just refresh when opening the dropdown; no auto-open of the latest chat
        ddTrigger.addEventListener('show.bs.dropdown', refreshMessages);
        // Default click should toggle dropdown only
      }

      // Toggle unread-only filter
      if (onlyUnreadToggle) {
        onlyUnreadToggle.addEventListener('change', function(){
          refreshMessages();
        });
      }

      // Improve unread updating: when a message item is clicked, hide dropdown and refresh badge shortly after
      const messagesListEl = document.getElementById('messagesList');
      if (messagesListEl) {
        messagesListEl.addEventListener('click', function(e){
          const item = e.target.closest('.msg-item');
          if (!item) return;
          try {
            const dd = bootstrap.Dropdown.getOrCreateInstance(document.getElementById('messagesDropdown'));
            dd.hide();
          } catch(_){ }
          setTimeout(()=>{ if (typeof window.refreshMessages === 'function') window.refreshMessages(); }, 1200);
        });
      }
    })();

    // Profile modal: fetch and render user details for buyer/seller
    (function(){
      const avatarBtn = document.getElementById('profileAvatarBtn');
      const modalEl = document.getElementById('profileModal');
      const contentEl = document.getElementById('profileContent');
      if (!avatarBtn || !modalEl || !contentEl) return;

      function roleBadge(role){
        const map = { buyer: 'primary', seller: 'success', admin: 'warning' };
        const cls = map[(role||'').toLowerCase()] || 'secondary';
        return `<span class="badge bg-${cls} text-uppercase">${(role||'user')}</span>`;
      }

      function fmtINR(v){
        try { return new Intl.NumberFormat('en-IN', { style:'currency', currency:'INR', maximumFractionDigits: 2 }).format(v||0); }
        catch(_) { return '₹' + (v||0); }
      }

      function niceDate(iso){
        const d = iso ? new Date(iso) : null;
        return d && !isNaN(d) ? d.toLocaleDateString() : '';
      }

      function render(data){
        const isBuyer = (data.role||'').toLowerCase()==='buyer';
        const isSeller = (data.role||'').toLowerCase()==='seller';
        const commonTop = `
          <div class="d-flex align-items-center gap-3 mb-3">
            <img src="${data.avatar}" class="rounded-circle shadow" style="width:72px;height:72px" alt="avatar">
            <div>
              <div class="h5 mb-1 d-flex align-items-center gap-2">${data.name} ${roleBadge(data.role)}</div>
              <div class="d-flex flex-wrap gap-3 text-muted small">
                <span><i class=\"far fa-envelope me-1\"></i>${data.email}</span>
                ${data.phone ? `<span><i class=\"fas fa-phone me-1\"></i>${data.phone}</span>` : ''}
                ${data.address ? `<span><i class=\"fas fa-map-marker-alt me-1\"></i>${data.address}</span>` : ''}
              </div>
            </div>
          </div>
          <div class="profile-actions d-flex gap-2 mb-3">
            <a href="edit_profile.php" class="btn btn-outline-gold btn-sm" aria-label="Edit your profile"><i class="fas fa-user-edit me-1"></i>Edit Profile</a>
            <a href="dashboard.php" class="btn btn-primary btn-sm" aria-label="Go to dashboard"><i class="fas fa-tachometer-alt me-1"></i>Go to Dashboard</a>
          </div>
          <div class="row g-3 mb-3">
            <div class="col-6 col-md-3">
              <div class="p-3 border rounded text-center h-100 bg-dark-subtle profile-stat">
                <div class="text-muted small">Joined</div>
                <div class="fw-semibold">${niceDate(data.created_at)}</div>
              </div>
            </div>
          </div>`;

        let roleSection = '';
        if (isBuyer) {
          const s = data.stats || {};
          roleSection = `
            <h6 class="mb-2"><i class=\"fas fa-shopping-bag me-2 text-primary\"></i>Buyer Overview</h6>
            <div class="row g-3">
              <div class="col-6 col-md-3"><div class="p-3 border rounded text-center h-100 profile-stat"><div class="text-muted small">Purchases</div><div class="fw-bold fs-5 anim-count" data-count="${s.total_purchases||0}">0</div></div></div>
              <div class="col-6 col-md-3"><div class="p-3 border rounded text-center h-100 profile-stat"><div class="text-muted small">Spent</div><div class="fw-bold fs-6">${fmtINR(s.total_spent||0)}</div></div></div>
              <div class="col-6 col-md-3"><div class="p-3 border rounded text-center h-100 profile-stat"><div class="text-muted small">Pending</div><div class="fw-bold fs-5 anim-count" data-count="${s.pending_deliveries||0}">0</div></div></div>
              <div class="col-6 col-md-3"><div class="p-3 border rounded text-center h-100 profile-stat"><div class="text-muted small">Confirmed</div><div class="fw-bold fs-5 anim-count" data-count="${s.confirmed_deliveries||0}">0</div></div></div>
              
              <div class="col-6 col-md-3"><div class="p-3 border rounded text-center h-100 profile-stat"><div class="text-muted small">Cart</div><div class="fw-bold fs-5 anim-count" data-count="${s.cart_count||0}">0</div></div></div>
            </div>`;
        } else if (isSeller) {
          const s = data.stats || {};
          roleSection = `
            <h6 class="mb-2"><i class=\"fas fa-store me-2 text-success\"></i>Seller Overview</h6>
            <div class="row g-3">
              <div class="col-6 col-md-3"><div class="p-3 border rounded text-center h-100 profile-stat"><div class="text-muted small">Products</div><div class="fw-bold fs-5 anim-count" data-count="${s.total_products||0}">0</div></div></div>
              <div class="col-6 col-md-3"><div class="p-3 border rounded text-center h-100 profile-stat"><div class="text-muted small">Approved</div><div class="fw-bold fs-5 anim-count" data-count="${s.approved_products||0}">0</div></div></div>
              <div class="col-6 col-md-3"><div class="p-3 border rounded text-center h-100 profile-stat"><div class="text-muted small">Pending</div><div class="fw-bold fs-5 anim-count" data-count="${s.pending_products||0}">0</div></div></div>
              <div class="col-6 col-md-3"><div class="p-3 border rounded text-center h-100 profile-stat"><div class="text-muted small">Sales</div><div class="fw-bold fs-5 anim-count" data-count="${s.total_sales||0}">0</div></div></div>
              <div class="col-6 col-md-3"><div class="p-3 border rounded text-center h-100 profile-stat"><div class="text-muted small">Earnings</div><div class="fw-bold fs-6">${fmtINR(s.total_earnings||0)}</div></div></div>
              <div class="col-6 col-md-3"><div class="p-3 border rounded text-center h-100 profile-stat"><div class="text-muted small">Released</div><div class="fw-bold fs-6">${fmtINR(s.released_earnings||0)}</div></div></div>
              <div class="col-6 col-md-3"><div class="p-3 border rounded text-center h-100 profile-stat"><div class="text-muted small">Pending ₹</div><div class="fw-bold fs-6">${fmtINR(s.pending_earnings||0)}</div></div></div>
              <div class="col-12">
                <div class="p-3 border rounded d-flex align-items-center justify-content-between profile-stat">
                  <div class="text-muted small">Seller Rating</div>
                  <div>
                    ${(() => {
                      const ar = Number(s.seller_avg_rating||0);
                      const count = Number(s.seller_reviews_count||0);
                      let html = '';
                      if (count>0) {
                        const full = Math.floor(ar);
                        const half = (ar - full) >= 0.5 ? 1 : 0;
                        const empty = 5 - full - half;
                        for (let i=0;i<full;i++) html += '<i class="fas fa-star text-warning"></i>';
                        if (half) html += '<i class="fas fa-star-half-alt text-warning"></i>';
                        for (let i=0;i<empty;i++) html += '<i class="far fa-star text-warning"></i>';
                        html += ` <small class="text-muted">(${ar.toFixed(1)} · ${count})</small>`;
                      } else {
                        html = '<span class="text-muted">No reviews yet</span>';
                      }
                      return html;
                    })()}
                  </div>
                </div>
              </div>
            </div>`;
        }

        contentEl.innerHTML = commonTop + roleSection;
        // Animate any counters
        try {
          const els = contentEl.querySelectorAll('.anim-count');
          els.forEach(el => {
            const target = parseInt(el.getAttribute('data-count')||'0',10);
            const duration = 700; const start = performance.now();
            const step = (now)=>{
              const p = Math.min(1, (now - start) / duration);
              el.textContent = Math.floor((1 - Math.pow(1 - p, 3)) * target).toString();
              if (p < 1) requestAnimationFrame(step);
            };
            requestAnimationFrame(step);
          });
        } catch(_){ }
      }

      async function openProfile(){
        try {
          contentEl.innerHTML = '<div class="text-center py-4"><div class="spinner-border" role="status"></div><div class="mt-2 small text-muted">Loading profile…</div></div>';
          const res = await fetch('api/profile_summary.php', { credentials:'same-origin' });
          const data = await res.json();
          if (!res.ok) throw new Error(data.error||'Failed');
          render(data);
        } catch (e) {
          contentEl.innerHTML = '<div class="alert alert-danger">Unable to load profile.</div>';
        }
        const m = bootstrap.Modal.getOrCreateInstance(modalEl);
        m.show();
      }

      avatarBtn.addEventListener('click', function(e){ e.preventDefault(); openProfile(); });
    })();

});

// Utility functions
function formatPrice(price) {
    return '₹' + parseFloat(price).toLocaleString('en-IN', {
        maximumFractionDigits: 2
    });
}

function showAlert(message, type = 'info') {
    const alertDiv = document.createElement('div');
    alertDiv.className = `alert alert-${type} alert-dismissible fade show`;
    alertDiv.innerHTML = `
        ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;
    
    const container = document.querySelector('.container');
    if (container) {
        container.insertBefore(alertDiv, container.firstChild);
    }
}

function confirmAction(message, callback) {
    if (confirm(message)) {
        callback();
    }
}

// AJAX helper function
function makeRequest(url, method = 'GET', data = null) {
    return fetch(url, {
        method: method,
        headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: data ? JSON.stringify(data) : null
    })
    .then(response => response.json())
    .catch(error => {
        console.error('Request failed:', error);
        showAlert('An error occurred. Please try again.', 'danger');
    });
}

// Handle payment confirmation
const handlePaymentConfirmation = async (button) => {
    const transactionId = button.dataset.id;
    if (!transactionId) return;

    // Disable button and show loading state
    const originalText = button.innerHTML;
    button.disabled = true;
    button.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Processing...';

    try {
        const response = await fetch('confirm_payment.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `transaction_id=${encodeURIComponent(transactionId)}`,
            credentials: 'same-origin'
        });

        const result = await response.json();
        
        if (result.success) {
            // Show success message
            showAlert(result.message, 'success');
            
            // Update UI
            const row = button.closest('tr');
            if (row) {
                // Update status cell
                const statusCell = row.querySelector('td:nth-child(4)');
                if (statusCell) {
                    statusCell.innerHTML = '<span class="badge bg-info">Processing</span>';
                }
                
                // Update action cell
                const actionCell = row.querySelector('td:last-child');
                if (actionCell) {
                    actionCell.innerHTML = `
                        <span class="badge bg-info">
                            <i class="fas fa-check"></i> Confirmed
                        </span>
                        <div class="mt-1">
                            <small class="text-muted">${result.requires_admin_release ? 'Awaiting admin release' : 'Payment released'}</small>
                        </div>
                    `;
                }
            }
        } else {
            throw new Error(result.error || 'Failed to confirm payment');
        }
    } catch (error) {
        console.error('Payment confirmation failed:', error);
        showAlert(error.message || 'Failed to confirm payment. Please try again.', 'danger');
        // Re-enable button on error
        button.disabled = false;
        button.innerHTML = originalText;
    }
};

// Delegated handler for Cart buttons and payment confirmation buttons
document.addEventListener('click', async function(e) {
    // Handle payment confirmation button
    const confirmPayBtn = e.target.closest('.confirm-payment');
    if (confirmPayBtn) {
        e.preventDefault();
        if (confirm('Are you sure you want to confirm this payment? This action cannot be undone.')) {
            await handlePaymentConfirmation(confirmPayBtn);
        }
        return;
    }

    // Handle cart buttons
    const cBtn = e.target.closest('.addToCartBtn');
    if (cBtn) {
    e.preventDefault();
    try {
      const pid = cBtn.getAttribute('data-product-id');
      const form = new FormData();
      form.append('product_id', pid);
      const res = await fetch('api/cart_add.php', { method: 'POST', body: form, credentials: 'same-origin' });
      const data = await res.json();
      if (!res.ok) { alert(data.error || 'Add to cart failed'); return; }
      if (data.status === 'added') {
        cBtn.innerHTML = '<i class="fas fa-check"></i>';
        showAlert('Added to cart.', 'success');
      } else if (data.status === 'exists') {
        showAlert('Already in cart.', 'info');
      }
    } catch(err){ alert('Cart error'); }
  }
  const rBtn = e.target.closest('.cartRemoveBtn');
  if (rBtn) {
    e.preventDefault();
    try {
      const pid = rBtn.getAttribute('data-product-id');
      const form = new FormData();
      form.append('product_id', pid);
      const res = await fetch('api/cart_remove.php', { method: 'POST', body: form, credentials: 'same-origin' });
      const data = await res.json();
      if (!res.ok) { alert(data.error || 'Remove failed'); return; }
      showAlert('Removed from cart.', 'info');
      // Soft refresh for now
      setTimeout(()=> window.location.reload(), 600);
    } catch(err){ alert('Remove error'); }
  }
});
